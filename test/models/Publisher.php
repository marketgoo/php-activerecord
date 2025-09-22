<?php

namespace TestModels;

use ActiveRecord\Model;

class Publisher extends Model
{
    static $pk = 'publisher_id';
    static $cache = true;
    static $cache_expire = 2592000; // 1 month. 60 * 60 * 24 * 30

    static $has_many = [
        'authors'
    ];
}
