<?php
//==================================================================================================================
declare(strict_types=1);

namespace SQL
{
    //==================================================================================================================
    use PDO;
    use PDOStatement;

    class RequestResult
    {
        public bool $success;
        public PDOStatement $statement;

        public function __construct(bool $success, PDOStatement $statement)
        {
            $this->success   = $success;
            $this->statement = $statement;
        }
    }

    class Gateway
    {
        //==============================================================================================================
        private ?PDO   $mysql;
        private string $lastQuery;

        //==============================================================================================================
        public function __construct(string $host, string $user, string $password, string $database, int $port)
        {
            $this->mysql = new PDO("mysql:host={$host};dbname={$database};port={$port}", $user, $password);
        }

        public function __destruct()
        {
            $this->mysql = null;
        }

        //==============================================================================================================
        public function request(string $query, array $data = array()) : RequestResult
        {
            $this->lastQuery = $query;
            $stmt            = $this->mysql->prepare($query);
            $succ            = $stmt->execute($data);
            return new RequestResult($succ, $stmt);
        }

        //==============================================================================================================
        public function createTable(string $table, array $fields, bool $ignoreDuplicates = false) : bool
        {
            $field_list = implode(",", $fields);
            $should_ignore = ($ignoreDuplicates ? "IF NOT EXISTS" : "");
            return $this->request(
                "CREATE TABLE {$should_ignore} `{$table}`
                 (
                     `id` INT NOT NULL AUTO_INCREMENT UNIQUE,
                     {$field_list},
                     PRIMARY KEY(id)
                 );")->success;
        }

        public function dropTable(string $table, bool $onlyIfExists = false) : bool
        {
            $exists = ($onlyIfExists ? 'IF EXISTS ' : '');
            return $this->request("DROP TABLE {$exists}?;", [$table])->success;
        }

        public function select(string $from, array $columns, string $additionalConstraints = "",
                               int $fetchMode = PDO::FETCH_ASSOC, ...$fetchParameters) : array
        {
            $selectors = implode(',', array_map(function($string){ return "`{$string}`"; }, $columns));
            $result    = $this->request("SELECT
                                             {$selectors}
                                         FROM
                                             `{$from}`
                                         {$additionalConstraints};");

            if ($result->success)
            {
                return $result->statement->fetchAll($fetchMode, ...$fetchParameters);
            }

            return [];
        }

        public function insert(string $table, array $columns, array $values, bool $ignoreDuplicates = false) : bool
        {
            $val_count = count($values);
            $col_count = count($columns);

            if ($col_count == 0 || $col_count > $val_count)
            {
                return false;
            }

            array_walk($columns, function(&$val, $i){ $val = '`'.$val.'`'; });
            $column_string = implode(',', $columns);
            $value_pattern = '('.substr(str_repeat('?,', $col_count), 0, -1).')';
            $value_string  = substr(str_repeat($value_pattern.',', ($val_count / $col_count)), 0, -1);

            $should_ignore = ($ignoreDuplicates ? 'IGNORE' : '');
            return $this->request("INSERT {$should_ignore} INTO `{$table}`({$column_string})
                                   VALUES
                                       {$value_string};",
                                  $values)->success;
        }

        public function update(string $table, array $columns, array $values, string $where) : bool
        {
            $val_count = count($values);
            $col_count = count($columns);

            if ($col_count == 0 || $col_count > $val_count)
            {
                return false;
            }

            $fields = "";

            foreach ($columns as $column)
            {
                $fields .= "`{$column}`=?,";
            }

            $fields = substr($fields, 0, -1);

            return $this->request("UPDATE LOW_PRIORITY IGNORE `{$table}`
                                   SET
                                       {$fields}
                                   WHERE
                                       {$where};",
                                  $values)->success;
        }

        public function delete(string $table, string $where, array $values=[])
        {
            return $this->request("DELETE
                                   FROM
                                       `{$table}`
                                   WHERE
                                       {$where};", $values)->success;
        }

        public function deleteAll(string $table)
        {
            return $this->request("DELETE FROM `{$table}`;")->success;
        }

        public function containsRecord(string $table, string $column, string $value) : bool
        {
            $result = $this->request(
                "SELECT
                    `id`
                FROM
                    `{$table}`
                WHERE
                    `{$column}`=?;", [$value]);

            if (!$result->success)
            {
                return false;
            }

            $result = $result->statement->fetch();
            return ($result !== false);
        }

        //==============================================================================================================
        public function getLastQuery() : string { return $this->lastQuery; }

        //==============================================================================================================
        public function beginTransaction() { $this->mysql->beginTransaction(); }
        public function endTransaction()   { $this->mysql->commit(); }

        //==============================================================================================================
        public function getConnection() : PDO { return $this->mysql; }
    }
}
