<?php

namespace TestModels\Clickhouse;

use ActiveRecord\Model;

class Session extends Model
{
    public static $connection = 'clickhouse_sync';
}
