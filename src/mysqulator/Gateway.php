<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace elsa\sql;

//======================================================================================================================
use Exception;
use PDO;

//======================================================================================================================
/**
 *  Represents a gateway between PHP and MySql.
 *  This is just a simple wrapper class around PDO to make some tasks easier and more readable.
 */
class Gateway
{
    //==================================================================================================================
    private ?PDO   $mysql;
    private string $lastQuery;
    private string $lastError;

    //==================================================================================================================
    /**
     *  Creates a new Gateway
     *
     *  @param string $host     The mysql hostname
     *  @param string $user     The mysql username
     *  @param string $password The mysql password
     *  @param string $database The mysql database name
     *  @param int    $port     [Optional] If specified, the port of the database, otherwise the default 330
     *                          will be used
     */
    public function __construct(string $host, string $user, string $password, string $database, int $port = 3306)
    {
        $this->mysql = new PDO("mysql:host={$host};dbname={$database};port={$port}", $user, $password);
    }

    public function __destruct()
    {
        $this->mysql = null;
    }

    //==================================================================================================================
    /**
     *  Make a request to the database connection and return the result.
     *
     *  @param string $query The query to execute
     *  @param array  $data  The binding parameters that are passed to the execution of the query
     *  @return RequestResult The result of the request
     */
    public function request(string $query, array $data = []) : RequestResult
    {
        $this->lastQuery = $query;
        $stmt            = $this->mysql->prepare($query);

        try
        {
            $succ = $stmt->execute($data);
        }
        catch (Exception $ex)
        {
            $this->lastError = $ex->getMessage();
            $succ            = false;
        }

        return new RequestResult($succ, $stmt);
    }

    //==================================================================================================================
    /**
     *  Create a new table in the database.
     *
     *  @param string $table            The name of the new table
     *  @param array  $fields           An array of column definitions (in Sql syntax)
     *  @param bool   $ignoreDuplicates Whether the creation of the table should not error on duplicate
     *  @return bool True if one or zero tables were created, false if an error occurred
     */
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

    /**
     *  Drops a table from the database.
     *
     *  @param string $table        The name of the table to drop
     *  @param bool   $onlyIfExists Drop only if it exists
     *  @return bool True one or zero tables were dropped, false if an error occurred
     */
    public function dropTable(string $table, bool $onlyIfExists = false) : bool
    {
        $exists = ($onlyIfExists ? 'IF EXISTS ' : '');
        return $this->request("DROP TABLE {$exists}?;", [$table])->success;
    }

    /**
     *  Make a SELECT query on the database.
     *
     *  @param string   $from                  The table or sub-query to select from
     *  @param string[] $columns               An array of column names to select
     *  @param string   $additionalConstraints Any Sql compliant query string that is appended to the end of the query
     *  @param int      $fetchMode             The PDO fetch mode
     *  @param mixed    ...$fetchParameters    Additional parameters the corresponding fetch mode requires
     *  @return array | null An array of results of the SELECT query in the format of the fetch mode, null if an
     *                       error occurred
     */
    public function select(string $from, array $columns, string $additionalConstraints = "", array $data = [],
                           int $fetchMode = PDO::FETCH_ASSOC, mixed ...$fetchParameters) : ?array
    {
        $selectors = implode(',', array_map(function($string){ return "`{$string}`"; }, $columns));
        $result    = $this->request("SELECT
                                         {$selectors}
                                     FROM
                                         `{$from}`
                                     {$additionalConstraints};", $data);

        if ($result->success)
        {
            return $result->statement->fetchAll($fetchMode, ...$fetchParameters);
        }

        return null;
    }

    /**
     *  An INSERT query that tries to insert given records into the given table.
     *
     *  @param string     $table            The table to insert into
     *  @param array[][]  $records          An array of records to insert into the table.<br>
     *                                      Example:
     *  <code>
     *  array(
     *      'column_name_1' => array(
     *          'record_1', 'record_2'
     *      ),
     *      'column_name_2' => array(
     *          'record_1', 'record_2'
     *      )
     *  )
     *  </code>
     *  @param bool $ignoreDuplicates Whether a unique record, if it exists should be ignored if it already existed
     *                                or should error out
     *  @return bool | int The amount of records that have been inserted, false if an error occurred
     */
    public function insert(string $table, array $records, bool $ignoreDuplicates = false) : int | false
    {
        $columns    = array_map(function($col) { return "'{$col}'"; }, array_keys($records));
        $values     = array_values($records);
        $value_list = [];

        $col_count = count($columns);
        $rec_count = 0;

        $value_pattern = "";

        foreach ($values as $records)
        {
            $count = count($records);

            if ($count > $rec_count)
            {
                $rec_count = $count;
            }

            $value_pattern .= "?,";
        }

        $value_pattern = substr($value_pattern, 0, -1);
        $value_string  = "";

        for ($i = 0; $i < $rec_count; ++$i)
        {
            foreach ($values as $record_list)
            {
                $value_list[] = ($record_list[$i] ?? 'NULL');
            }

            $value_string .= "({$value_pattern}),";
        }

        $value_string  = substr($value_string, 0, -1);
        $ignore_string = ($ignoreDuplicates ? 'IGNORE' : '');
        return self::returnResult($this->request("INSERT {$ignore_string} INTO `{$table}`
                                                      (".implode(',', $columns).")
                                                  VALUES
                                                      {$value_string};",
                                                 $value_list));
    }

    /**
     *  An update query that tries to update a record in a table.
     *
     *  @param string   $table   The table name to update the record from
     *  @param string[] $records A list of column names and the associated value that should be updated
     *  @param string   $where   A WHERE string that helps identify the record to update
     *  @return bool | int The amount of records that have been updated, false if an error occurred
     */
    public function update(string $table, array $records, string $where) : int | false
    {
        $fields = "";

        foreach (array_keys($records) as $name)
        {
            $fields .= "`{$name}`=?,";
        }

        $fields = substr($fields, 0, -1);
        return self::returnResult($this->request("UPDATE LOW_PRIORITY IGNORE `{$table}`
                                                  SET
                                                      {$fields}
                                                  WHERE
                                                      {$where};",
                                                 array_values($records)));
    }

    /**
     *  Deletes one or more records from the given table that match the WHERE query.
     *
     *  @param string $table The table to delete records from
     *  @param string $where The WHERE string selecting the columns in question
     *  @param array $values [Optional] If any bind parameters are given in the query, you can pass the values here
     *  @return int | false The amount of records that have been deleted, false if an error occurred
     */
    public function delete(string $table, string $where, array $values=[]) : int | false
    {
        return self::returnResult($this->request("DELETE
                                                  FROM
                                                      `{$table}`
                                                  WHERE
                                                      {$where};", $values));
    }

    /**
     *  Deletes all records from a given table.
     *
     *  @param string $table The table to wipe
     *  @return int | false The amount of records that have been wiped, false if an error occurred
     */
    public function deleteAll(string $table) : int | false
    {
        return self::returnResult($this->request("DELETE FROM `{$table}`;"));
    }

    /**
     *  Checks whether the table has a given record or not.
     *
     *  @param string   $table           The table to search for.
     *  @param string[] $queryParameters An array of columns and their corresponding values that should be checked for
     *  @return int | false The amount of records that have been found, false if an error occurred
     */
    public function recordExists(string $table, array $queryParameters) : int | false
    {
        $fields = "";

        foreach (array_keys($queryParameters) as $name)
        {
            $fields .= "`{$name}`=?,";
        }

        $fields = substr($fields, 0, -1);

        $result = $this->request(
            "SELECT
                *
            FROM
                `{$table}`
            WHERE
                {$fields};", array_values($queryParameters));

        if (!$result->success)
        {
            return false;
        }

        $result = $result->statement->fetchAll();
        return count($result);
    }

    //==================================================================================================================
    /**
     *  Gets the last executed query string.
     *  @return string The query string
     */
    public function getLastQuery()        : string { return $this->lastQuery; }
    public function getLastErrorMessage() : string { return $this->lastError; }

    //==================================================================================================================
    /** Tells the PDO that a batch operation is about to begin. */
    public function beginTransaction() { $this->mysql->beginTransaction(); }

    /** Tells the PDO that a batch operation failed and that it should roll back all changes. */
    public function abortTransaction() { $this->mysql->rollBack(); }

    /** Tells the PDO to stop and commit the last batch operation. */
    public function endTransaction() { $this->mysql->commit(); }

    //==================================================================================================================
    /**
     *  Gets the underlying PDO instance.
     *  @return PDO The PDO instance
     */
    public function getConnection() : PDO { return $this->mysql; }

    //==================================================================================================================
    private static function returnResult(RequestResult $result) : int | false
    {
        return ($result->success ? $result->statement->rowCount() : false);
    }
}
