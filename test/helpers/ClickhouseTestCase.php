<?php

namespace TestHelpers;

use ActiveRecord\Table;
use ActiveRecord\ConnectionManager;
use ActiveRecord\Exceptions\DatabaseException;

/**
 * Base for the ClickHouse tests. They have their own schema (test/sql/clickhouse.sql)
 * rather than the shared fixtures, whose auto-increment keys and transactions
 * ClickHouse does not have. Tables are created once per run and emptied
 * before each test.
 *
 * Runs against the clickhouse_sync connection, which also waits for deletes,
 * and is skipped when no server answers at PHPAR_CLICKHOUSE.
 */
class ClickhouseTestCase extends SnakeCase_PHPUnit_Framework_TestCase
{
    /**
     * @var \ActiveRecord\Adapters\ClickhouseAdapter
     */
    protected $conn;

    private static $schema_loaded = false;

    public function setUp(): void
    {
        Table::clear_cache();

        try {
            $this->conn = ConnectionManager::get_connection('clickhouse_sync');
        } catch (DatabaseException $e) {
            $this->mark_test_skipped('ClickHouse failed to connect. ' . $e->getMessage());
        }

        $tables = self::tables();

        if (!self::$schema_loaded) {
            foreach ($tables as $table) {
                $this->conn->query("DROP TABLE IF EXISTS `$table`");
            }

            foreach (explode(';', file_get_contents(__DIR__ . '/../sql/clickhouse.sql')) as $sql) {
                if (trim($sql) !== '') {
                    $this->conn->query($sql);
                }
            }

            self::$schema_loaded = true;
        } else {
            foreach ($tables as $table) {
                $this->conn->query("TRUNCATE TABLE `$table`");
            }
        }
    }

    /**
     * @return array Tables created by test/sql/clickhouse.sql
     */
    private static function tables()
    {
        preg_match_all('/CREATE TABLE (\w+)/', file_get_contents(__DIR__ . '/../sql/clickhouse.sql'), $matches);
        return $matches[1];
    }
}
