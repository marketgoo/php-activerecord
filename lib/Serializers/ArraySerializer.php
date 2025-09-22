<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord\Serializers;

use ActiveRecord\Utils;
use ActiveRecord\Serialization;

/**
 * Array serializer.
 *
 * @package ActiveRecord
 *
 */
class ArraySerializer extends Serialization
{
    public static $include_root = false;

    public function to_s()
    {
        return self::$include_root
            ? [strtolower(Utils::denamespace($this->model)) => $this->to_a()]
            : $this->to_a();
    }
}
