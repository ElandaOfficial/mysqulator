<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace sql\mod;

//======================================================================================================================
use Attribute;

//======================================================================================================================
#[Attribute(Attribute::TARGET_PROPERTY)]
class AutoIncrement {}
