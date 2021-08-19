<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace sql\mod;

//======================================================================================================================
use Attribute;

//======================================================================================================================
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class PrimaryKey
{
    //==================================================================================================================
    public function __construct(public string $columnName = "") {}
}
