<?php

use TestHelpers\JsonModelTestCase;

class MysqlJsonTest extends JsonModelTestCase
{
    public function setUp(): void
    {
        $this->connection_name = 'mysql';
        parent::setUp();
    }

    protected function expected_raw_type()
    {
        return 'json';
    }
}
