<?php

use TestHelpers\JsonModelTestCase;

class SqliteJsonTest extends JsonModelTestCase
{
    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        @unlink(static::$db);
    }

    public function setUp(): void
    {
        $this->connection_name = 'sqlite';
        parent::setUp();
    }

    protected function expected_raw_type()
    {
        return 'json';
    }
}
