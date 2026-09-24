<?php

use ActiveRecord\Column;
use ActiveRecord\Config;
use ActiveRecord\Connection;
use ActiveRecord\Adapters\ClickhouseAdapter;
use ActiveRecord\Adapters\Clickhouse\Statement;
use ActiveRecord\Exceptions\DatabaseException;
use TestHelpers\ClickhouseTestCase;

class ClickhouseAdapterTest extends ClickhouseTestCase
{
    private function url($query = '')
    {
        $url = Config::instance()->get_connection('clickhouse');
        return $query ? $url . (strpos($url, '?') === false ? '?' : '&') . $query : $url;
    }

    private function insert_typed_row()
    {
        $this->conn->query(<<<'SQL'
INSERT INTO typed VALUES (
    255, -7, 18446744073709551615, -9223372036854775808, 340282366920938463463374607431768211455,
    0.5, 1234567.891, true, 'tab\there', 'abc', 'low', NULL, 'b',
    '2026-09-24', '2026-09-24 12:34:56', '2026-09-24 12:34:56.789',
    '{"a": {"b": 1}, "c": [1, 2]}', [1, 2, 3], {'k': 1}, '01a0d139-b9f2-7000-8000-000000000001'
)
SQL);
    }

    public function test_server_version()
    {
        $this->assert_true(version_compare($this->conn->server_version(), '25.9', '>='));
    }

    public function test_wrong_password()
    {
        $url = parse_url($this->url());
        $this->expectException(DatabaseException::class);
        Connection::instance("clickhouse://{$url['user']}:wrong-password@{$url['host']}:" . ($url['port'] ?? 8123) . '/test');
    }

    public function test_unknown_database()
    {
        $this->expectException(DatabaseException::class);
        Connection::instance(str_replace('/test', '/__1337__invalid_db__', $this->url()));
    }

    public function test_unreachable_server()
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot reach ClickHouse');
        Connection::instance('clickhouse://test:test@127.0.0.1:1/test?connect_timeout=2');
    }

    public function test_url_parameters_are_settings()
    {
        $conn = Connection::instance($this->url('max_threads=3&async_insert=1'));

        $this->assert_equals(3, $conn->query_and_fetch_one("SELECT getSetting('max_threads')"));
        $this->assert_true($conn->query_and_fetch_one("SELECT getSetting('async_insert')"));
    }

    public function test_default_settings()
    {
        $conn = Connection::instance($this->url());

        $this->assert_false($conn->query_and_fetch_one("SELECT getSetting('async_insert')"));
        $this->assert_true($conn->query_and_fetch_one("SELECT getSetting('wait_for_async_insert')"), 'for when async_insert is turned on');
        $this->assert_equals(0, $conn->query_and_fetch_one("SELECT getSetting('lightweight_deletes_sync')"));
    }

    public function test_datetimes_are_written_as_the_same_instant()
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Europe/Madrid');

        try {
            $values = [new \DateTime('2026-09-09 18:00:00')];
            $this->conn->query("INSERT INTO typed (dt, dt64) VALUES (?, '1788969600')", $values);

            $row = $this->conn->query('SELECT toUnixTimestamp(dt) AS ts, dt, dt64 FROM typed')->fetch();
            $this->assert_equals(1788969600, $row['ts']);
            $this->assert_equals('2026-09-09 18:00:00', $row['dt'], 'read back in PHP\'s time zone');
            $this->assert_equals('2026-09-09 18:00:00.000', $row['dt64']);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_datetimes_are_read_in_php_timezone()
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Europe/Madrid');

        try {
            $row = $this->conn->query(
                "SELECT toDateTime('2026-09-09 16:00:00', 'UTC') AS a, toDateTime64('2026-09-09 16:00:00.123', 3, 'Asia/Tokyo') AS b,"
                . " CAST(NULL AS Nullable(DateTime('UTC'))) AS c, toDate('2026-09-09') AS d"
            )->fetch();

            $this->assert_equals('2026-09-09 18:00:00', $row['a']);
            $this->assert_equals('2026-09-09 09:00:00.123', $row['b']);
            $this->assert_null($row['c']);
            $this->assert_equals('2026-09-09', $row['d'], 'dates have no time zone');
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_uncompressed_connection()
    {
        $conn = Connection::instance($this->url('compress=0'));
        $this->assert_equals(4950, $conn->query_and_fetch_one('SELECT sum(number) FROM numbers(100)'));
    }

    public function test_fetch_modes()
    {
        $sth = $this->conn->query('SELECT number AS n, toString(number) AS s FROM numbers(3)');

        $this->assert_equals(['n' => 0, 's' => '0'], $sth->fetch());
        $this->assert_equals([1, '1'], $sth->fetch(PDO::FETCH_NUM));
        $this->assert_equals(['n' => 2, 's' => '2', 0 => 2, 1 => '2'], $sth->fetch(PDO::FETCH_BOTH));
        $this->assert_false($sth->fetch());

        $sth = $this->conn->query('SELECT number FROM numbers(3)');
        $this->assert_equals([0, 1, 2], $sth->fetchAll(PDO::FETCH_COLUMN));

        $sth = $this->conn->query('SELECT number AS n FROM numbers(2)');
        $this->assert_equals([['n' => 0], ['n' => 1]], iterator_to_array($sth));
    }

    public function test_row_count_and_column_count_of_a_result()
    {
        $sth = $this->conn->query('SELECT number, 1 FROM numbers(5)');
        $this->assert_equals(5, $sth->rowCount());
        $this->assert_equals(2, $sth->columnCount());
    }

    public function test_column_names_are_lower_cased()
    {
        $row = $this->conn->query('SELECT 1 AS MixedCase')->fetch();
        $this->assert_equals(['mixedcase' => 1], $row);
    }

    public function test_values_are_cast_by_type()
    {
        $this->insert_typed_row();
        $row = $this->conn->query('SELECT * FROM typed')->fetch();

        $this->assert_same(255, $row['u8']);
        $this->assert_same(-7, $row['i32']);
        $this->assert_same('18446744073709551615', $row['u64'], 'beyond PHP_INT_MAX');
        $this->assert_same(PHP_INT_MIN, $row['i64']);
        $this->assert_same('340282366920938463463374607431768211455', $row['u128']);
        $this->assert_same(0.5, $row['f64']);
        $this->assert_same('1234567.891', $row['dec']);
        $this->assert_same(true, $row['b']);
        $this->assert_same("tab\there", $row['s']);
        $this->assert_same('abc', $row['fs']);
        $this->assert_same('low', $row['lc']);
        $this->assert_null($row['ns']);
        $this->assert_same('b', $row['e']);
        $this->assert_same('2026-09-24', $row['d']);
        $this->assert_same('2026-09-24 12:34:56', $row['dt']);
        $this->assert_same('2026-09-24 12:34:56.789', $row['dt64']);
        $this->assert_equals(['a' => ['b' => 1], 'c' => [1, 2]], json_decode($row['j'], true));
        $this->assert_same([1, 2, 3], $row['arr']);
        $this->assert_same(['k' => 1], $row['m']);
        $this->assert_same('01a0d139-b9f2-7000-8000-000000000001', $row['uuid']);
    }

    public function test_uint64_that_fits_is_an_int()
    {
        $this->assert_same(PHP_INT_MAX, $this->conn->query_and_fetch_one('SELECT toUInt64(9223372036854775807)'));
        $this->assert_same('9223372036854775808', $this->conn->query_and_fetch_one('SELECT toUInt64(9223372036854775808)'));
    }

    public function test_special_floats()
    {
        $row = $this->conn->query('SELECT 1/0 AS a, -1/0 AS b, 0/0 AS c')->fetch();
        $this->assert_same(INF, $row['a']);
        $this->assert_same(-INF, $row['b']);
        $this->assert_nan($row['c']);
    }

    public function test_bound_strings_round_trip_every_byte()
    {
        $all = implode('', array_map('chr', range(0, 255)));
        $values = [$all, "O'Reilly \"quoted\" `ticks`", 'C:\\path\\n', 'ñandú 漢字 🎉', "\0" . '1'];

        foreach ($values as $value) {
            $params = [$value];
            $this->assert_same(strtoupper(bin2hex($value)), $this->conn->query_and_fetch_one('SELECT hex(?)', $params));
        }
    }

    public function test_question_marks_in_literals_identifiers_and_comments_are_not_parameters()
    {
        $values = ['x'];
        $row = $this->conn->query("SELECT '?' AS `a?`, 1 AS \"b?\", ? AS c -- is this one?\n/* or ? this */", $values)->fetch();

        $this->assert_equals(['a?' => '?', 'b?' => 1, 'c' => 'x'], $row);
    }

    public function test_escaped_quotes_inside_literals()
    {
        $values = ['x'];
        $this->assert_equals("it's?", $this->conn->query_and_fetch_one("SELECT 'it\\'s?' || '' WHERE ? = 'x'", $values));
    }

    public function test_missing_bound_value()
    {
        $this->expectException(DatabaseException::class);
        $values = [1];
        $this->conn->query('SELECT ?, ?', $values);
    }

    public function test_extra_bound_value()
    {
        $this->expectException(DatabaseException::class);
        $values = [1, 2];
        $this->conn->query('SELECT ?', $values);
    }

    public function test_literals()
    {
        $c = $this->conn;

        $this->assert_equals('NULL', $c->literal(null));
        $this->assert_equals('1', $c->literal(true));
        $this->assert_equals('0', $c->literal(false));
        $this->assert_equals('42', $c->literal(42));
        $this->assert_equals("'42'", $c->literal('42'));
        $this->assert_equals('0.1', $c->literal(0.1));
        $this->assert_equals('inf', $c->literal(INF));
        $this->assert_equals("'it\\'s \\\\'", $c->literal("it's \\"));
        $this->assert_equals("[1,'a',NULL]", $c->literal([1, 'a', null]));
        $this->assert_equals("{'k':1}", $c->literal(['k' => 1]));
        $this->assert_equals("'1790244000'", $c->literal(new \DateTime('2026-09-24 10:00:00 UTC')));
        $this->assert_equals("'it\\'s'", $c->escape("it's"));
    }

    public function test_quoted_numbers_compare_with_numeric_columns()
    {
        $this->insert_typed_row();

        $values = ['255', '340282366920938463463374607431768211455'];
        $this->assert_equals(1, $this->conn->query_and_fetch_one('SELECT count() FROM typed WHERE u8 = ? AND u128 = ?', $values));
    }

    public function test_invalid_query_carries_the_clickhouse_code()
    {
        try {
            $this->conn->query('SELEC 1');
            $this->fail('expected a DatabaseException');
        } catch (DatabaseException $e) {
            $this->assert_equals(62, $e->getCode());
            $this->assert_string_contains_string('Syntax error', $e->getMessage());
        }
    }

    public function test_error_after_the_result_started_streaming()
    {
        try {
            $this->conn->query('SELECT number, throwIf(number = 3000000) FROM numbers(4000000) SETTINGS max_block_size = 65536');
            $this->fail('expected a DatabaseException');
        } catch (DatabaseException $e) {
            $this->assert_equals(395, $e->getCode());
            $this->assert_string_contains_string('throwIf', $e->getMessage());
        }
    }

    public function test_columns()
    {
        $columns = $this->conn->columns('daily_metrics');

        $this->assert_equals(['site_id', 'day', 'metric', 'value', 'note'], array_keys($columns), 'MATERIALIZED and ALIAS columns are left out');

        $this->assert_equals('uint32', $columns['site_id']->raw_type);
        $this->assert_equals(Column::INTEGER, $columns['site_id']->type);
        $this->assert_equals(Column::DATE, $columns['day']->type);
        $this->assert_equals(Column::STRING, $columns['metric']->type, 'LowCardinality is unwrapped');
        $this->assert_equals(Column::DECIMAL, $columns['value']->type);
        $this->assert_same(1.5, $columns['value']->default);
        $this->assert_same("it's \\ fine", $columns['note']->default);

        foreach ($columns as $column) {
            $this->assert_false($column->pk, 'sorting key columns are not primary keys');
            $this->assert_false($column->nullable);
        }
    }

    public function test_columns_types()
    {
        $columns = $this->conn->columns('page_views');

        $this->assert_true($columns['id']->pk);
        $this->assert_equals('uint128', $columns['id']->raw_type);
        $this->assert_equals(Column::INTEGER, $columns['id']->type);
        $this->assert_true($columns['referrer']->nullable);
        $this->assert_equals(Column::JSON, $columns['payload']->type);
        $this->assert_null($columns['tags']->type, 'arrays are kept as they are');
        $this->assert_equals(Column::DATETIME, $columns['created_at']->type);

        $typed = $this->conn->columns('typed');
        $this->assert_equals(3, $typed['fs']->length);
        $this->assert_equals(12, $typed['dec']->length);
        $this->assert_equals(Column::DATETIME, $typed['dt64']->type);
    }

    public function test_columns_of_a_database_qualified_table()
    {
        $this->assert_true(array_key_exists('site_id', $this->conn->columns('`test`.`daily_metrics`')));
    }

    public function test_columns_of_a_missing_table()
    {
        $this->expectException(DatabaseException::class);
        $this->conn->columns('no_such_table');
    }

    public function test_tables()
    {
        $this->assert_true(in_array('page_views', $this->conn->tables()));
    }

    public function test_limit()
    {
        $this->assert_equals('SELECT 1 LIMIT 5,10', $this->conn->limit('SELECT 1', 5, 10));
        $this->assert_equals('SELECT 1 LIMIT 10', $this->conn->limit('SELECT 1', null, 10));

        $sql = $this->conn->limit('SELECT number FROM numbers(10) ORDER BY number', 2, 3);
        $this->assert_equals([2, 3, 4], $this->conn->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_no_transactions()
    {
        foreach (['transaction', 'commit', 'rollback'] as $method) {
            try {
                $this->conn->$method();
                $this->fail("$method() should throw");
            } catch (DatabaseException $e) {
                $this->assert_string_contains_string('transactions', $e->getMessage());
            }
        }
    }

    public function test_delete_without_conditions()
    {
        $this->conn->query('INSERT INTO sites VALUES (1, \'a\', now()), (2, \'b\', now())');
        $this->conn->query('DELETE FROM `sites`');

        $this->assert_equals(0, $this->conn->query_and_fetch_one('SELECT count() FROM sites'));
    }

    public function test_row_count_is_null_when_the_server_does_not_say()
    {
        $this->conn->query("INSERT INTO sites VALUES (1, 'a', now())");

        $this->assert_null($this->conn->query('ALTER TABLE sites UPDATE name = \'b\' WHERE 1 SETTINGS mutations_sync = 1')->rowCount());
        $this->assert_null($this->conn->query('DELETE FROM sites WHERE id = 1')->rowCount());
    }

    public function test_row_count_of_a_synchronous_insert()
    {
        $this->assert_equals(2, $this->conn->query("INSERT INTO sites VALUES (1, 'a', now()), (2, 'b', now())")->rowCount());
    }

    public function test_large_batches_skip_the_async_insert_buffer()
    {
        $comment = uniqid('batch');
        $conn = Connection::instance($this->url("async_insert=1&log_comment=$comment"));
        $rows = array_map(function ($i) {
            return [$i, "site $i"];
        }, range(1, ClickhouseAdapter::$SYNC_INSERT_MIN_ROWS));

        $conn->insert_rows('`sites`', ['id', 'name'], array_slice($rows, 0, 10));
        $conn->insert_rows('`sites`', ['id', 'name'], $rows);
        $conn->query('SYSTEM FLUSH LOGS');

        // Settings only lists values that differ from the server default, which varies by version
        $sql = "SELECT written_rows, if(Settings['async_insert'] = '',"
            . " (SELECT `default` FROM system.settings WHERE name = 'async_insert'), Settings['async_insert'])"
            . ' FROM system.query_log'
            . " WHERE log_comment = ? AND query_kind = 'Insert' AND type = 'QueryFinish' ORDER BY event_time_microseconds";
        $values = [$comment];
        $inserts = $conn->query($sql, $values)->fetchAll(PDO::FETCH_NUM);

        $this->assert_equals(2, count($inserts));
        $this->assert_equals('1', $inserts[0][1], 'the small insert keeps the async default');
        $this->assert_equals('0', $inserts[1][1], 'the large one is synchronous');
        $this->assert_equals(count($rows), $inserts[1][0]);
    }

    public function test_parse_composite()
    {
        $this->assert_same(["x\ty", "q'z", null], Statement::parse_composite("['x\\ty','q\\'z',NULL]"));
        $this->assert_same(['a' => 1, 'b' => [2.5, -3]], Statement::parse_composite("{'a':1,'b':[2.5,-3]}"));
        $this->assert_same([1, 'x', [[], [true]]], Statement::parse_composite("(1,'x',[[],[true]])"));
        $this->assert_same(['18446744073709551615'], Statement::parse_composite('[18446744073709551615]'));
    }
}
