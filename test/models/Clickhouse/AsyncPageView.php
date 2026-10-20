<?php

namespace TestModels\Clickhouse;

use ActiveRecord\Model;

/**
 * The page_views table through a connection with async_insert=1: inserts go
 * through the server's async insert buffer.
 */
class AsyncPageView extends Model
{
    public static $connection = 'clickhouse_async';
    public static $table_name = 'page_views';
}
