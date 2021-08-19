<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace sql;

//======================================================================================================================
use PDOStatement;

//======================================================================================================================
/** The result object returned by a Gateway request. */
class RequestResult
{
    /**
     *  @param bool         $success   Whether the operation has been successful
     *  @param PDOStatement $statement The statement of the query
     */
    public function __construct(public bool $success, public PDOStatement $statement) {}
}
