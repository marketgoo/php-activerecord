<?php

namespace TestModels;

use ActiveRecord\Model;

class Publisher extends Model
{
    public static $pk = 'publisher_id';
    public static $cache = true;
    public static $cache_expire = 2592000; // 1 month. 60 * 60 * 24 * 30

    public static $has_many = [
        'authors'
    ];
}
