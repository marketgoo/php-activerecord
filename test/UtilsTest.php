<?php

use ActiveRecord\Utils;
use TestHelpers\SnakeCase_PHPUnit_Framework_TestCase;

class UtilsTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    protected $object_array;
    protected $array_hash;

    public function setUp(): void
    {
        $this->object_array = [null, null];
        $this->object_array[0] = new stdClass();
        $this->object_array[0]->a = "0a";
        $this->object_array[0]->b = "0b";
        $this->object_array[1] = new stdClass();
        $this->object_array[1]->a = "1a";
        $this->object_array[1]->b = "1b";

        $this->array_hash = [
            ["a" => "0a", "b" => "0b"],
            ["a" => "1a", "b" => "1b"]
        ];
    }

    public function test_collect_with_array_of_objects_using_closure()
    {
        $this->assert_equals(["0a","1a"], Utils::collect($this->object_array, function ($obj) {
            return $obj->a;
        }));
    }

    public function test_collect_with_array_of_objects_using_string()
    {
        $this->assert_equals(["0a","1a"], Utils::collect($this->object_array, "a"));
    }

    public function test_collect_with_array_hash_using_closure()
    {
        $this->assert_equals(["0a","1a"], Utils::collect($this->array_hash, function ($item) {
            return $item["a"];
        }));
    }

    public function test_collect_with_array_hash_using_string()
    {
        $this->assert_equals(["0a","1a"], Utils::collect($this->array_hash, "a"));
    }

    public function test_array_flatten()
    {
        $this->assert_equals([], Utils::array_flatten([]));
        $this->assert_equals([1], Utils::array_flatten([1]));
        $this->assert_equals([1], Utils::array_flatten([[1]]));
        $this->assert_equals([1, 2], Utils::array_flatten([[1, 2]]));
        $this->assert_equals([1, 2], Utils::array_flatten([[1], 2]));
        $this->assert_equals([1, 2], Utils::array_flatten([1, [2]]));
        $this->assert_equals([1, 2, 3], Utils::array_flatten([1, [2], 3]));
        $this->assert_equals([1, 2, 3, 4], Utils::array_flatten([1, [2, 3], 4]));
        $this->assert_equals([1, 2, 3, 4, 5, 6], Utils::array_flatten([1, [2, 3], 4, [5, 6]]));
    }

    public function test_all()
    {
        $this->assert_true(Utils::all(null, [null, null]));
        $this->assert_true(Utils::all(1, [1, 1]));
        $this->assert_false(Utils::all(1, [1, '1']));
        $this->assert_false(Utils::all(null, ['', null]));
    }

    public function test_classify()
    {
        $bad_class_names = ['ubuntu_rox', 'stop_the_Snake_Case', 'CamelCased', 'camelCased'];
        $good_class_names = ['UbuntuRox', 'StopTheSnakeCase', 'CamelCased', 'CamelCased'];

        $class_names = [];
        foreach ($bad_class_names as $s) {
            $class_names[] = Utils::classify($s);
        }

        $this->assert_equals($class_names, $good_class_names);
    }

    public function test_classify_singularize()
    {
        $bad_class_names = ['events', 'stop_the_Snake_Cases', 'angry_boxes', 'Mad_Sheep_herders', 'happy_People'];
        $good_class_names = ['Event', 'StopTheSnakeCase', 'AngryBox', 'MadSheepHerder', 'HappyPerson'];

        $class_names = [];
        foreach ($bad_class_names as $s) {
            $class_names[] = Utils::classify($s, true);
        }

        $this->assert_equals($class_names, $good_class_names);
    }

    public function test_singularize()
    {
        $this->assert_equals('order_status', Utils::singularize('order_status'));
        $this->assert_equals('order_status', Utils::singularize('order_statuses'));
        $this->assert_equals('os_type', Utils::singularize('os_type'));
        $this->assert_equals('os_type', Utils::singularize('os_types'));
        $this->assert_equals('photo', Utils::singularize('photos'));
        $this->assert_equals('pass', Utils::singularize('pass'));
        $this->assert_equals('pass', Utils::singularize('passes'));
    }

    public function test_wrap_strings_in_arrays()
    {
        $x = ['1', ['2']];
        $this->assert_equals([['1'], ['2']], Utils::wrap_strings_in_arrays($x));

        $x = '1';
        $this->assert_equals([['1']], Utils::wrap_strings_in_arrays($x));
    }
}
