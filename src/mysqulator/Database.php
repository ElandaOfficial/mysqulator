<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace sql
{
    //==================================================================================================================
    use Exception;
    use ReflectionException;

    //==================================================================================================================
    /** This class provides all sorts of functionality to interact with a MySql database. */
    class Database
    {
        //==============================================================================================================
        /**
         *  Implicitly create a Gateway instance and return it as a Database object.
         *
         *  @param string $host     The mysql hostname
         *  @param string $user     The mysql username
         *  @param string $password The mysql password
         *  @param string $database The mysql database name
         *  @param int    $port     [Optional] If specified, the port of the database, otherwise the default 3306 will be used
         *  @return static A new Database instance
         */
        public static function fromDetails(string $host, string $user, string $password, string $database,
                                           int $port = 3306) : static
        {
            return new Database(new Gateway($host, $user, $password, $database, $port));
        }

        //==============================================================================================================
        /** @var Table[] */
        private array $tables = [];

        //==============================================================================================================
        /**
         *  @param Gateway $gateway Constructs a new Database instance from a given MySql gateway
         */
        public function __construct(private Gateway $gateway) {}

        //==============================================================================================================
        /**
         *  Searches in all loaded classes for tables and adds valid ones to the table-list.
         *  @throws Exception
         */
        public function findTables()
        {
            $this->addTables(...get_declared_classes());
        }

        /**
         *  Tries to add the given tables to the table-list if possible.
         *
         *  @param string ...$tableClasses A list of table classes
         *  @throws Exception
         */
        public function addTables(string ...$tableClasses)
        {
            foreach ($tableClasses as $class)
            {
                $table_def = Serialiser::getTableDefinition($class);

                if (!is_null($table_def))
                {
                    if (array_key_exists($table_def->name, $this->tables))
                    {
                        throw new Exception("Couldn't register table '{$table_def->name}'({$class}) "
                            ."because it was already registered before "
                            ."({$this->tables[$table_def->name]->getClass()}})");
                    }

                    $this->verifyNoCircularReferences($table_def);
                    $this->tables[$table_def->name] = new Table($class, $table_def);
                }
            }
        }

        //==============================================================================================================
        /**
         *  Generates a new database schema instance.
         *  @return Schema A new Schema instance
         *  @throws ReflectionException
         */
        public function generateSchema() : Schema
        {
            return Schema::fromTables($this->tables);
        }

        /**
         *  Generates a new database schema internally and tries execute it.
         *  @throws Exception
         */
        public function makeAndApplySchema() : bool
        {
            $schema = $this->generateSchema();
            $this->gateway->beginTransaction();

            if ($schema->hasTables())
            {
                $this->gateway->request($schema->exportTableSchema(true));
            }

            if ($schema->hasTriggers())
            {
                $this->gateway->getConnection()->exec($schema->exportTriggerSchema(true));
            }

            if ($schema->hasRecords())
            {
                $this->gateway->request($schema->exportRecordSchema(true));
            }

            $this->gateway->endTransaction();

            return true;
        }

        //==============================================================================================================
        /**
         *  Tries to find a table in the table-list by its class name
         *
         *  @param string $class The class to find
         *  @return Table|null The found table instance for that class or null if none was found
         */
        public function findTableByClass(string $class) : ?Table
        {
            foreach ($this->tables as $table)
            {
                if ($table->getClass() == $class)
                {
                    return $table;
                }
            }

            return null;
        }

        //==============================================================================================================
        /**
         *  Reads a table on a database and returns an array of records.
         *
         *  @param string $class                 The table class of the corresponding table to read
         *  @param string $additionalConstraints Additional SQL query constraints for the select query
         *  @return array|null An array of records or null if no such table was found
         */
        public function readTableForClass(string $class, string $additionalConstraints = '') : ?array
        {
            $table = $this->findTableByClass($class);

            if (!is_null($table))
            {
                $stmt = $this->gateway->request("SELECT * FROM `{$table->getName()}` {$additionalConstraints};")
                             ->statement;
                return $stmt->fetchAll();
            }

            return null;
        }

        //==============================================================================================================
        /**
         *  Checks whether a table exists in a database or not.
         *
         *  @param string $class The table class of the corresponding table to check
         *  @return bool True if the table is in the database, otherwise false
         */
        public function tableExists(string $class) : bool
        {
            $table  = $this->findTableByClass($class);
            $result = [];

            if (!is_null($table))
            {
                $result = $this->gateway->select('information_schema.tables', ['*'],
                                                 "WHERE
                                                     table_schema='{$_ENV['CAPRICORN_DB_NAME']}'
                                                     AND table_name='{$table->getName()}' LIMIT 1");
            }

            return (count($result) > 0);
        }

        //==============================================================================================================
        /**
         *  Gets the PDO instance.
         *  @return Gateway The PDO instance
         */
        public function getGateway() : Gateway { return $this->gateway; }

        //==============================================================================================================
        /** @throws Exception */
        private function verifyNoCircularReferences(TableDefinition $table)
        {
            foreach ($table->constraints['reference'] as $reference)
            {
                if (array_key_exists($reference->table, $this->tables))
                {
                    $referenced_table = $this->tables[$reference->table];

                    foreach ($referenced_table->getForeignKeys() as $referenced_reference)
                    {
                        if ($referenced_reference->table == $table->name)
                        {
                            throw new Exception("Table '{$table->name}' and '{$referenced_table->getName()}' "
                                                ."reference each other, circular foreign keys are not allowed");
                        }
                    }
                }
            }
        }
    }
}
