<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace elsa\sql;

//======================================================================================================================
use Exception;
use PDO;
use ReflectionException;

//======================================================================================================================
/** This class provides all sorts of functionality to interact with a MySql database. */
class Database
{
    //==================================================================================================================
    /**
     *  Implicitly create a Gateway instance and return it as a Database object.
     *
     *  @param string $host     The mysql hostname
     *  @param string $user     The mysql username
     *  @param string $password The mysql password
     *  @param string $database The mysql database name
     *  @param int    $port     [Optional] If specified, the port of the database,
     *                          otherwise the default 3306 will be used
     *  @return static A new Database instance
     */
    public static function fromDetails(string $host, string $user, string $password, string $database,
                                       int $port = 3306) : static
    {
        return new Database(new Gateway($host, $user, $password, $database, $port));
    }

    //==================================================================================================================
    /** @var Entity[] */
    private array $entities = [];

    //==================================================================================================================
    /**
     *  @param Gateway $gateway Constructs a new Database instance from a given MySql gateway
     */
    public function __construct(private Gateway $gateway) {}

    //==================================================================================================================
    /**
     *  Searches in all loaded classes for entities and adds valid ones to the entity-list.
     *  @throws Exception
     */
    public function findEntities()
    {
        $this->addEntities(...get_declared_classes());
    }

    /**
     *  Tries to add the given entities to the entity-list if possible.
     *
     *  @param string ...$entityClasses A list of entity classes
     *  @throws Exception
     */
    public function addEntities(string ...$entityClasses)
    {
        foreach ($entityClasses as $class)
        {
            $table_def = Serialiser::getTableDefinition($class, $mappings);

            if (!is_null($table_def))
            {
                if (array_key_exists($table_def->name, $this->entities))
                {
                    throw new Exception("Couldn't register table '{$table_def->name}'({$class}) "
                        ."because it was already registered before "
                        ."({$this->entities[$table_def->name]->getClass()}})");
                }

                $this->verifyNoCircularReferences($table_def);
                $this->entities[$table_def->name] = new Entity($class, $table_def, $mappings);
            }
        }
    }

    //==================================================================================================================
    /**
     *  Generates a new database schema instance.
     *  @return Schema A new Schema instance
     *  @throws ReflectionException
     */
    public function generateSchema() : Schema
    {
        return Schema::fromEntities($this->entities);
    }

    /**
     *  Generates a new database schema internally and tries to execute it.
     *  @throws Exception
     */
    public function makeAndApplySchema() : bool
    {
        $schema = $this->generateSchema();

        $schema->createTables($this->gateway, true);
        $schema->createTriggers($this->gateway, true);

        $this->gateway->beginTransaction();

        if ($schema->insertRecords ($this->gateway, true) === false)
        {
            $this->gateway->abortTransaction();
            return false;
        }

        $this->gateway->endTransaction();
        return true;
    }

    //==================================================================================================================
    /**
     *  Tries to find an entity in the entity-list by its class name
     *
     *  @param string $class The class to find
     *  @return Entity|null The found entity instance for that class or null if none was found
     */
    public function findEntityByClass(string $class) : ?Entity
    {
        foreach ($this->entities as $entity)
        {
            if ($entity->getClass() == $class)
            {
                return $entity;
            }
        }

        return null;
    }

    //==================================================================================================================

    /**
     *  Reads a table on a database and returns an array of records.
     *
     * @param string $entityClass The entity class of the corresponding table to read
     * @param string $additionalConstraints Additional SQL query constraints for the select query
     * @param array $data
     * @param bool $asArray
     * @return array | null An array of objects or null if an error occurred
     * @throws ReflectionException
     * @throws Exception
     */
    public function readTableForEntity(string $entityClass,
                                       string $additionalConstraints = '',
                                       array  $data                  = [],
                                       bool   $asArray               = false) : ?array
    {
        $entity = $this->findEntityByClass($entityClass);

        if (!is_null($entity))
        {
            $stmt = $this->gateway->request("SELECT * FROM `{$entity->getTableName()}` "
                                           ."{$additionalConstraints};", $data)->statement;
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($asArray)
            {
                return $records;
            }

            return $entity->toObjects($records);
        }

        return null;
    }

    /** @throws Exception */
    public function insertNewRecord(string $entityClass, array $values) : bool
    {
        $entity = $this->findEntityByClass($entityClass);

        if (is_null($entity))
        {
            return false;
        }

        $query = Serialiser::queryInsertIntoTable($entity->getTableDefinition(), $values, true);
        return $this->gateway->request($query, array_values($values))->success;
    }

    /** @throws Exception */
    public function updateRecord(object $entityObject, string $whereColumn) : bool
    {
        $entity = $this->findEntityByClass(get_class($entityObject));

        if (is_null($entity))
        {
            return false;
        }

        if (!array_key_exists($whereColumn, $entity->getTableColumns()))
        {
            throw new Exception("Column '{$whereColumn}' does not exist in table '{$entity->getTableName()}'");
        }

        $value_list   = array_values((array) $entityObject);
        $column_names = array_keys($entity->getTableColumns());
        $values       = [];

        for ($i = 0; $i < count($value_list); ++$i)
        {
            $name = $column_names[$i];

            if ($name != $whereColumn)
            {
                $values[] = $value_list[$i];
            }
        }

        $id_value = $value_list[array_search($whereColumn, $column_names)];
        $query    = Serialiser::queryUpdate($entity->getTableDefinition(), exclude: [$whereColumn],
                                            additionalConstraints: "WHERE `{$whereColumn}`='{$id_value}'");
        return $this->gateway->request($query, $values)->success;
    }

    //==================================================================================================================
    /**
     *  Checks whether a table exists in a database or not.
     *
     *  @param string $class The table class of the corresponding table to check
     *  @return bool True if the table is in the database, otherwise false
     */
    public function tableExists(string $class) : bool
    {
        $entity  = $this->findEntityByClass($class);
        $result = [];

        if (!is_null($entity))
        {
            $result = $this->gateway->select('information_schema.tables', ['*'],
                                             'WHERE
                                                 table_schema=?
                                                 AND table_name=?
                                              LIMIT 1',
                                             [$_ENV['CAPRICORN_DB_NAME'], $entity->getTableName()]);
        }

        return (count($result) > 0);
    }

    //==================================================================================================================
    /**
     *  Gets the PDO instance.
     *  @return Gateway The PDO instance
     */
    public function getGateway() : Gateway { return $this->gateway; }

    //==================================================================================================================
    /** @throws Exception */
    private function verifyNoCircularReferences(TableDefinition $table)
    {
        foreach ($table->constraints['reference'] as $reference)
        {
            if (array_key_exists($reference->table, $this->entities))
            {
                $referenced_table = $this->entities[$reference->table];

                foreach ($referenced_table->getTableForeignKeys() as $referenced_reference)
                {
                    if ($referenced_reference->table == $table->name)
                    {
                        throw new Exception("Entity '{$table->name}' and '{$referenced_table->getTableName()}' "
                                            ."reference each other, circular foreign keys are not allowed");
                    }
                }
            }
        }
    }
}
