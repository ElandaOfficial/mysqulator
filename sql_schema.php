<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace SQL
{
    //==================================================================================================================
    use ReflectionClass;
    use ReflectionException;

    //==================================================================================================================
    class DatabaseSchema
    {
        //==============================================================================================================
        /**
         *  @param DatabaseTable[] $tables
         *  @throws ReflectionException
         */
        public static function fromTables(array $tables) : DatabaseSchema
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

            return new DatabaseSchema($expressions, $hasTriggers, $hasRecords);
        }

        //==============================================================================================================
        private static function createTriggerQueries(DatabaseTable $definition) : array
        {
            $queries       = [];
            $trigger_time  = ['BEFORE', 'AFTER'];
            $trigger_event = ['INSERT', 'UPDATE', 'DELETE'];

            foreach ($definition->getTriggers() as $trigger)
            {
                $queries[$trigger->name] = "%IF_NOT_EXISTS%"
                                          ."CREATE TRIGGER `{$trigger->name}`\n"
                                          ."{$trigger_time[$trigger->triggerTime]} {$trigger_event[$trigger->triggerEvent]}"
                                          ."ON `{$definition->getName()}`\n"
                                          ."FOR EACH ROW\n"
                                          ."BEGIN\n"
                                          ."{$trigger->query}\n"
                                          ."END$$";
            }

            return $queries;
        }

        private static function createRecordQueries(DatabaseTable $definition, array $records) : array
        {
            $queries = [];

            foreach ($records as $record)
            {
                /** @var Record $record */
                $record         = $record->newInstance();
                $columns_length = count($record->columns);

                $columns_string = implode(',', array_map(function($col) { return "`{$col}`"; }, $record->columns));
                $record_list    = [];

                foreach ($record->values as $entry)
                {
                    $entry_count = count($entry);
                    $values      = [];

                    for ($i = 0; $i < $columns_length; ++$i)
                    {
                        if ($i < $entry_count)
                        {
                            if (preg_match('/{query:\s*(.*)\s*/', (string) $entry[$i], $matches))
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
                            $default  = $definition->getColumns()[$record->columns[$i]]->default;
                            $values[] = ($default == "NULL" ?: "'{$default}'");
                        }
                    }

                    $record_list[] = '('.implode(',', $values).')';
                }

                $queries[] = "INSERT %IF_NOT_EXISTS% INTO `{$definition->getName()}`\n    ({$columns_string})\n"
                            ."VALUES\n    ".implode(",\n    ", $record_list).";";
            }

            return $queries;
        }

        //==============================================================================================================
        private function __construct(public array $expressions,
                                     private bool $hasAnyTriggers,
                                     private bool $hasAnyRecords) {}

        //==============================================================================================================
        public function exportTableSchema(bool $ignoreDuplicates = false) : string
        {
            $ignore = ($ignoreDuplicates  ? 'IF NOT EXISTS' : '');
            $output = "";

            $finished_tables = [];
            $deferred_tables = [];

            foreach ($this->expressions as $name=>$expression)
            {
                if (count($expression['meta']['references']) == 0)
                {
                    $output .= str_replace('%IF_NOT_EXISTS%', $ignore, $expression['create'])."\n\n";
                    $finished_tables[] = $name;
                }
                else
                {
                    $deferred_tables[] = $name;
                }
            }

            while (count($deferred_tables) > 0)
            {
                $name = array_shift($deferred_tables);

                foreach ($this->expressions[$name]['meta']['references'] as $ref_table)
                {
                    if (!array_key_exists($ref_table, $finished_tables) && array_key_exists($ref_table, $deferred_tables))
                    {
                        $deferred_tables[] = $name;
                        $name              = null;
                        break;
                    }
                }

                if (!is_null($name))
                {
                    $output .= str_replace('%IF_NOT_EXISTS%', $ignore, $this->expressions[$name]['create'])."\n\n";
                    $finished_tables[] = $name;
                }
            }

            return $output;
        }

        public function exportTriggerSchema(bool $ignoreDuplicates = false) : string
        {
            $output = "DELIMITER $$\n\n";

            foreach ($this->expressions as $name=>$expression)
            {
                if (!array_key_exists('triggers', $expression))
                {
                    continue;
                }

                foreach ($expression['triggers'] as $trigger_name=>$trigger)
                {
                    $ignore = ($ignoreDuplicates  ? "DROP TRIGGER IF EXISTS `{$trigger_name}`$$\n\n" : '');
                    $output .= str_replace('%IF_NOT_EXISTS%', $ignore, $trigger)."\n\n";
                }
            }

            $output .= "DELIMITER ;\n\n";
            return $output;
        }

        public function exportRecordSchema(bool $ignoreDuplicates = false) : string
        {
            $ignore = ($ignoreDuplicates  ? 'IGNORE' : '');
            $output = "";

            $finished_tables = [];
            $deferred_tables = [];

            foreach ($this->expressions as $name=>$expression)
            {
                if (!array_key_exists('records', $expression))
                {
                    continue;
                }

                if (count($expression['meta']['references']) == 0)
                {
                    foreach ($expression['records'] as $record)
                    {
                        $output .= str_replace('%IF_NOT_EXISTS%', $ignore, $record) . "\n\n";
                    }

                    $finished_tables[] = $name;
                }
                else
                {
                    $deferred_tables[] = $name;
                }
            }

            while (count($deferred_tables) > 0)
            {
                $name       = array_shift($deferred_tables);
                $expression = $this->expressions[$name];

                foreach ($expression['meta']['references'] as $ref_table)
                {
                    if (!array_key_exists($ref_table, $finished_tables) && array_key_exists($ref_table, $deferred_tables))
                    {
                        $deferred_tables[] = $name;
                        $name              = null;
                        break;
                    }
                }

                if (!is_null($name))
                {
                    foreach ($expression['records'] as $record)
                    {
                        $output .= str_replace('%IF_NOT_EXISTS%', $ignore, $record) . "\n\n";
                    }

                    $finished_tables[] = $name;
                }
            }

            return $output;
        }

        //==============================================================================================================
        public function exportSchema(bool $ignoreDuplicates = false, bool $insertRecords = true) : string
        {
            $output  = "# SQL Schema Exporter\n"
                      ."# Generated: ".date('m/d/Y h:i:s a', time())."\n\n"
                      ."#===================================\n"
                      ."# Tables ===========================\n"
                      ."#===================================\n";
            $output .= $this->exportTableSchema($ignoreDuplicates);

            if ($this->hasAnyTriggers)
            {
                $output .= "#===================================\n"
                          ."# Triggers =========================\n"
                          ."#===================================\n"
                          .$this->exportTriggerSchema($ignoreDuplicates);
            }

            if ($this->hasAnyRecords && $insertRecords)
            {
                $output .= "#===================================\n"
                          ."# Records ==========================\n"
                          ."#===================================\n"
                          .$this->exportRecordSchema($ignoreDuplicates);
            }

            return $output;
        }
    }
}
