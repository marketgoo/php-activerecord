<?php

namespace TestModels\Clickhouse;

use ActiveRecord\Model;

class DailyMetric extends Model
{
    public static $connection = 'clickhouse_sync';
}
