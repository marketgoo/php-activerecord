<?php

namespace TestHelpers;

use SQLite3;
use ActiveRecord\Table;
use ActiveRecord\Config;
use ActiveRecord\ConnectionManager;
use ActiveRecord\Exceptions\DatabaseException;
use ActiveRecord\Exceptions\UndefinedPropertyException;

class DatabaseTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    protected $conn;
    protected $connection_name;

    protected $original_default_connection;
    protected $original_date_class;

    public static $log = false;
    public static $db;

    public function setUp(): void
    {
        Table::clear_cache();

        $config = Config::instance();
        $this->original_default_connection = $config->get_default_connection();
        $this->original_date_class = $config->get_date_class();

        if ($this->connection_name) {
            $config->set_default_connection($this->connection_name);
        }

        if ($this->connection_name == 'sqlite' || $config->get_default_connection() == 'sqlite') {
            // need to create the db. the adapter specifically does not create it for us.
            static::$db = substr(Config::instance()->get_connection('sqlite'), 9);

            if (!file_exists(static::$db)) {
                // A previous test class deleted the file (SqliteAdapterTest does), so the
                // cached connection points at a dead file and the loader still thinks the
                // schema exists. Reconnect and rebuild from scratch.
                ConnectionManager::drop_connection('sqlite');
                DatabaseLoader::$instances['sqlite'] = 0;
            }

            new SQLite3(static::$db);
        }

        try {
            $this->conn = ConnectionManager::get_connection($this->connection_name);
        } catch (DatabaseException $e) {
            // PHPUnit does not run tearDown() when setUp() skips the test, so undo
            // the default connection change here. Otherwise an unreachable adapter
            // stays as the default and every later test class is skipped as well.
            $config->set_default_connection($this->original_default_connection);
            $this->mark_test_skipped($this->connection_name . ' failed to connect. ' . $e->getMessage());
        }

        $GLOBALS['ACTIVERECORD_LOG'] = false;

        $loader = new DatabaseLoader($this->conn);
        $loader->reset_table_data();

        if (self::$log) {
            $GLOBALS['ACTIVERECORD_LOG'] = true;
        }
    }

    public function tearDown(): void
    {
        Config::instance()->set_date_class($this->original_date_class);

        if ($this->original_default_connection) {
            Config::instance()->set_default_connection($this->original_default_connection);
        }
    }

    public function assert_exception_message_contains($contains, $closure)
    {
        $message = "";

        try {
            $closure();
        } catch (UndefinedPropertyException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString($contains, $message);
    }

    /**
     * Returns true if $regex matches $actual.
     *
     * Takes database specific quotes into account by removing them. So, this won't
     * work if you have actual quotes in your strings.
     */
    public function assert_sql_has($needle, $haystack)
    {
        $needle = str_replace(['"','`'], '', $needle);
        $haystack = str_replace(['"','`'], '', $haystack);
        return $this->assertStringContainsString($needle, $haystack);
    }

    public function assert_sql_doesnt_has($needle, $haystack)
    {
        $needle = str_replace(['"','`'], '', $needle);
        $haystack = str_replace(['"','`'], '', $haystack);
        return $this->assertStringNotContainsString($needle, $haystack);
    }

    public function test_database_dummy_test()
    {
        // Dummy test so that PHPUnit doesn't emit "no tests found" warning
        $this->assertTrue(true);
    }
}
