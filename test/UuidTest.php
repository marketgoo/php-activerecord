<?php

use ActiveRecord\Uuid;
use ActiveRecord\Exceptions\ActiveRecordException;
use TestHelpers\SnakeCase_PHPUnit_Framework_TestCase;

class UuidTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    public function test_v7_is_a_decimal_of_a_version_7_uuid()
    {
        $id = Uuid::v7();

        $this->assert_true(ctype_digit($id));
        $this->assert_matches_regular_expression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            Uuid::to_string($id)
        );
    }

    public function test_v7_carries_the_current_time()
    {
        $hex = str_replace('-', '', Uuid::to_string(Uuid::v7()));
        $milliseconds = hexdec(substr($hex, 0, 12));

        $this->assert_less_than(1000, abs($milliseconds - (int)(microtime(true) * 1000)));
    }

    public function test_v7_increases_within_a_process()
    {
        $previous = Uuid::v7();

        for ($i = 0; $i < 10000; $i++) {
            $id = Uuid::v7();
            $this->assert_true(strlen($id) > strlen($previous) || (strlen($id) == strlen($previous) && strcmp($id, $previous) > 0));
            $previous = $id;
        }
    }

    public function test_to_string_and_from_string_round_trip()
    {
        $uuid = '01a0d139-b9f2-7000-8000-000000000001';

        $this->assert_equals('2164239090310730585219565691961606145', Uuid::from_string($uuid));
        $this->assert_equals($uuid, Uuid::to_string('2164239090310730585219565691961606145'));
    }

    public function test_limits()
    {
        $this->assert_equals('00000000-0000-0000-0000-000000000000', Uuid::to_string(0));
        $this->assert_equals('0', Uuid::from_string('00000000-0000-0000-0000-000000000000'));
        $this->assert_equals('ffffffff-ffff-ffff-ffff-ffffffffffff', Uuid::to_string('340282366920938463463374607431768211455'));
        $this->assert_equals('340282366920938463463374607431768211455', Uuid::from_string('FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF'));
    }

    public function test_from_string_accepts_braces_and_no_dashes()
    {
        $this->assert_equals('1', Uuid::from_string('{00000000000000000000000000000001}'));
    }

    public function test_to_string_rejects_what_does_not_fit_in_128_bits()
    {
        $this->expectException(ActiveRecordException::class);
        Uuid::to_string('340282366920938463463374607431768211456');
    }

    public function test_to_string_rejects_non_digits()
    {
        $this->expectException(ActiveRecordException::class);
        Uuid::to_string('-1');
    }

    public function test_from_string_rejects_malformed_text()
    {
        $this->expectException(ActiveRecordException::class);
        Uuid::from_string('not-a-uuid');
    }
}
