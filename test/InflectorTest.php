<?php

use ActiveRecord\Inflector;
use TestHelpers\SnakeCase_PHPUnit_Framework_TestCase;

class InflectorTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    protected $inflector;

    public function setUp(): void
    {
        $this->inflector = Inflector::instance();
    }

    public function test_underscorify()
    {
        $this->assert_equals('rm__name__bob', $this->inflector->variablize('rm--name  bob'));
        $this->assert_equals('One_Two_Three', $this->inflector->underscorify('OneTwoThree'));
    }

    public function test_tableize()
    {
        $this->assert_equals('angry_people', $this->inflector->tableize('AngryPerson'));
        $this->assert_equals('my_sqls', $this->inflector->tableize('MySQL'));
    }

    public function test_keyify()
    {
        $this->assert_equals('building_type_id', $this->inflector->keyify('BuildingType'));
    }
}
