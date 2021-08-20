<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace sql;

//======================================================================================================================
use ReflectionClass;
use ReflectionException;
use sql\mod\Record;

//======================================================================================================================
/**
 *  Represents the SQL schema of the collection of all tables that were found.
 *  This can be used to generate an SQL schema string.
 */
class Schema
{
    //==================================================================================================================
    /**
     *  Create a Schema from an array of Table objects.
     *
     *  @param Table[] $tables The array of Tables
     *  @return static A new Schema instance
     *  @throws ReflectionException
     */
    public static function fromTables(array $tables) : static
    {
        $expressions = [];
        $hasTriggers = false;
        $hasRecords  = false;

        foreach ($tables as $table)
        {
            $expression = [];

            $query                = $table->createTableQuery();
            $expression['create'] = substr_replace($query, ' %IF_NOT_EXISTS%', strlen("CREATE TABLE"), 0);

            if (count($table->getTriggers()) > 0)
            {
                $expression['triggers'] = self::createTriggerQueries($table);
                $hasTriggers = true;
            }

            $records = (new ReflectionClass($table->getClass()))->getAttributes(Record::class);

            if (count($records) > 0)
            {
                $expression['records'] = self::createRecordQueries($table, $records);
                $hasRecords = true;
            }

            $expression['meta'] = [
                'references'=>array_map(function($ref) { return $ref->table; }, $table->getForeignKeys())
            ];
            $expressions[$table->getName()] = $expression;
        }

        return new Schema($expressions, $hasTriggers, $hasRecords);
    }

    //==================================================================================================================
    private static function createTriggerQueries(Table $definition) : array
    {
        $queries       = [];
        $trigger_time  = ['BEFORE', 'AFTER'];
        $trigger_event = ['INSERT', 'UPDATE', 'DELETE'];

        foreach ($definition->getTriggers() as $trigger)
        {
            $queries[$trigger->name] = "CREATE TRIGGER {$trigger->name}\n"
                                      ."{$trigger_time[$trigger->triggerTime]} {$trigger_event[$trigger->triggerEvent]} "
                                      ."ON `{$definition->getName()}`\n"
                                      ."FOR EACH ROW\n"
                                      ."BEGIN\n"
                                      ."{$trigger->query}\n"
                                      ."END";
        }

        return $queries;
    }

    private static function createRecordQueries(Table $definition, array $records) : array
    {
        $queries = [];

        foreach ($records as $record)
        {
            /** @var Record $record_obj */
            $record_obj     = $record->newInstance();
            $columns_length = count($record_obj->columns);

            $columns_string = implode(',', array_map(function($col) { return "`{$col}`"; }, $record_obj->columns));
            $record_list    = [];

            foreach ($record_obj->values as $entry)
            {
                $entry_count = count($entry);
                $values      = [];

                for ($i = 0; $i < $columns_length; ++$i)
                {
                    if ($i < $entry_count)
                    {
                        if (preg_match('/{query:\s*(.*)\s*}/', (string) $entry[$i], $matches))
                        {
                            $values[] = "({$matches[1]})";
                        }
                        else
                        {
                            $values[] = "'{$entry[$i]}'";
                        }
                    }
                    else
                    {
                        $default  = $definition->getColumns()[$record_obj->columns[$i]]->default;
                        $values[] = ($default == "NULL" ?: "'{$default}'");
                    }
                }

                $record_list[] = '('.implode(',', $values).')';
            }

            $queries[] = "INSERT %IF_NOT_EXISTS% INTO `{$definition->getName()}`\n    ({$columns_string})\n"
                        ."VALUES\n    ".implode(",\n    ", $record_list);
        }

        return $queries;
    }

    //==================================================================================================================
    private function __construct(private array $expressions,
                                 private bool  $hasAnyTriggers,
                                 private bool  $hasAnyRecords)
    {
        $this->sortExpressions();
    }

    //==================================================================================================================
    /**
     *  Generates a schema string that creates all the tables from your project structure.
     *
     *  @param bool $ignoreDuplicates If tables should be ignored if they already exist, else the query will fail
     *  @return string The schema string
     */
    public function exportTableSchema(bool $ignoreDuplicates = false) : string
    {
        $ignore = ($ignoreDuplicates  ? 'IF NOT EXISTS' : '');
        $output = "";

        foreach ($this->expressions as $expression)
        {
            $output .= str_replace('%IF_NOT_EXISTS%', $ignore, $expression['create']).";\n\n";
        }

        return $output;
    }

    /**
     *  Generates a schema string that creates all the triggers from your project structure.
     *
     *  @param bool $ignoreDuplicates If triggers should be dropped before added so as to that no exception can occur
     *                                when adding new ones, else the query will fail
     *  @return string The schema string
     */
    public function exportTriggerSchema(bool $ignoreDuplicates = false) : string
    {
        $output = "DELIMITER $$\n\n";

        foreach ($this->expressions as $expression)
        {
            if (!array_key_exists('triggers', $expression))
            {
                continue;
            }

            foreach ($expression['triggers'] as $trigger_name=>$trigger)
            {
                $ignore = ($ignoreDuplicates  ? "DROP TRIGGER IF EXISTS {$trigger_name}$$\n\n" : '');
                $output .= str_replace('%IF_NOT_EXISTS%', $ignore, $trigger)."$$\n\n";
            }
        }

        return $output."DELIMITER ;\n\n";
    }

    /**
     *  Generates a schema string that inserts all the initial records into the created tables.
     *
     *  @param bool $ignoreDuplicates If records should be ignored if they already exist, else the query will fail
     *  @return string The schema string
     */
    public function exportRecordSchema(bool $ignoreDuplicates = false) : string
    {
        $ignore = ($ignoreDuplicates  ? 'IGNORE' : '');
        $output = "";

        foreach ($this->expressions as $name=>$expression)
        {
            if (!array_key_exists('records', $expression))
            {
                continue;
            }

            foreach ($expression['records'] as $record)
            {
                $output .= str_replace('%IF_NOT_EXISTS%', $ignore, $record) . ";\n\n";
            }
        }

        return $output;
    }

    /**
     *  Export a full schema that contains records, triggers and tables.
     *
     *  @param bool $ignoreDuplicates Whether any of the entities should be ignored if they already exist,
     *                                else the query will fail
     *  @param bool $insertRecords    Whether records should be inserted or not in the returned schema
     *  @return string The schema string
     */
    public function exportSchema(bool $ignoreDuplicates = false, bool $insertRecords = true, $pure = true) : string
    {
        $output = "";

        if (!$pure)
        {
            $output = "# mysqulator Schema Exporter\n"
                     ."# Generated: ".date('m/d/Y h:i:s a', time())."\n\n"
                     ."#===================================\n"
                     ."# Tables ===========================\n"
                     ."#===================================\n";
        }

        $output .= $this->exportTableSchema($ignoreDuplicates);

        if ($this->hasAnyTriggers)
        {
            if (!$pure)
            {
                $output .= "#===================================\n"
                          ."# Triggers =========================\n"
                          ."#===================================\n";
            }

            $output .= $this->exportTriggerSchema($ignoreDuplicates);
        }

        if ($this->hasAnyRecords && $insertRecords)
        {
            if (!$pure)
            {
                $output .= "#===================================\n"
                          ."# Records ==========================\n"
                          ."#===================================\n";
            }

            $output .= $this->exportRecordSchema($ignoreDuplicates);
        }

        return $output;
    }

    //==================================================================================================================
    /**
     *  Returns a list of CREATE TABLE queries.
     *
     *  @param bool $ignoreDuplicates Whether tables that exist should be ignored or should the query error
     *  @return string[] The array of queries
     */
    public function getTableQueries(bool $ignoreDuplicates = false) : array
    {
        $ignore = ($ignoreDuplicates  ? 'IF NOT EXISTS' : '');
        $result = [];

        foreach ($this->expressions as $name=>$expression)
        {
            $result[$name] = str_replace('%IF_NOT_EXISTS%', $ignore, $expression['create']);
        }

        return $result;
    }

    /**
     *  Returns a list of CREATE TRIGGER queries mapped to each table they belong to.
     *  @return string[][] The array of queries
     */
    public function getTriggerQueries() : array
    {
        $result = [];

        foreach ($this->expressions as $name=>$expression)
        {
            if (!array_key_exists('triggers', $expression))
            {
                continue;
            }

            $result[$name] = $expression['triggers'];
        }

        return $result;
    }

    /**
     *  Returns a list of INSERT INTO queries mapped to each table they belong to.
     *  @return string[][] The array of queries
     */
    public function getRecordQueries(bool $ignoreDuplicates = false) : array
    {
        $ignore = ($ignoreDuplicates  ? 'IGNORE' : '');
        $result = [];

        foreach ($this->expressions as $name=>$expression)
        {
            if (!array_key_exists('records', $expression))
            {
                continue;
            }

            $result[$name] = array_map(function ($record) use ($ignore)
            {
                return str_replace('%IF_NOT_EXISTS%', $ignore, $record);
            }, $expression['records']);
        }

        return $result;
    }

    //==================================================================================================================
    public function createTables(Gateway $gateway, bool $ignoreDuplicates = false) : int | false
    {
        if (!$this->hasTables())
        {
            return 0;
        }

        $count = 0;

        foreach ($this->getTableQueries($ignoreDuplicates) as $query)
        {
            if (!$gateway->request($query)->success)
            {
                return false;
            }

            ++$count;
        }

        return $count;
    }

    public function dropAllTables(Gateway $gateway)
    {
        foreach ($this->expressions as $name=>$expression)
        {
            
        }
    }

    public function createTriggers(Gateway $gateway, bool $ignoreDuplicates = false) : int | false
    {
        if (!$this->hasTriggers())
        {
            return 0;
        }

        $count = 0;

        foreach ($this->getTriggerQueries() as $queries)
        {
            foreach ($queries as $name=>$query)
            {
                if ($ignoreDuplicates)
                {
                    $gateway->request("DROP TRIGGER IF EXISTS ?", [$name]);
                }

                if (!$gateway->request($query)->success)
                {
                    return false;
                }
            }

            ++$count;
        }

        return $count;
    }

    public function insertRecords(Gateway $gateway, bool $ignoreDuplicates = false) : int | false
    {
        if (!$this->hasRecords())
        {
            return 0;
        }

        $count = 0;

        foreach ($this->getRecordQueries($ignoreDuplicates) as $queries)
        {
            foreach ($queries as $query)
            {
                if (!$gateway->request($query)->success)
                {
                    return false;
                }

                ++$count;
            }
        }

        return $count;
    }

    //==================================================================================================================
    /**
     *  Gets whether this schema has any tables.
     *  @return bool True if at least one table was found
     */
    public function hasTables() : bool { return (count($this->expressions) > 0); }

    /**
     *  Gets whether this schema has any triggers that it should create.
     *  @return bool True if at least one trigger was found
     */
    public function hasTriggers() : bool { return $this->hasAnyTriggers; }

    /**
     *  Gets whether this schema has any records that should be inserted.
     *  @return bool True if at least one record was found
     */
    public function hasRecords() : bool { return $this->hasAnyRecords;  }

    //==================================================================================================================
    private function sortExpressions()
    {
        $expressions_temp = [];
        $deferred_tables  = [];

        foreach ($this->expressions as $name=>$expression)
        {
            if (count($expression['meta']['references']) == 0)
            {
                $expressions_temp[$name] = $expression;
            }
            else
            {
                $deferred_tables[$name] = $expression;
            }
        }

        while (count($deferred_tables) > 0)
        {
            $name       = array_keys($deferred_tables)[0];
            $expression = array_shift($deferred_tables);

            foreach ($expression['meta']['references'] as $ref_table)
            {
                if (!array_key_exists($ref_table, $expressions_temp) && array_key_exists($ref_table, $deferred_tables))
                {
                    $deferred_tables[$name] = $expression;
                    $name                   = null;
                    break;
                }
            }

            if (!is_null($name))
            {
                $expressions_temp[$name] = $expression;
            }
        }

        $this->expressions = $expressions_temp;
    }
}
