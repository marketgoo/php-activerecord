<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord\Serializers;

use ActiveRecord\Serialization;

/**
 * CSV serializer.
 *
 * @package ActiveRecord
 *
 */
class CsvSerializer extends Serialization
{
    public static $delimiter = ',';
    public static $enclosure = '"';

    public function to_s()
    {
        if (@$this->options['only_header'] == true) {
            return $this->header();
        }
        return $this->row();
    }

    private function header()
    {
        return $this->to_csv(array_keys($this->to_a()));
    }

    private function row()
    {
        return $this->to_csv($this->to_a());
    }

    private function to_csv($arr)
    {
        // a CSV cell holds text, so JSON documents go in as JSON text
        foreach ($arr as &$value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }
        }

        $outstream = fopen('php://temp', 'w');
        fputcsv($outstream, $arr, self::$delimiter, self::$enclosure, "");
        rewind($outstream);
        $buffer = trim(stream_get_contents($outstream));
        fclose($outstream);
        return $buffer;
    }
}
