<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace sql\mod;

//======================================================================================================================
use Attribute;

//======================================================================================================================
#[Attribute(Attribute::TARGET_PROPERTY)]
class Id extends Column
{
    //==================================================================================================================
    public function __construct(string $name = "", public bool $autoIncrement = true)
    {
        parent::__construct($name, false, true);
    }
}
