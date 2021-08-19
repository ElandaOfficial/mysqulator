<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace sql\mod;

//======================================================================================================================
use Attribute;

//======================================================================================================================
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class NamingStrategy
{
    //==================================================================================================================
    public const RAW                             = 0;
    public const KEBAB_CASE                      = 1;
    public const LOWER_CASE                      = 2;
    public const UPPER_CASE                      = 3;
    public const UNDERSCORE_SEPARATED_LOWER_CASE = 4;
    public const UNDERSCORE_SEPARATED_UPPER_CASE = 5;

    //==================================================================================================================
    public function __construct(public int $strategy) {}
}
