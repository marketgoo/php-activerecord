<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Class for a table column.
 *
 * @package ActiveRecord
 */
class Column
{
    // types for $type
    const STRING    = 1;
    const INTEGER   = 2;
    const DECIMAL   = 3;
    const DATETIME  = 4;
    const DATE      = 5;
    const TIME      = 6;
    const JSON      = 7;

    /**
     * Map a type to an column type.
     * @static
     * @var array
     */
    public static $TYPE_MAPPING = [
        'datetime'  => self::DATETIME,
        'timestamp' => self::DATETIME,
        'date'      => self::DATE,
        'time'      => self::TIME,

        'json'      => self::JSON,
        'jsonb'     => self::JSON,

        'tinyint'   => self::INTEGER,
        'smallint'  => self::INTEGER,
        'mediumint' => self::INTEGER,
        'int'       => self::INTEGER,
        'bigint'    => self::INTEGER,

        'float'     => self::DECIMAL,
        'double'    => self::DECIMAL,
        'numeric'   => self::DECIMAL,
        'decimal'   => self::DECIMAL,
        'dec'       => self::DECIMAL
    ];

    /**
     * The true name of this column.
     * @var string
     */
    public $name;

    /**
     * The inflected name of this columns .. hyphens/spaces will be => _.
     * @var string
     */
    public $inflected_name;

    /**
     * The type of this column: STRING, INTEGER, ...
     * @var integer
     */
    public $type;

    /**
     * The raw database specific type.
     * @var string
     */
    public $raw_type;

    /**
     * The maximum length of this column.
     * @var int
     */
    public $length;

    /**
     * True if this column allows null.
     * @var boolean
     */
    public $nullable;

    /**
     * True if this column is a primary key.
     * @var boolean
     */
    public $pk;

    /**
     * The default value of the column.
     * @var mixed
     */
    public $default;

    /**
     * True if this column is set to auto_increment.
     * @var boolean
     */
    public $auto_increment;

    /**
     * Name of the sequence to use for this column if any.
     * @var boolean
     */
    public $sequence;

    /**
     * Cast a value to an integer type safely
     *
     * This will attempt to cast a value to an integer,
     * unless its detected that the casting will cause
     * the number to overflow or lose precision, in which
     * case the number will be returned as a string, so
     * that large integers (BIGINTS, unsigned INTS, etc)
     * can still be stored without error
     *
     * This would ideally be done with bcmath or gmp, but
     * requiring a new PHP extension for a bug-fix is a
     * little ridiculous
     *
     * @param mixed $value The value to cast
     * @return int|string type-casted value
     */
    public static function castIntegerSafely($value)
    {
        if (is_int($value)) {
            return $value;
        } elseif (is_numeric($value) && floor($value) != $value) {
            // Its just a decimal number
            return (int) $value;
        } elseif (is_string($value) && is_float($value + 0)) {
            // If adding 0 to a string causes a float conversion,
            // we have a number over PHP_INT_MAX
            return (string) $value;
        } elseif (is_float($value) && $value >= PHP_INT_MAX) {
            // If a float was passed and its greater than PHP_INT_MAX
            // (which could be wrong due to floating point precision)
            // We'll also check for equal to (>=) in case the precision
            // loss creates an overflow on casting
            return number_format($value, 0, '', '');
        }

        return (int) $value;
    }

    /**
     * Casts a value to the column's type.
     *
     * @param mixed $value The value to cast
     * @param Connection $connection The Connection this column belongs to
     * @return mixed type-casted value
     */
    public function cast($value, $connection)
    {
        if ($value === null) {
            return null;
        }

        switch ($this->type) {
            case self::STRING:
                return (string)$value;
            case self::INTEGER:
                return static::castIntegerSafely($value);
            case self::DECIMAL:
                return (float)$value;
            case self::DATETIME:
            case self::DATE:
                if (!$value) {
                    return null;
                }

                $date_class = Config::instance()->get_date_class();
                if ($value instanceof $date_class) {
                    return $value;
                }

                if ($value instanceof \DateTime) {
                    return $date_class::createFromFormat(Connection::DATETIME_TRANSLATE_FORMAT, $value->format(Connection::DATETIME_TRANSLATE_FORMAT), $value->getTimezone());
                }

                return $connection->string_to_datetime($value);
            case self::JSON:
                return static::cast_json($value);
        }
        return $value;
    }

    /**
     * Casts a column default as reported by the database.
     *
     * A JSON default that is not a document (an expression such as
     * json_object(), or a MySQL 8 literal shown in its charset-prefixed form)
     * becomes null rather than failing to load the table's metadata.
     *
     * @param mixed $value The default value reported by the adapter
     * @param Connection $connection The Connection this column belongs to
     * @return mixed type-casted default
     */
    public function cast_default($value, $connection)
    {
        if ($this->type == self::JSON && is_string($value)) {
            // SQLite reports the default as written in the DDL, quotes included
            $value = preg_replace("/^'(.*)'$/s", '$1', $value);

            try {
                return static::cast_json($value);
            } catch (\JsonException $e) {
                return null;
            }
        }

        return $this->cast($value, $connection);
    }

    /**
     * Casts a value to a {@link Json} document.
     *
     * Strings are parsed as JSON text, which keeps code that assigned
     * json_encode()'d strings to these columns working. Arrays, scalars and
     * objects (stdClass, JsonSerializable) are wrapped as they are.
     *
     * @param mixed $value The value to cast
     * @return Json
     * @throws \JsonException if $value is a string that is not valid JSON
     */
    public static function cast_json($value)
    {
        if ($value instanceof Json) {
            return $value;
        }

        if (is_string($value)) {
            return Json::decode($value);
        }

        return new Json($value);
    }

    /**
     * Sets the $type member variable.
     * @return mixed
     */
    public function map_raw_type()
    {
        if ($this->raw_type == 'integer') {
            $this->raw_type = 'int';
        }

        if (array_key_exists($this->raw_type, self::$TYPE_MAPPING)) {
            $this->type = self::$TYPE_MAPPING[$this->raw_type];
        } else {
            $this->type = self::STRING;
        }

        // Display width specification for integer data types was deprecated in MySQL 8.0.17
        // See: https://dev.mysql.com/doc/relnotes/mysql/8.0/en/news-8-0-19.html
        if (($this->type == self::INTEGER) && ($this->length == 0)) {
            $this->length = 11;
        }

        return $this->type;
    }
}
