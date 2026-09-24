<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord\Adapters\Clickhouse;

use PDO;
use IteratorAggregate;
use Generator;

/**
 * The result of a ClickHouse query, with the part of the PDOStatement API the
 * library uses: fetch(), fetchAll(), fetchColumn(), rowCount()...
 *
 * Results arrive as TabSeparatedWithNamesAndTypes. The body is kept as it came
 * and each fetch() parses one more row, so the rows of a large result are
 * never all in memory next to the models built from them. Values are cast
 * from their ClickHouse type: integers that fit in a PHP int become ints, the
 * rest (UInt64 above PHP_INT_MAX, Int128, UInt128, Decimal) stay strings.
 * DateTime values are moved from the time zone the server wrote them in to
 * PHP's, so they read as the same instant that was saved. Column names are
 * lower-cased like PDO::CASE_LOWER does for the other adapters.
 *
 * @package ActiveRecord
 */
class Statement implements IteratorAggregate
{
    const STRING = 0;
    const INTEGER = 1;
    const UINT64 = 2;
    const FLOAT = 3;
    const BOOLEAN = 4;
    const VERBATIM = 5;
    const COMPOSITE = 6;
    const DATETIME = 7;

    /**
     * Escape sequences of the TabSeparated format. strtr() is used rather than
     * stripcslashes(), which would read "\0" followed by a digit as octal.
     */
    const UNESCAPE = [
        '\\\\' => '\\', '\\t' => "\t", '\\n' => "\n", '\\r' => "\r", '\\0' => "\0",
        '\\b' => "\x08", '\\f' => "\f", '\\a' => "\x07", '\\v' => "\v", "\\'" => "'",
    ];

    private $body;
    private $offset = 0;
    private $names = [];
    private $types = [];
    private $casts = [];

    /** @var array Time zone of each DateTime column that differs from PHP's */
    private $zones = [];
    private $fetch_mode = PDO::FETCH_ASSOC;
    private $summary;

    /**
     * @param string $body Response body
     * @param string|null $format Format the server answered in
     * @param array|null $summary Decoded X-ClickHouse-Summary header
     * @param string|null $timezone Time zone of DateTime columns without one of their own
     */
    public function __construct($body, $format, $summary, $timezone = null)
    {
        $this->summary = $summary;

        if ($format !== 'TabSeparatedWithNamesAndTypes' || $body === '') {
            $this->body = '';
            return;
        }

        $this->body = $body;
        foreach ($this->next_line() as $name) {
            $this->names[] = strtolower(strtr($name, self::UNESCAPE));
        }

        foreach ($this->next_line() as $type) {
            $this->types[] = strtr($type, self::UNESCAPE);
        }

        $php_timezone = date_default_timezone_get();

        foreach ($this->types as $i => $type) {
            $this->casts[$i] = static::cast_for($type);

            if ($this->casts[$i] == self::DATETIME) {
                $zone = preg_match("/'([^']+)'\)+$/", $type, $matches) ? $matches[1] : $timezone;

                if ($zone && $zone !== $php_timezone) {
                    $this->zones[$i] = [new \DateTimeZone($zone), new \DateTimeZone($php_timezone)];
                } else {
                    // already in PHP's time zone: nothing to do
                    $this->casts[$i] = self::VERBATIM;
                }
            }
        }
    }

    public function setFetchMode($mode)
    {
        $this->fetch_mode = $mode;
        return true;
    }

    /**
     * @param int|null $mode PDO::FETCH_ASSOC, PDO::FETCH_NUM or PDO::FETCH_BOTH
     * @return array|false The next row, false when there are no more
     */
    public function fetch($mode = null)
    {
        if (($values = $this->next_line()) === false) {
            return false;
        }

        foreach ($values as $i => $value) {
            if ($value === '\N') {
                $values[$i] = null;
                continue;
            }

            switch ($this->casts[$i]) {
                case self::STRING:
                    if (strpos($value, '\\') !== false) {
                        $values[$i] = strtr($value, self::UNESCAPE);
                    }
                    break;

                case self::INTEGER:
                    $values[$i] = (int)$value;
                    break;

                case self::UINT64:
                    // compared as text: as numbers PHP would round both to the same float
                    $length = strlen($value);
                    if ($length < 19 || ($length == 19 && strcmp($value, (string)PHP_INT_MAX) <= 0)) {
                        $values[$i] = (int)$value;
                    }
                    break;

                case self::FLOAT:
                    $values[$i] = static::to_float($value);
                    break;

                case self::BOOLEAN:
                    $values[$i] = $value === 'true';
                    break;

                case self::COMPOSITE:
                    $values[$i] = static::parse_composite($value);
                    break;

                case self::DATETIME:
                    $values[$i] = static::move_to_timezone($value, ...$this->zones[$i]);
                    break;
            }
        }

        switch ($mode ?? $this->fetch_mode) {
            case PDO::FETCH_NUM:
                return $values;

            case PDO::FETCH_BOTH:
                return array_combine($this->names, $values) + $values;

            default:
                return array_combine($this->names, $values);
        }
    }

    /**
     * @param int|null $mode PDO::FETCH_ASSOC, PDO::FETCH_NUM, PDO::FETCH_BOTH or PDO::FETCH_COLUMN
     * @return array The remaining rows
     */
    public function fetchAll($mode = null)
    {
        $rows = [];

        if (($mode ?? $this->fetch_mode) == PDO::FETCH_COLUMN) {
            while (($row = $this->fetch(PDO::FETCH_NUM)) !== false) {
                $rows[] = $row[0];
            }
        } else {
            while (($row = $this->fetch($mode)) !== false) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param int $column Zero-based column index
     * @return mixed The column of the next row, false when there are no more
     */
    public function fetchColumn($column = 0)
    {
        $row = $this->fetch(PDO::FETCH_NUM);
        return $row === false ? false : $row[$column];
    }

    public function columnCount()
    {
        return count($this->names);
    }

    /**
     * Rows in the result set; for other statements the rows the server says
     * it wrote, or null when it does not know. ClickHouse reports nothing for
     * DELETE, UPDATE or an INSERT still waiting in the async insert queue.
     *
     * @return int|null
     */
    public function rowCount()
    {
        if ($this->names) {
            return max(0, substr_count($this->body, "\n") - 2);
        }

        $written = (int)($this->summary['written_rows'] ?? 0);
        return $written > 0 ? $written : null;
    }

    /**
     * @return array|null The decoded X-ClickHouse-Summary header
     */
    public function summary()
    {
        return $this->summary;
    }

    public function getIterator(): Generator
    {
        while (($row = $this->fetch()) !== false) {
            yield $row;
        }
    }

    /**
     * @return array|false Raw fields of the next line
     */
    private function next_line()
    {
        if ($this->offset >= strlen($this->body)) {
            return false;
        }

        $end = strpos($this->body, "\n", $this->offset);

        if ($end === false) {
            $end = strlen($this->body);
        }

        $line = substr($this->body, $this->offset, $end - $this->offset);
        $this->offset = $end + 1;

        return explode("\t", $line);
    }

    /**
     * @param string $type ClickHouse type as the types row spells it
     * @return int One of the class constants
     */
    private static function cast_for($type)
    {
        while (preg_match('/^(?:Nullable|LowCardinality)\((.*)\)$/', $type, $matches)) {
            $type = $matches[1];
        }

        if (preg_match('/^U?Int(8|16|32)$|^Int64$/', $type)) {
            return self::INTEGER;
        }

        if ($type === 'UInt64') {
            return self::UINT64;
        }

        if (str_starts_with($type, 'Float')) {
            return self::FLOAT;
        }

        if ($type === 'Bool') {
            return self::BOOLEAN;
        }

        if (preg_match('/^DateTime(64)?(\(|$)/', $type)) {
            return self::DATETIME;
        }

        // no escapes to undo, and too big or too precise for a PHP number
        if (preg_match('/^U?Int(128|256)$|^Decimal/', $type)) {
            return self::VERBATIM;
        }

        if (preg_match('/^(Array|Map|Tuple|Nested)\(/', $type)) {
            return self::COMPOSITE;
        }

        return self::STRING;
    }

    /**
     * Parses the text form of an Array, Map or Tuple, such as ['a','b\'c'],
     * {'k':1} or (1,'x',[NULL]), into PHP arrays. Elements are quoted strings,
     * numbers, NULL, true or false. Inside a TabSeparated field these are
     * written as they are, not escaped a second time.
     *
     * @param string $text
     * @return array
     */
    public static function parse_composite($text)
    {
        preg_match_all("/'(?:[^'\\\\]|\\\\.)*'|[\\[\\]{}(),:]|[^\\[\\]{}(),:'\\s]+/s", $text, $matches);
        $tokens = $matches[0];
        $position = 0;

        return static::parse_composite_value($tokens, $position);
    }

    private static function parse_composite_value(array $tokens, &$position)
    {
        $token = $tokens[$position++] ?? null;

        if ($token === '[' || $token === '(' || $token === '{') {
            $close = ['[' => ']', '(' => ')', '{' => '}'][$token];
            $map = $token === '{';
            $result = [];

            while (($tokens[$position] ?? $close) !== $close) {
                $item = static::parse_composite_value($tokens, $position);

                if ($map && ($tokens[$position] ?? null) === ':') {
                    $position++;
                    $result[$item] = static::parse_composite_value($tokens, $position);
                } else {
                    $result[] = $item;
                }

                if (($tokens[$position] ?? null) === ',') {
                    $position++;
                }
            }

            $position++;
            return $result;
        }

        if ($token === null || $token === 'NULL') {
            return null;
        }

        if ($token[0] === "'") {
            return strtr(substr($token, 1, -1), self::UNESCAPE);
        }

        if ($token === 'true' || $token === 'false') {
            return $token === 'true';
        }

        if (preg_match('/^-?\d+$/', $token)) {
            // beyond PHP_INT_MAX the cast saturates: keep those as strings
            $int = (int)$token;
            return (string)$int === $token ? $int : $token;
        }

        return is_numeric($token) || in_array($token, ['inf', '-inf', 'nan']) ? static::to_float($token) : $token;
    }

    /**
     * @param string $value Y-m-d H:i:s, with a fraction for DateTime64
     * @param \DateTimeZone $from
     * @param \DateTimeZone $to
     * @return string The same instant as wall-clock time in $to
     */
    private static function move_to_timezone($value, $from, $to)
    {
        $fraction = '';

        if (($dot = strpos($value, '.')) !== false) {
            $fraction = substr($value, $dot);
            $value = substr($value, 0, $dot);
        }

        $datetime = \DateTime::createFromFormat('Y-m-d H:i:s', $value, $from);

        return $datetime ? $datetime->setTimezone($to)->format('Y-m-d H:i:s') . $fraction : $value . $fraction;
    }

    private static function to_float($value)
    {
        switch ($value) {
            case 'inf':
            case '+inf':
                return INF;
            case '-inf':
                return -INF;
            case 'nan':
            case '-nan':
                return NAN;
            default:
                return (float)$value;
        }
    }
}
