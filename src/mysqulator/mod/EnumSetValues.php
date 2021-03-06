<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace elsa\sql\mod;

//======================================================================================================================
use Attribute;

//======================================================================================================================
#[Attribute(Attribute::TARGET_PROPERTY)]
class EnumSetValues
{
    //==================================================================================================================
    /** @param string[] $values */
    public function __construct(public array $values) {}
}
