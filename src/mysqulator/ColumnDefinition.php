<?php
//======================================================================================================================
declare(strict_types=1);

//======================================================================================================================
namespace sql;

//======================================================================================================================

/**
 *  A POD class containing all relevant data representing a mysql table column.
 *  This class is meant to be internal but can be used for anything it's needed for.
 */
class ColumnDefinition
{
    //==================================================================================================================
    /**
     *  @param string   $name            The name of the column
     *  @param bool     $nullable        Whether the column accepts null values
     *  @param bool     $unique          Whether the column accepts only unique values across the entire table
     *  @param int      $precision       Represents several aspects of the data type in this column
     *                                   <table>
     *                                       <tr>
     *                                           <th>Types</th>
     *                                           <th>Purpose</th>
     *                                       </tr>
     *                                       <tr>
     *                                           <td>FLOAT, DOUBLE, DOUBLE PRECISION, DECIMAL, DEC</td>
     *                                           <td>
     *                                               Specifies the number of digits after the decimal point.<br>
     *                                               This is non-standard syntax, so best to leave this default for these
     *                                               types except for FLOAT, but with size left default.
     *                                           </td>
     *                                       </tr>
     *                                       <tr>
     *                                           <td>FLOAT</td>
     *                                           <td>
     *                                               If size is not given (or -1), this will affect the precision of the
     *                                               FLOAT type, respectively, decides whether the type will become FLOAT
     *                                               or DOUBLE. A value between 0 and 24 will make this a FLOAT and a
     *                                               value from 25 to 53 a DOUBLE.
     *                                           </td>
     *                                       </tr>
     *                                       <tr>
     *                                           <td>DATETIME, TIMESTAMP, TIME</td>
     *                                           <td>
     *                                               A value between 0 and 6 specifying the fractional second precision.
     *                                           </td>
     *                                       </tr>
     *                                   </table>
     *  @param int      $size            Represents the size of the data type in this column
     *                                   If this is specified together with one of FLOAT, DOUBLE, DOUBLE PRECISION,
     *                                   DECIMAL or DEC and the precision parameter,
     *                                   this will be the amount of total digits,
     *                                   otherwise this has no effect on any of these.
     *  @param string   $type            The type to be used for the column
     *  @param string[] $enumSetValues   When ENUM or SET is used, this will represent the values they can hold
     *  @param string   $default         The default value for a column, if columns are nullable this will be NULL,
     *                                   otherwise an empty string
     *  @param bool     $autoIncrement   Whether this column will automatically increment on each insertion
     *  @param bool     $primary         Whether this column is the primary key of the table
     *  @param bool     $unsigned        Whether this column's type is an unsigned integer
     *  @param bool     $zerofill        Whether zerofill should be active for this column
     *  @param bool     $updateTimestamp Whether a TIMESTAMP, DATETIME or DATE column should automatically update the
     *                                   timestamp on update
     */
    public function __construct(public string $name,
                                public bool   $nullable,
                                public bool   $unique,
                                public int    $precision,
                                public int    $size,
                                public string $type,
                                public array  $enumSetValues,
                                public string $default,
                                public bool   $autoIncrement,
                                public bool   $primary,
                                public bool   $unsigned,
                                public bool   $zerofill,
                                public bool   $updateTimestamp) {}
}
