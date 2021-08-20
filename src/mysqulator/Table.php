<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace sql;

//======================================================================================================================
use Exception;
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

    public function getDefinition() : TableDefinition
    {
        return $this->definition;
    }

    //==================================================================================================================
    public function createTableQuery(bool $ifNotExists = false, bool $deferForeignKeys = false) : string
    {
        return Serialiser::queryCreateTable($this->definition, $ifNotExists, $deferForeignKeys);
    }

    public function dropTableQuery(bool $ifExists = false) : string
    {
        return Serialiser::queryDropTable($this->getName(), $ifExists);
    }

    public function truncateTableQuery() : string
    {
        return "TRUNCATE TABLE `{$this->definition->name}`";
    }

    public function alterTableForeignKeyQuery() : string
    {
        return Serialiser::queryAlterTableAddForeignKeys($this->definition);
    }

    //==================================================================================================================
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
