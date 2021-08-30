<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace elsa\sql;

//======================================================================================================================
use Attribute;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use ReflectionAttribute;
use ReflectionException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use elsa\sql\mod\AutoIncrement;
use elsa\sql\mod\Column;
use elsa\sql\mod\constraint\Reference;
use elsa\sql\mod\constraint\Unique;
use elsa\sql\mod\DefaultsTo;
use elsa\sql\mod\EnumSetValues;
use elsa\sql\mod\Id;
use elsa\sql\mod\Ignore;
use elsa\sql\mod\NamingStrategy;
use elsa\sql\mod\PrimaryKey;
use elsa\sql\mod\Record;
use elsa\sql\mod\Entity;
use elsa\sql\mod\Trigger;
use elsa\sql\mod\Type;
use elsa\sql\mod\Unsigned;
use elsa\sql\mod\UpdateTimestamp;
use elsa\sql\mod\Zerofill;

//======================================================================================================================
abstract class Serialiser
{
    //==================================================================================================================
    /**
     *  @throws ReflectionException
     *  @throws Exception
     */
    public static function getTableDefinition(string $class, array &$columnMappingsOut = null) : ?TableDefinition
    {
        $table      = null;
        $refl_class = new ReflectionClass($class);
        $attributes = self::getNamedAttributeArray($refl_class);

        if (array_key_exists(Entity::class, $attributes) && !array_key_exists(Ignore::class, $attributes))
        {
            $columns      = self::getColumns($attributes, $refl_class->getProperties(), $columnMappingsOut);
            $primary_keys = array_filter($columns, function ($column) { return $column->primary; });
            $primary_key  = null;

            if (count($primary_keys) > 1)
            {
                throw new Exception("Invalid column constraint: There is already a primary key specified, "
                                   ."multiple primary keys are not suppoerted ("
                                   .array_key_first($primary_keys).")");
            }
            else if (count($primary_keys) > 0)
            {
                $primary_key = array_key_first($primary_keys);
            }

            if (array_key_exists(PrimaryKey::class, $attributes))
            {
                if (!is_null($primary_key))
                {
                    throw new Exception("Invalid table constraint: One of the columns was already specified as a "
                                        ."primary key ({$primary_key})");
                }

                $primary_key_name = trim($attributes[PrimaryKey::class]->getArguments()[0]);

                if (!array_key_exists($primary_key_name, $columns))
                {
                    throw new Exception("Invalid table constraint: Cannot set primary key '{$primary_key_name}', "
                                        ."table has no such column");
                }

                $primary_key = $primary_key_name;
            }

            $table_def = $attributes[Entity::class]->newInstance();
            $name      = trim($table_def->name);

            if ($name == "")
            {
                $strategy = (array_key_exists(NamingStrategy::class, $attributes)
                    ? $attributes[NamingStrategy::class]->getArguments()[0]
                    : NamingStrategy::UNDERSCORE_SEPARATED_LOWER_CASE);
                $name = self::getNameForStrategy($class, $strategy);
            }

            $constraints  = ['unique'=>[], 'reference'=>[]];
            $triggers     = [];
            $column_names = array_keys($columns);

            foreach ($attributes as $key=>$value)
            {
                if ($key == Unique::class)
                {
                    foreach ($value as $att_unique)
                    {
                        /** @var Unique */
                        $unique = $att_unique->newInstance();

                        foreach ($unique->columns as $column)
                        {
                            if (!in_array($column, $column_names))
                            {
                                throw new Exception("Cannot apply unique constraint '{$unique->name}' "
                                                   ."to table '{$name}', there is no column such as '{$column}'");
                            }
                        }

                        $constraints['unique'][] = $unique;
                    }
                }
                else if ($key == Reference::class)
                {
                    foreach ($value as $att_reference)
                    {
                        /** @var Reference */
                        $reference = $att_reference->newInstance();

                        if (!in_array($reference->column, $column_names))
                        {
                            throw new Exception("Cannot apply foreign key to table '{$name}', "
                                               ."there is no column such as '{$reference->column}'");
                        }

                        $constraints['reference'][] = $reference;
                    }
                }
                else if ($key == Trigger::class)
                {
                    $triggers = array_map(function($val) { return $val->newInstance(); }, $value);
                }
            }

            $table = new TableDefinition($name, $primary_key, $columns, $constraints, $triggers);
        }

        return $table;
    }

    //==================================================================================================================
    public static function queryCreateTable(TableDefinition $tableDefinition,
                                            bool $ifNotExists = false, bool $deferForeignKeys = false) : string
    {
        $ignore = ($ifNotExists ? 'IF NOT EXISTS ' : '');
        $output = "CREATE TABLE {$ignore}`{$tableDefinition->name}`\n"
            ."(\n";

        foreach ($tableDefinition->columns as $column)
        {
            $output .= "    ".self::queryElementTableColumn($column).",\n";
        }

        foreach ($tableDefinition->constraints['unique'] as $unique_constraint)
        {
            $output .= "    ".self::queryElementUniqueConstraint($unique_constraint).",\n";
        }

        if (!$deferForeignKeys)
        {
            foreach ($tableDefinition->constraints['reference'] as $reference_constraint)
            {
                $output .= "    ".self::queryElementForeignKeyConstraint($reference_constraint).",\n";
            }
        }

        return substr($output, 0, -2)."\n)";
    }

    /**
     * @param TableDefinition $tableDefinition
     * @return string
     */
    public static function queryAlterTableAddForeignKeys(TableDefinition $tableDefinition) : string
    {
        if (!array_key_exists('reference', $tableDefinition->constraints))
        {
            return "";
        }

        $output = "ALTER TABLE `{$tableDefinition->name}`\n";

        foreach ($tableDefinition->constraints['reference'] as $foreign_key)
        {
            $output .= "ADD FOREIGN KEY(`{$foreign_key->column}`"
                ."REFERENCES `{$foreign_key->table}`(`{$foreign_key->referenceColumn}`),\n";
        }

        return substr($output, 0, -2);
    }

    public static function queryDropTable(string $tableName, bool $ifExists) : string
    {
        $ignore = ($ifExists ? 'IF EXISTS ' : '');
        return "DROP TABLE {$ignore}`{$tableName}`";
    }

    public static function queryElementUniqueConstraint(Unique $uniqueConstraint) : string
    {
        $values = array_map(function($val) { return "`{$val}`"; }, $uniqueConstraint->columns);
        return "UNIQUE `{$uniqueConstraint->name}`(".implode(',', $values).")";
    }

    public static function queryElementForeignKeyConstraint(Reference $referenceConstraint) : string
    {
        return "FOREIGN KEY(`{$referenceConstraint->column}`) " .
            "REFERENCES `{$referenceConstraint->table}`(`{$referenceConstraint->referenceColumn}`)";
    }

    public static function queryElementTableColumn(ColumnDefinition $column) : string
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

    //==================================================================================================================
    public static function querySelectFromTable(TableDefinition $tableDefinition,
                                                array  $columns               = null,
                                                string $additionalConstraints = "") : string
    {
        $columns_temp = [];

        if (is_null($columns) || count($columns) == 0)
        {
            $columns_temp[] = '*';
        }
        else
        {
            foreach ($columns as $name)
            {
                if (!array_key_exists($name, $tableDefinition->columns))
                {
                    throw new Exception("Insert query exception, no column named '{$name}' in table "
                        . "'{$tableDefinition->name}'");
                }

                $columns_temp[] = "`{$name}`";
            }
        }

        return "SELECT ".implode(',', $columns_temp)." FROM `{$tableDefinition->columns}`"
                   .(trim($additionalConstraints) != '' ? "\n" : '')
               .$additionalConstraints;
    }

    /** @throws Exception */
    public static function queryInsertIntoTable(TableDefinition $tableDefinition, array $values,
                                                bool $ifNotExists = false) : string
    {
        $ignore  = ($ifNotExists ? 'IGNORE ' : '');
        $columns = [];

        foreach (array_keys($values) as $name)
        {
            if (!array_key_exists($name, $tableDefinition->columns))
            {
                throw new Exception("Insert query exception, no column named '{$name}' in table "
                                   ."'{$tableDefinition->name}'");
            }

            $columns[] = "`{$name}`";
        }

        return "INSERT {$ignore}INTO `{$tableDefinition->name}`\n"
              ."    (".implode(',', $columns).")\n"
              ."VALUES\n"
              ."    (".substr(str_repeat("?,", count($columns)), 0, -1).")";
    }

    /** @throws Exception */
    public static function queryUpdate(TableDefinition $tableDefinition,
                                       ?array          $values                = null,
                                       ?array          $exclude               = [],
                                       string          $additionalConstraints = "") : string
    {
        $columns = [];

        foreach (array_keys($values ?? $tableDefinition->columns) as $name)
        {
            if (!array_key_exists($name, $tableDefinition->columns))
            {
                throw new Exception("Update query exception, no column named '{$name}' in table "
                                   ."'{$tableDefinition->name}'");
            }

            if (!in_array($name, $exclude))
            {
                $columns[] = "`{$name}`=?";
            }
        }

        return "UPDATE `{$tableDefinition->name}`\n"
              ."SET ".implode(",\n    ", $columns).(trim($additionalConstraints) != '' ? "\n" : '')
              .$additionalConstraints;
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

    /**
     *  @param ReflectionAttribute[] $classAttributes
     *  @param ReflectionProperty[] $classProperties
     *  @return ColumnDefinition[]
     *  @throws Exception
     */
    private static function getColumns(array $classAttributes, array $classProperties,
                                       array &$columnMappingsOut = null) : array
    {

        $columnMappingsOut = [];
        /** @var ColumnDefinition[] $result */
        $result                  = [];
        $default_naming_strategy = NamingStrategy::UNDERSCORE_SEPARATED_LOWER_CASE;

        if (array_key_exists(NamingStrategy::class, $classAttributes))
        {
            $default_naming_strategy = (int) $classAttributes[NamingStrategy::class]->getArguments()[0];
        }

        foreach ($classProperties as $property)
        {
            /** @var ColumnDefinition $column */
            $column     = null;
            $attributes = self::getNamedAttributeArray($property);
            $col_att    = ($attributes[Id::class] ?? ($attributes[Column::class] ?? null));

            if (!is_null($col_att) && !array_key_exists(Ignore::class, $attributes))
            {
                /** @var Column | Id $col_def */
                $col_def = $col_att->newInstance();
                $name    = trim($col_def->name);

                if ($name == "")
                {
                    $strategy = (array_key_exists(NamingStrategy::class, $attributes)
                        ? $attributes[NamingStrategy::class]->getArguments()[0] : $default_naming_strategy);
                    $name = self::getNameForStrategy($property->getName(), $strategy);
                }

                if (array_key_exists($name, $result))
                {
                    throw new Exception("Found duplicate column definition '{$name}'");
                }

                if (array_key_exists(Type::class, $attributes))
                {
                    $type = ($attributes[Type::class]->newInstance())->type;
                }
                else
                {
                    if ($property->getType()->getName() == "string")
                    {
                        $type = (array_key_exists(EnumSetValues::class, $attributes) ? 'ENUM' : 'VARCHAR');
                    }
                    else
                    {
                        $type = self::getTypeForProperty($property);
                    }
                }

                if ($col_def->nullable && !$property->getType()->allowsNull())
                {
                    throw new Exception("Property '{$property->getName()}' is not nullable even though column is.");
                }

                /** @var string[] $enumSet */
                $enumSet = [];
                $con_att = ($attributes[EnumSetValues::class] ?? null);

                if (!is_null($con_att))
                {
                    $enumSet = $con_att->getArguments()[0];
                }

                $default = ($col_def->nullable ? 'NULL' : '');

                if (array_key_exists(DefaultsTo::class, $attributes))
                {
                    $default = $attributes[DefaultsTo::class]->getArguments()[0];
                }

                $auto_increment = array_key_exists(AutoIncrement::class, $attributes)
                                  || (array_key_exists(Id::class, $attributes) && $col_def->autoIncrement);
                $primary = array_key_exists(PrimaryKey::class, $attributes)
                           || array_key_exists(Id::class, $attributes);

                if (!is_null($columnMappingsOut))
                {
                    $columnMappingsOut[$property->getName()] = $name;
                }

                $column = new ColumnDefinition($name, $col_def->nullable, $col_def->unique, $col_def->precision,
                                               $col_def->size, $type, $enumSet, $default, $auto_increment, $primary,
                                               array_key_exists(Unsigned::class,        $attributes),
                                               array_key_exists(Zerofill::class,        $attributes),
                                               array_key_exists(UpdateTimestamp::class, $attributes));
            }

            if (!is_null($column))
            {
                $result[$column->name] = $column;
            }
        }

        return $result;
    }

    //==================================================================================================================
    /**
     *  @param ReflectionClass | ReflectionProperty | ReflectionMethod $attributeHolder
     *  @throws ReflectionException
     */
    #[ArrayShape([Trigger::class=>'elsa\sql\mod\Trigger[]', Record::class=>'elsa\sql\mod\Record[]', Entity::class=>Entity::class,
                  Ignore::class=>Ignore::class, Column::class=>Column::class, Id::class=>Id::class,
                  PrimaryKey::class=>PrimaryKey::class, DefaultsTo::class=>DefaultsTo::class,
                  EnumSetValues::class=>EnumSetValues::class, Type::class=>Type::class,
                  Unsigned::class=>Unsigned::class, Zerofill::class=>Zerofill::class,
                  UpdateTimestamp::class=>UpdateTimestamp::class, AutoIncrement::class=>AutoIncrement::class,
                  NamingStrategy::class=>NamingStrategy::class,
                  Unique::class=>'elsa\sql\mod\constraint\Unique[]',
                  Reference::class=>'elsa\sql\mod\constraint\Reference[]'])]
    private static function getNamedAttributeArray(mixed $attributeHolder) : array
    {
        $result = [];

        foreach ($attributeHolder->getAttributes() as $attribute)
        {
            if (self::isRepeatable($attribute))
            {
                $result[$attribute->getName()][] = $attribute;
            }
            else
            {
                $result[$attribute->getName()] = $attribute;
            }
        }

        return $result;
    }

    /**
     *  @throws ReflectionException
     */
    private static function isRepeatable(ReflectionAttribute $attribute) : bool
    {
        $refl_class = new ReflectionClass($attribute->getName());

        /** @var Attribute $att */
        $att = $refl_class->getAttributes(Attribute::class)[0]->newInstance();
        return (($att->flags & Attribute::IS_REPEATABLE) == Attribute::IS_REPEATABLE);
    }

    /** @throws ReflectionException */
    public static function getNamingStrategyForTarget(string $class, ?string $property = null) : int
    {
        $refl_class                 = new ReflectionClass($class);
        $naming_strategy_attributes = $refl_class->getAttributes(NamingStrategy::class);
        $naming_strategy            = NamingStrategy::UNDERSCORE_SEPARATED_LOWER_CASE;

        if (count($naming_strategy_attributes) > 0)
        {
            $naming_strategy = (int) $naming_strategy_attributes[0]->getArguments()[0];
        }

        if (!is_null($property) && $refl_class->hasProperty($property))
        {
            $naming_strategy_attributes = $refl_class->getProperty($property)->getAttributes(NamingStrategy::class);

            if (count($naming_strategy_attributes) > 0)
            {
                $naming_strategy = (int) $naming_strategy_attributes[0]->getArguments()[0];
            }
        }

        return $naming_strategy;
    }

    /** @throws Exception */
    public static function getNameForStrategy(string $name, int $strategy) : string
    {
        switch ($strategy)
        {
            case NamingStrategy::RAW: return $name;
            case NamingStrategy::KEBAB_CASE:
                return strtolower(
                    preg_replace_callback('/([A-Z])/', function(array $matches)
                    {
                        return '-'.$matches[1];
                    }, $name)
                );

            case NamingStrategy::LOWER_CASE: return strtolower($name);
            case NamingStrategy::UPPER_CASE: return strtoupper($name);

            case NamingStrategy::UNDERSCORE_SEPARATED_LOWER_CASE:
                return strtolower(
                    preg_replace_callback('/([A-Z])/', function(array $matches)
                    {
                        return '_'.$matches[1];
                    }, $name)
                );

            case NamingStrategy::UNDERSCORE_SEPARATED_UPPER_CASE:
                return strtoupper(
                    preg_replace_callback('/([A-Z])/', function(array $matches)
                    {
                        return '_'.$matches[1];
                    }, $name)
                );
        }

        throw new Exception("Unsupported NamingStrategy: {$strategy}");
    }

    /** @throws Exception */
    private static function getTypeForProperty(ReflectionProperty $property) : string
    {
        switch (strtolower($property->getType()->getName()))
        {
            case 'int':      return 'INT';
            case 'float':    return 'FLOAT';
            case 'bool':     return 'BOOL';
            case 'datetime': return 'DATETIME';
            case 'array':    return 'SET';
        }

        throw new Exception("Unable to deduct mysqulator type from PHP type '{$property->getType()->getName()}', \
                             please qualify the property with an explicit type constraint");
    }
}
