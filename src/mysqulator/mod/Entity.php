<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace elsa\sql\mod;

//======================================================================================================================
use Attribute;

//======================================================================================================================
#[Attribute(Attribute::TARGET_CLASS)]
class Entity
{
    //==================================================================================================================
    public function __construct(public string $name = "") {}
}