<?php

namespace TestModels\Clickhouse;

use ActiveRecord\Model;

class PageView extends Model
{
    public static $connection = 'clickhouse_sync';

    public static $belongs_to = [
        ['site']
    ];

    public static $alias_attribute = [
        'path' => 'url'
    ];
}
