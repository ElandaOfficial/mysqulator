<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace sql;

//======================================================================================================================
use ReflectionException;

//======================================================================================================================
class Table
{
    //==================================================================================================================
    /** @throws ReflectionException */
    public static function fromClass(string $class) : static
    {
        return new Table($class, Serialiser::getTableDefinition($class));
    }

    //==================================================================================================================
    public function __construct(private string $class, private TableDefinition $definition) {}

    //==================================================================================================================
    public function getClass() : string
    {
        return $this->class;
    }

    public function getName() : string
    {
        return $this->definition->name;
    }

    public function getPrimaryKey() : ?string
    {
        return $this->definition->primaryKeyColumn;
    }

    /**
     *  @return ColumnDefinition[]
     */
    public function getColumns() : array
    {
        return $this->definition->columns;
    }

    /** @return mod\constraint\Reference[] */
    public function getForeignKeys() : array
    {
        return $this->definition->constraints['reference'];
    }

    /** @return mod\constraint\Unique[] */
    public function getUniqueConstraints() : array
    {
        return $this->definition->constraints['unique'];
    }

    public function getTriggers() : array
    {
        return $this->definition->triggers;
    }

    //==================================================================================================================
    public function createTableQuery(bool $onlyIfNotExists = false, bool $deferForeignKeys = false) : string
    {
        $ignore      = ($onlyIfNotExists ? 'IF NOT EXISTS ' : '');
        $columns     = array_map('self::getColumnString', $this->definition->columns);
        $constraints = [];

        foreach ($this->getUniqueConstraints() as $unique_constraint)
        {
            $values        = array_map(function($val) { return "`{$val}`"; }, $unique_constraint->columns);
            $constraints[] = "UNIQUE `{$unique_constraint->name}`(".implode(',', $values).")";
        }

        if (!$deferForeignKeys)
        {
            foreach ($this->getForeignKeys() as $reference_constraint)
            {
                $constraints[] = "FOREIGN KEY(`{$reference_constraint->column}`) " .
                    "REFERENCES `{$reference_constraint->table}`(`{$reference_constraint->referenceColumn}`)";
            }
        }

        return "CREATE TABLE {$ignore}`{$this->definition->name}`\n"
              ."(\n"
              ."    ".implode(",\n    ", $columns).(count($constraints) > 0 ? ',' : '')
              .(count($constraints) > 0 ? "\n    " : '')
              .implode(",\n    ", $constraints)."\n"
              .");";
    }

    public function dropTableQuery(bool $onlyIfExists = false) : string
    {
        $ignore = ($onlyIfExists ? 'IF EXISTS ' : '');
        return "DROP TABLE {$ignore}\n"
              ."    `{$this->definition->name}`;";
    }

    public function truncateTableQuery() : string
    {
        return "TRUNCATE TABLE `{$this->definition->name}`";
    }

    public function alterTableForeignKeyQuery() : string
    {
        $add_instructions = [];

        foreach ($this->getForeignKeys() as $reference)
        {
            $add_instructions[] = "ADD FOREIGN KEY(`{$reference->column}`) REFERENCES "
                                 ."`{$reference->table}`(`{$reference->referenceColumn}`)";
        }

        return "ALTER TABLE `{$this->definition->name}`\n".implode(",\n", $add_instructions).';';
    }

    //==================================================================================================================
    private static function getColumnString(ColumnDefinition $column) : string
    {
        $type      = self::getTypeFromColumn($column);
        $not_null  = ($column->nullable                    ? '' : ' NOT NULL');
        $default   = (trim($column->default) == ''         ? '' : ' DEFAULT '.trim($column->default));
        $ai        = (!$column->autoIncrement              ? '' : ' AUTO_INCREMENT');
        $unique    = (!$column->unique || $column->primary ? '' : ' UNIQUE');
        $primary   = (!$column->primary                    ? '' : ' PRIMARY KEY');
        $on_update = (!$column->updateTimestamp            ? '' : ' ON UPDATE CURRENT_TIMESTAMP');

        return "`{$column->name}` {$type}{$not_null}{$default}{$ai}{$unique}{$primary}{$on_update}";
    }

    private static function getTypeFromColumn(ColumnDefinition $definition) : string
    {
        $unsigned_string = ($definition->unsigned ? ' UNSIGNED' : '');
        $zerofill_string = ($definition->zerofill ? ' ZEROFILL' : '');

        if (in_array($definition->type, ['CHAR', 'VARCHAR', 'BINARY', 'VARBINARY', 'TEXT', 'BLOB', 'BIT', 'TINYINT',
            'SMALLINT', 'MEDIUMINT', 'INT', 'INTEGER', 'BIGINT']))
        {
            if ($definition->size > -1)
            {
                return "{$definition->type}({$definition->size})".$unsigned_string.$zerofill_string;
            }
        }
        else if (in_array($definition->type, ['FLOAT', 'DOUBLE', 'DOUBLE_PRECISION', 'DECIMAL', 'DEC']))
        {
            if ($definition->size > -1 && $definition->precision > -1)
            {
                return "{$definition->type}({$definition->size},{$definition->precision})"
                    .$unsigned_string.$zerofill_string;
            }
        }
        else if (in_array($definition->type, ['DATETIME', 'TIMESTAMP', 'TIME']))
        {
            if ($definition->precision > -1)
            {
                return "{$definition->type}({$definition->precision})".$unsigned_string.$zerofill_string;
            }
        }
        else if (in_array($definition->type, ['ENUM', 'SET']))
        {
            $values = array_map(function($val) { return "'{$val}'"; }, $definition->enumSetValues);
            return "{$definition->type}(".implode(',', $values).")".$unsigned_string.$zerofill_string;
        }

        return "{$definition->type}".$unsigned_string.$zerofill_string;
    }
}
