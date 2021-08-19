<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace sql;

//======================================================================================================================
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use sql\mod\Trigger;

//======================================================================================================================
/**
 *  A POD class containing all relevant data representing a mysql table.
 *  This class is meant to be internal but can be used for anything it's needed for.
 */
class TableDefinition
{
    //==================================================================================================================
    /**
     *  Creates a new TableDefinition instance.
     *
     *  @param string             $name             The name of the table
     *  @param string|null        $primaryKeyColumn The primary column name
     *  @param ColumnDefinition[] $columns          An array of columnd definitions
     *  @param array              $constraints      An array of table constraints
     *  @param Trigger[]          $triggers         An array of table triggers
     *  @throws Exception
     */
    public function __construct(public string  $name,
                                public ?string $primaryKeyColumn,
                                public array   $columns,

                                #[ArrayShape(['unique'    => 'mysqulator\Constraint\Unique[]',
                                              'reference' => 'mysqulator\Constraint\Reference[]'])]
                                public array $constraints,
                                public array $triggers)
    {}
}
