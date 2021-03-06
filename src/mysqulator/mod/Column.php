<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace elsa\sql\mod;

//======================================================================================================================
use Attribute;

//======================================================================================================================
#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    //==================================================================================================================
    public function __construct(public string $name       = "",
                                public bool   $nullable   = true,
                                public bool   $unique     = false,
                                public int    $precision  = -1,
                                public int    $size       = -1) {}
}
