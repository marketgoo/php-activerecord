<?php

use TestHelpers\JsonModelTestCase;

class PgsqlJsonTest extends JsonModelTestCase
{
    public function setUp(): void
    {
        $this->connection_name = 'pgsql';
        parent::setUp();
    }

    protected function expected_raw_type()
    {
        return 'jsonb';
    }
}
