<?php

use ActiveRecord\Cache;
use ActiveRecord\Config;
use ActiveRecord\Adapters\PgsqlAdapter;
use ActiveRecord\Exceptions\CacheException;
use TestHelpers\DatabaseTest;
use TestModels\Author;

class ActiveRecordCacheTest extends DatabaseTest
{
    public function setUp(): void
    {
        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('The memcached extension is not available');
            return;
        }

        try {
            Config::instance()->set_cache('memcached://localhost');
        } catch (CacheException $e) {
            $this->markTestSkipped('Unable to connect to memcached server');
        }

        parent::setUp();
    }

    public function tearDown(): void
    {
        Cache::flush();
        Cache::initialize(null);
    }

    public function test_default_expire()
    {
        $this->assert_equals(30, Cache::$options['expire']);
    }

    public function test_explicit_default_expire()
    {
        Config::instance()->set_cache('memcached://localhost', array('expire' => 1));
        $this->assert_equals(1, Cache::$options['expire']);
    }

    public function test_caches_column_meta_data()
    {
        Author::first();

        $table_name = Author::table()->get_fully_qualified_table_name(!($this->conn instanceof PgsqlAdapter));
        $value = Cache::$adapter->read("get_meta_data-$table_name");
        $this->assert_true(is_array($value));
    }
}
