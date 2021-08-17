<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace SQL
{
    //==================================================================================================================
    use Exception;
    use ReflectionException;

    //==================================================================================================================
    class Database
    {
        //==============================================================================================================
        /** @var DatabaseTable[] */
        private array $tables = [];

        //==============================================================================================================
        public function __construct(private Gateway $gateway) {}

        //==============================================================================================================

        /** @throws Exception */
        public function findTables()
        {
            $this->addTables(...get_declared_classes());
        }

        /** @throws Exception */
        public function addTables(string ...$tableClasses)
        {
            foreach ($tableClasses as $class)
            {
                $table_def = SqlSerialiser::getTableDefinition($class);

                if (!is_null($table_def))
                {
                    if (array_key_exists($table_def->name, $this->tables))
                    {
                        throw new Exception("Couldn't register table '{$table_def->name}'({$class}) "
                            ."because it was already registered before "
                            ."({$this->tables[$table_def->name]->getClass()}})");
                    }

                    $this->verifyNoCircularReferences($table_def);
                    $this->tables[$table_def->name] = new DatabaseTable($class, $table_def);
                }
            }
        }

        //==============================================================================================================
        /**
         *  @throws ReflectionException
         */
        public function generateSchema() : DatabaseSchema
        {
            return DatabaseSchema::fromTables($this->tables);
        }

        //==============================================================================================================
        public function tableExistsOnline(string $tableName) : bool
        {
            $result = $this->gateway->select('information_schema.tables', ['*'],
                                             "WHERE
                                                 table_schema='{$_ENV['CAPRICORN_DB_NAME']}'
                                                 AND table_name='{$tableName}' LIMIT 1");
            return (count($result) > 0);
        }

        //==============================================================================================================
        public function getGateway() : Gateway { return $this->gateway; }

        //==============================================================================================================
        /**
         * @throws Exception
         */
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
