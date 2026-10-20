<?php

namespace TestModels\Clickhouse;

use ActiveRecord\Model;

class Site extends Model
{
    public static $connection = 'clickhouse_sync';

    public static $has_many = [
        ['page_views']
    ];
}
