<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace elsa\sql\mod\constraint;

//======================================================================================================================
use Attribute;

//======================================================================================================================
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS)]
class Reference
{
    //==================================================================================================================
    public function __construct(public string $column, public string $table, public string $referenceColumn) {}
}
