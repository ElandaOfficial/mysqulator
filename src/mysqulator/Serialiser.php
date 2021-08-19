<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace sql;

//======================================================================================================================
use Attribute;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use ReflectionAttribute;
use ReflectionException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use sql\mod\AutoIncrement;
use sql\mod\Column;
use sql\mod\constraint\Reference;
use sql\mod\constraint\Unique;
use sql\mod\DefaultsTo;
use sql\mod\EnumSetValues;
use sql\mod\Id;
use sql\mod\Ignore;
use sql\mod\NamingStrategy;
use sql\mod\PrimaryKey;
use sql\mod\Record;
use sql\mod\Table;
use sql\mod\Trigger;
use sql\mod\Type;
use sql\mod\Unsigned;
use sql\mod\UpdateTimestamp;
use sql\mod\Zerofill;

//======================================================================================================================
abstract class Serialiser
{
    //==================================================================================================================
    /**
     *  @throws ReflectionException
     *  @throws Exception
     */
    public static function getTableDefinition(string $class) : ?TableDefinition
    {
        $table      = null;
        $refl_class = new ReflectionClass($class);
        $attributes = self::getNamedAttributeArray($refl_class);

        if (array_key_exists(Table::class, $attributes) && !array_key_exists(Ignore::class, $attributes))
        {
            $columns      = self::getColumns($attributes, $refl_class->getProperties());
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

            $table_def = $attributes[Table::class]->newInstance();
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
    /**
     *  @param ReflectionAttribute[] $classAttributes
     *  @param ReflectionProperty[] $classProperties
     *  @return ColumnDefinition[]
     *  @throws Exception
     */
    private static function getColumns(array $classAttributes, array $classProperties) : array
    {
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
                    throw new Exception("Property '{$property->name}' is not nullable even though column is.");
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
    #[ArrayShape([Trigger::class=>'sql\mod\Trigger[]', Record::class=>'sql\mod\Record[]', Table::class=>Table::class,
                  Ignore::class=>Ignore::class, Column::class=>Column::class, Id::class=>Id::class,
                  PrimaryKey::class=>PrimaryKey::class, DefaultsTo::class=>DefaultsTo::class,
                  EnumSetValues::class=>EnumSetValues::class, Type::class=>Type::class,
                  Unsigned::class=>Unsigned::class, Zerofill::class=>Zerofill::class,
                  UpdateTimestamp::class=>UpdateTimestamp::class, AutoIncrement::class=>AutoIncrement::class,
                  NamingStrategy::class=>NamingStrategy::class,
                  Unique::class=>'sql\mod\constraint\Unique[]',
                  Reference::class=>'sql\mod\constraint\Reference[]'])]
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

    /** @throws Exception */
    private static function getNameForStrategy(string $name, int $strategy) : string
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
