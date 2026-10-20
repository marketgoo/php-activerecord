<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

use ActiveRecord\Exceptions\ActiveRecordException;

/**
 * UUIDv7 identifiers stored as unsigned 128-bit integers.
 *
 * The ClickHouse adapter generates the primary key of new records with v7()
 * when the key column is a UInt128, and the model holds it as the decimal
 * string ClickHouse returns for that type. to_string() renders such a key in
 * the usual UUID text form and from_string() turns UUID text back into it:
 *
 * <code>
 * $event = Event::create(['name' => 'visit']);
 * $event->id;                            # '2164240782629168885377694484224845435'
 * Uuid::to_string($event->id);           # '01a0d14f-161f-709f-9d89-561ae88ca67b'
 * Event::find(Uuid::from_string($uuid)); # look a record up by its UUID text
 * </code>
 *
 * Unlike the UUID type, a UInt128 sorts numerically, so ORDER BY id follows
 * creation order. Needs neither gmp nor bcmath.
 *
 * @package ActiveRecord
 */
class Uuid
{
    /**
     * Last id generated in this process, as 16 raw bytes.
     * @var string
     */
    private static $last = '';

    /**
     * Generates a UUIDv7 and returns it as an unsigned decimal string.
     *
     * The first 48 bits are the Unix time in milliseconds and the next 12 the
     * microseconds within that millisecond (RFC 9562, section 6.2, method 3),
     * so ids from different processes order by creation time to within a few
     * microseconds. Within a process each id is greater than the previous one.
     *
     * @return string
     */
    public static function v7()
    {
        return static::bytes_to_decimal(static::v7_bytes());
    }

    /**
     * Renders a UInt128 key (decimal string or int) as UUID text.
     *
     * @param string|int $value Unsigned decimal
     * @return string Such as 01a0d14f-161f-709f-9d89-561ae88ca67b
     */
    public static function to_string($value)
    {
        $value = (string)$value;

        if (!ctype_digit($value) || strlen(ltrim($value, '0')) > 39) {
            throw new ActiveRecordException("Not an unsigned 128-bit integer: $value");
        }

        $hex = bin2hex(static::decimal_to_bytes($value));

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    /**
     * Turns UUID text into the unsigned decimal string of a UInt128 key.
     *
     * @param string $uuid With or without dashes and braces, any case
     * @return string
     */
    public static function from_string($uuid)
    {
        $hex = str_replace(['-', '{', '}'], '', (string)$uuid);

        if (strlen($hex) != 32 || !ctype_xdigit($hex)) {
            throw new ActiveRecordException("Not a UUID: $uuid");
        }

        return static::bytes_to_decimal(hex2bin($hex));
    }

    /**
     * @return string 16 raw bytes
     */
    private static function v7_bytes()
    {
        // microtime() as a string keeps the full microsecond precision
        [$fraction, $seconds] = explode(' ', microtime());
        $microseconds = (int)$seconds * 1000000 + (int)round((float)$fraction * 1000000);
        $milliseconds = intdiv($microseconds, 1000);
        $sub_millisecond = intdiv(($microseconds % 1000) * 4096, 1000);

        $random = random_bytes(8);
        $bytes = substr(pack('J', $milliseconds), 2, 6)          // unix_ts_ms
            . pack('n', 0x7000 | $sub_millisecond)               // version and rand_a
            . chr(0x80 | (ord($random[0]) & 0x3f))               // variant
            . substr($random, 1);                                // rand_b

        // Same clock tick as the previous id: keep the order by incrementing it
        if (strcmp($bytes, self::$last) <= 0) {
            $bytes = self::$last;

            for ($i = 15; $i >= 0; $i--) {
                $byte = ord($bytes[$i]) + 1;
                $bytes[$i] = chr($byte & 0xff);

                if ($byte < 256) {
                    break;
                }
            }
        }

        return self::$last = $bytes;
    }

    /**
     * 16 big-endian bytes to an unsigned decimal string.
     */
    private static function bytes_to_decimal($bytes)
    {
        $words = array_values(unpack('N4', $bytes));
        $digits = '';

        while ($words[0] || $words[1] || $words[2] || $words[3]) {
            $remainder = 0;

            // long division by 10^9, 32 bits at a time: fits in a 64-bit int
            foreach ($words as $i => $word) {
                $current = ($remainder << 32) | $word;
                $words[$i] = intdiv($current, 1000000000);
                $remainder = $current % 1000000000;
            }

            $digits = str_pad((string)$remainder, 9, '0', STR_PAD_LEFT) . $digits;
        }

        return ltrim($digits, '0') ?: '0';
    }

    /**
     * Unsigned decimal string to 16 big-endian bytes.
     */
    private static function decimal_to_bytes($decimal)
    {
        $words = [0, 0, 0, 0];
        $padded = str_pad($decimal, (int)ceil(strlen($decimal) / 9) * 9, '0', STR_PAD_LEFT);

        foreach (str_split($padded, 9) as $chunk) {
            $carry = (int)$chunk;

            for ($i = 3; $i >= 0; $i--) {
                $current = $words[$i] * 1000000000 + $carry;
                $words[$i] = $current & 0xffffffff;
                $carry = $current >> 32;
            }

            if ($carry) {
                throw new ActiveRecordException("Not an unsigned 128-bit integer: $decimal");
            }
        }

        return pack('N4', ...$words);
    }
}
