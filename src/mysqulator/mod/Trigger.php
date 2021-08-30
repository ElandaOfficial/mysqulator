<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace elsa\sql\mod;

//======================================================================================================================
use Attribute;

//======================================================================================================================
#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS)]
class Trigger
{
    //==================================================================================================================
    public const TRIGGER_TIME_BEFORE = 0;
    public const TRIGGER_TIME_AFTER  = 1;

    public const TRIGGER_EVENT_INSERT = 0;
    public const TRIGGER_EVENT_UPDATE = 1;
    public const TRIGGER_EVENT_DELETE = 2;

    //==================================================================================================================
    public function __construct(public string $name,
                                public int    $triggerTime,
                                public int    $triggerEvent,
                                public string $query) {}
}
