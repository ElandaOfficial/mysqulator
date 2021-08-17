<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace SQL
{
    //==================================================================================================================
    use Attribute;
    use JetBrains\PhpStorm\Pure;

    //==================================================================================================================
    #[Attribute(Attribute::TARGET_CLASS)]
    class Table
    {
        //==============================================================================================================
        public function __construct(public string $name = "") {}
    }

    #[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
    class Ignore {}

    #[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS)]
    class Record
    {
        //==============================================================================================================
        /**
         *  @param string[] $columns
         *  @param string[][] $values
         */
        public function __construct(public array $columns, public array $values) {}
    }

    #[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS)]
    class Trigger
    {
        //==============================================================================================================
        public const TRIGGER_TIME_BEFORE = 0;
        public const TRIGGER_TIME_AFTER  = 1;

        public const TRIGGER_EVENT_INSERT = 0;
        public const TRIGGER_EVENT_UPDATE = 1;
        public const TRIGGER_EVENT_DELETE = 2;

        //==============================================================================================================
        public function __construct(public string $name,
                                    public int    $triggerTime,
                                    public int    $triggerEvent,
                                    public string $query) {}
    }

    #[Attribute(Attribute::TARGET_PROPERTY)]
    class Column
    {
        //==============================================================================================================
        public function __construct(public string $name       = "",
                                    public bool   $nullable   = true,
                                    public bool   $unique     = false,
                                    public int    $precision  = -1,
                                    public int    $size       = -1) {}
    }

    #[Attribute(Attribute::TARGET_PROPERTY)]
    class Id extends Column
    {
        //==============================================================================================================
        #[Pure]
        public function __construct(string $name = "", public bool $autoIncrement = true)
        {
            parent::__construct($name, false, true);
        }
    }

    #[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
    class PrimaryKey
    {
        //==============================================================================================================
        public function __construct(public string $columnName = "") {}
    }

    #[Attribute(Attribute::TARGET_PROPERTY)]
    class DefaultsTo
    {
        //==============================================================================================================
        public function __construct(public string $defaultValue) {}
    }

    #[Attribute(Attribute::TARGET_PROPERTY)]
    class EnumSetValues
    {
        //==============================================================================================================
        /** @param string[] $values */
        public function __construct(public array $values) {}
    }

    #[Attribute(Attribute::TARGET_PROPERTY)]
    class Type
    {
        //==============================================================================================================
        public function __construct(public string $type) {}
    }

    #[Attribute(Attribute::TARGET_PROPERTY)]
    class Unsigned {}

    #[Attribute(Attribute::TARGET_PROPERTY)]
    class Zerofill {}

    #[Attribute(Attribute::TARGET_PROPERTY)]
    class UpdateTimestamp {}

    #[Attribute(Attribute::TARGET_PROPERTY)]
    class AutoIncrement {}

    #[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
    class NamingStrategy
    {
        //==============================================================================================================
        public const RAW                             = 0;
        public const KEBAB_CASE                      = 1;
        public const LOWER_CASE                      = 2;
        public const UPPER_CASE                      = 3;
        public const UNDERSCORE_SEPARATED_LOWER_CASE = 4;
        public const UNDERSCORE_SEPARATED_UPPER_CASE = 5;

        //==============================================================================================================
        public function __construct(public int $strategy) {}
    }
}

namespace SQL\Constraint
{
    //==================================================================================================================
    use Attribute;

    //==================================================================================================================
    #[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS)]
    class Unique
    {
        //==============================================================================================================
        /**
         *  @param string $name
         *  @param string[] $columns
         */
        public function __construct(public string $name, public array $columns) {}
    }

    #[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS)]
    class Reference
    {
        //==============================================================================================================
        public function __construct(public string $column, public string $table, public string $referenceColumn) {}
    }
}