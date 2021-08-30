<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace elsa\sql;

//======================================================================================================================
use DateTime;
use Exception;
use ReflectionClass;
use ReflectionProperty;

//======================================================================================================================
class Entity
{
    //==================================================================================================================
    /** @throws Exception */
    public static function fromClass(string $class) : ?static
    {
        $table_def = Serialiser::getTableDefinition($class, $column_mappings);
        return (!is_null($table_def) ? new Entity($class, $table_def, $column_mappings) : null);
    }

    //==================================================================================================================
    /** @throws Exception */
    public function __construct(private string $class, private TableDefinition $definition,
                                private array $columnMappings) {}

    //==================================================================================================================

    /** @throws Exception */
    public function toObjects(array $data) : array
    {
        $refl_class   = new ReflectionClass($this->class);
        $objects      = [];
        $object_count = count($data);

        for ($i = 0; $i < $object_count; ++$i)
        {
            $objects[] = $refl_class->newInstance();
        }

        foreach ($refl_class->getProperties() as $property)
        {
            $property->setAccessible(true);

            if (!array_key_exists($property->getName(), $this->columnMappings))
            {
                continue;
            }

            $name = $this->columnMappings[$property->getName()];

            for ($i = 0; $i < $object_count; ++$i)
            {
                $entry = $data[$i];

                if (array_key_exists($name, $entry))
                {
                    $value = self::inferTypeFromProperty($property, $this->getTableColumns()[$name], $entry[$name]);
                    $property->setValue($objects[$i], $value);
                }
            }
        }

        return $objects;
    }

    /** @throws Exception */
    public function toObject(array $data) : ?object
    {
        $objects = $this->toObjects([$data]);
        return count($objects) > 0 ? $objects[0] : null;
    }

    //==================================================================================================================
    /** @throws Exception */
    public function toArrays(array $objects) : array
    {
        $refl_class   = new ReflectionClass($this->class);
        $arrays       = [];
        $array_count = count($objects);

        foreach ($refl_class->getProperties() as $property)
        {
            $property->setAccessible(true);

            if (!array_key_exists($property->getName(), $this->columnMappings))
            {
                continue;
            }

            $name = $this->columnMappings[$property->getName()];

            for ($i = 0; $i < $array_count; ++$i)
            {
                $arrays[$i][$name] = self::inferValueFromProperty($property, $this->getTableColumns()[$name],
                                                                  $property->getValue($objects[$i]));
            }
        }

        return $arrays;
    }

    /** @throws Exception */
    public function toArray(object $object) : ?array
    {
        $arrays = $this->toArrays([$object]);
        return count($arrays) > 0 ? $arrays[0] : null;
    }

    //==================================================================================================================
    public function getClass() : string
    {
        return $this->class;
    }

    public function getTableName() : string
    {
        return $this->definition->name;
    }

    public function getTablePrimaryKey() : ?string
    {
        return $this->definition->primaryKeyColumn;
    }

    /** @return ColumnDefinition[] */
    public function getTableColumns() : array
    {
        return $this->definition->columns;
    }

    /** @return mod\constraint\Reference[] */
    public function getTableForeignKeys() : array
    {
        return $this->definition->constraints['reference'];
    }

    /** @return mod\constraint\Unique[] */
    public function getTableUniqueConstraints() : array
    {
        return $this->definition->constraints['unique'];
    }

    public function getTableTriggers() : array
    {
        return $this->definition->triggers;
    }

    public function getTableDefinition() : TableDefinition
    {
        return $this->definition;
    }

    //==================================================================================================================
    /** @throws Exception */
    private static function inferTypeFromProperty(ReflectionProperty $property, ColumnDefinition $columnDefinition,
                                                  mixed $value) : mixed
    {
        $column_type = $columnDefinition->type;

        if ($property->getType()->getName() == "DateTime")
        {
            if ($column_type == "TIMESTAMP" || $column_type == "DATETIME")
            {
                return DateTime::createFromFormat('Y-m-d H:i:s', $value);
            }
            else if ($column_type == "DATE")
            {
                return DateTime::createFromFormat('Y-m-d', $value);
            }
            else if ($column_type == "TIME")
            {
                return DateTime::createFromFormat('H:i:s', $value);
            }
            else if ($column_type == "YEAR")
            {
                return DateTime::createFromFormat('Y', $value);
            }
            else
            {
                throw new Exception("Column '{$columnDefinition->name}' is not convertible to PHP DateTime object");
            }
        }

        return $value;
    }

    /** @throws Exception */
    private static function inferValueFromProperty(ReflectionProperty $property, ColumnDefinition $columnDefinition,
                                                   mixed $value) : mixed
    {
        $column_type = $columnDefinition->type;

        if ($property->getType()->getName() == "DateTime")
        {
            /** @var DateTime $date_time */
            $date_time = $value;

            if ($column_type == "TIMESTAMP" || $column_type == "DATETIME")
            {
                return $date_time->format('Y-m-d H:i:s');
            }
            else if ($column_type == "DATE")
            {
                return $date_time->format('Y-m-d');
            }
            else if ($column_type == "TIME")
            {
                return $date_time->format('H:i:s');
            }
            else if ($column_type == "YEAR")
            {
                return $date_time->format('Y');
            }
            else
            {
                throw new Exception("Column '{$columnDefinition->name}' is not convertible to PHP DateTime object");
            }
        }

        return $value;
    }
}
