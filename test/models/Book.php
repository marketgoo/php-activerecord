<?php

namespace TestModels;

use ActiveRecord\Model;

class Book extends Model
{
    static $belongs_to = ['author'];
    static $has_one = [];
    static $use_custom_get_name_getter = false;

    public function upper_name()
    {
        return strtoupper($this->name);
    }

    public function name()
    {
        return strtolower($this->name);
    }

    public function get_name()
    {
        if (self::$use_custom_get_name_getter) {
            return strtoupper($this->read_attribute('name'));
        } else {
            return $this->read_attribute('name');
        }
    }

    public function get_upper_name()
    {
        return strtoupper($this->name);
    }

    public function get_lower_name()
    {
        return strtolower($this->name);
    }
}
