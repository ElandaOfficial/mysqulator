<?php
    //==================================================================================================================
    declare(strict_types=1);

    //==================================================================================================================
    namespace SQL
    {
        //==============================================================================================================
        use JetBrains\PhpStorm\Pure;

        //==============================================================================================================
        class ValueProvider
        {
            //==========================================================================================================
            #[Pure]
            public static function dec(int $size, int $d): ValueProvider
            {
                return new ValueProvider('dec', ['size' => $size, 'd' => $d]);
            }

            #[Pure]
            public static function size(int $size): ValueProvider
            {
                return new ValueProvider('size', ['size' => $size]);
            }

            #[Pure]
            public static function args(string ...$args): ValueProvider
            {
                return new ValueProvider('args', ['args' => $args]);
            }

            #[Pure]
            public static function auto(int $p): ValueProvider
            {
                return new ValueProvider('auto', ['p' => $p]);
            }

            #[Pure]
            public static function date(int $fsp): ValueProvider
            {
                return new ValueProvider('date', ['fsp' => $fsp]);
            }

            //==========================================================================================================
            private function __construct(private string $type, private array $attributes) {}

            //==========================================================================================================
            public function getType() : string
            {
                return $this->type;
            }

            public function getAttributes() : array
            {
                return $this->attributes;
            }
        }

        class SqlType
        {
            //==========================================================================================================
            // String types
            #[Pure]
            public static function CHAR(int $size = 1): SqlType
            {
                return new SqlType('CHAR', ValueProvider::size($size));
            }

            #[Pure]
            public static function VARCHAR(int $size = 80): SqlType
            {
                return new SqlType('VARCHAR', ValueProvider::size($size));
            }

            #[Pure]
            public static function BINARY(int $size): SqlType
            {
                return new SqlType('BINARY', ValueProvider::size($size));
            }

            #[Pure]
            public static function VARBINARY(int $size): SqlType
            {
                return new SqlType('VARBINARY', ValueProvider::size($size));
            }

            #[Pure]
            public static function TEXT(int $size): SqlType
            {
                return new SqlType('TEXT', ValueProvider::size($size));
            }

            #[Pure]
            public static function BLOB(int $size): SqlType
            {
                return new SqlType('BLOB', ValueProvider::size($size));
            }

            #[Pure]
            public static function ENUM(string ...$values): SqlType
            {
                return new SqlType('ENUM', ValueProvider::args(...$values));
            }

            #[Pure]
            public static function SET(string ...$values): SqlType
            {
                return new SqlType('SET', ValueProvider::args(...$values));
            }

            #[Pure]
            public static function TINYBLOB(): SqlType
            {
                return new SqlType('TINYBLOB');
            }

            #[Pure]
            public static function TINYTEXT(): SqlType
            {
                return new SqlType('TINYTEXT');
            }

            #[Pure]
            public static function MEDIUMTEXT(): SqlType
            {
                return new SqlType('MEDIUMTEXT');
            }

            #[Pure]
            public static function MEDIUMBLOB(): SqlType
            {
                return new SqlType('MEDIUMBLOB');
            }

            #[Pure]
            public static function LONGTEXT(): SqlType
            {
                return new SqlType('LONGTEXT');
            }

            #[Pure]
            public static function LONGBLOB(): SqlType
            {
                return new SqlType('LONGBLOB');
            }

            // Numeric types
            #[Pure]
            public static function BIT(int $size): SqlType
            {
                return new SqlType('BIT', ValueProvider::size($size));
            }

            #[Pure]
            public static function TINYINT(int $size): SqlType
            {
                return new SqlType('TINYINT', ValueProvider::size($size));
            }

            #[Pure]
            public static function SMALLINT(int $size): SqlType
            {
                return new SqlType('SMALLINT', ValueProvider::size($size));
            }

            #[Pure]
            public static function MEDIUMINT(int $size): SqlType
            {
                return new SqlType('MEDIUMINT', ValueProvider::size($size));
            }

            #[Pure]
            public static function INT(int $size = 11): SqlType
            {
                return new SqlType('INT', ValueProvider::size($size));
            }

            #[Pure]
            public static function INTEGER(int $size): SqlType
            {
                return new SqlType('INTEGER', ValueProvider::size($size));
            }

            #[Pure]
            public static function BIGINT(int $size): SqlType
            {
                return new SqlType('BIGINT', ValueProvider::size($size));
            }

            #[Pure]
            public static function FLOAT(int $size, int $d): SqlType
            {
                return new SqlType('FLOAT', ValueProvider::dec($size, $d));
            }

            #[Pure]
            public static function DOUBLE(int $size, int $d): SqlType
            {
                return new SqlType('DOUBLE', ValueProvider::dec($size, $d));
            }

            #[Pure]
            public static function DOUBLE_PRECISION(int $size, int $d): SqlType
            {
                return new SqlType('DOUBLE PRECISION', ValueProvider::dec($size, $d));
            }

            #[Pure]
            public static function DECIMAL(int $size, int $d): SqlType
            {
                return new SqlType('DECIMAL', ValueProvider::dec($size, $d));
            }

            #[Pure]
            public static function DEC(int $size, int $d): SqlType
            {
                return new SqlType('DEC', ValueProvider::dec($size, $d));
            }

            #[Pure]
            public static function BOOL() : string
            {
                return "BOOL";
            }

            #[Pure]
            public static function BOOLEAN(): SqlType
            {
                return new SqlType('BOOLEAN');
            }

            // Date types
            #[Pure]
            public static function DATETIME(int $fsp): SqlType
            {
                return new SqlType('DATETIME', ValueProvider::date($fsp));
            }

            #[Pure]
            public static function TIMESTAMP(int $fsp): SqlType
            {
                return new SqlType('TIMESTAMP', ValueProvider::date($fsp));
            }

            #[Pure]
            public static function TIME(int $fsp): SqlType
            {
                return new SqlType('TIME', ValueProvider::date($fsp));
            }

            #[Pure]
            public static function DATE(): SqlType
            {
                return new SqlType('DATE');
            }

            #[Pure]
            public static function YEAR(): SqlType
            {
                return new SqlType('YEAR');
            }

            //==========================================================================================================
            private function __construct(private string $name, private ?ValueProvider $provider = null) {}

            //==========================================================================================================
            public function withLength(int $length): SqlType
            {
                $this->length = $length;
                return $this;
            }

            //==========================================================================================================
            public function getName(): string
            {
                return $this->name;
            }

            public function getValueProvider(): ?ValueProvider
            {
                return $this->provider;
            }
        }
    }