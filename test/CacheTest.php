<?php

use ActiveRecord\Cache;

class CacheTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    public function set_up()
    {
        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('The memcached extension is not available');
            return;
        }

        try {
            Cache::initialize('memcached://localhost');
        } catch (ActiveRecord\CacheException $e) {
            $this->markTestSkipped('Unable to connect to memcached server');
        }
    }

    public function tear_down()
    {
        Cache::flush();
    }

    private function cache_get()
    {
        return Cache::get("1337", function () {
            return "abcd";
        });
    }

    public function test_initialize()
    {
        $this->assert_not_null(Cache::$adapter);
    }

    public function test_initialize_with_null()
    {
        Cache::initialize(null);
        $this->assert_null(Cache::$adapter);
    }

    public function test_get_returns_the_value()
    {
        $this->assert_equals("abcd", $this->cache_get());
    }

    public function test_get_writes_to_the_cache()
    {
        $this->cache_get();
        $this->assert_equals("abcd", Cache::$adapter->read("1337"));
    }

    public function test_get_does_not_execute_closure_on_cache_hit()
    {
        $this->cache_get();
        Cache::get("1337", function () {
            throw new Exception("I better not execute!");
        });

        $this->expectNotToPerformAssertions();
    }

    public function test_cache_adapter_returns_false_on_cache_miss()
    {
        $this->assert_same(false, Cache::$adapter->read("some-key"));
    }

    public function test_get_works_without_caching_enabled()
    {
        Cache::$adapter = null;
        $this->assert_equals("abcd", $this->cache_get());
    }

    public function test_cache_expire()
    {
        Cache::$options['expire'] = 1;
        $this->cache_get();
        sleep(2);

        $this->assert_same(false, Cache::$adapter->read("1337"));
    }

    public function test_namespace_is_set_properly()
    {
        Cache::$options['namespace'] = 'myapp';
        $this->cache_get();
        $this->assert_same("abcd", Cache::$adapter->read("myapp::1337"));
    }

    /**
     * @expectedException ActiveRecord\CacheException
     * @expectedExceptionMessage Connection refused
     */
    public function test_exception_when_connect_fails()
    {
        $this->expectException(ActiveRecord\CacheException::class);
        Cache::initialize('memcached://127.0.0.1:1234');
    }
}
