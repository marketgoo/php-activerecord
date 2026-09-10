<?php

use ActiveRecord\Column;
use ActiveRecord\Json;
use TestHelpers\SnakeCase_PHPUnit_Framework_TestCase;

/**
 * Unit tests for the Json value object and the JSON column casts. No database needed.
 */
class JsonTest extends SnakeCase_PHPUnit_Framework_TestCase
{
    /**
     * Stand-in for a model that records the attributes flagged dirty.
     */
    private function model_spy()
    {
        return new class {
            public $dirty = [];

            public function flag_dirty($name)
            {
                $this->dirty[] = $name;
            }
        };
    }

    public function test_wraps_hash_as_object()
    {
        $json = new Json(['a' => 1, 'b' => [1, 2]]);
        $this->assert_true($json->is_object());
        $this->assert_equals(['a' => 1, 'b' => [1, 2]], $json->to_array());
        $this->assert_equals('{"a":1,"b":[1,2]}', $json->to_json());
        $this->assert_equals('{"a":1,"b":[1,2]}', (string)$json);
    }

    public function test_wraps_list_as_array()
    {
        $json = new Json([1, 2, 3]);
        $this->assert_false($json->is_object());
        $this->assert_equals('[1,2,3]', $json->to_json());
    }

    public function test_empty_array_encodes_as_list_unless_told_otherwise()
    {
        $this->assert_equals('[]', (new Json([]))->to_json());
        $this->assert_equals('{}', (new Json([], true))->to_json());
        $this->assert_equals('{}', json_encode(new Json([], true)));
    }

    public function test_wraps_scalars()
    {
        $this->assert_same(5, (new Json(5))->value());
        $this->assert_equals('5', (new Json(5))->to_json());
        $this->assert_equals('"five"', (new Json('five'))->to_json());
        $this->assert_equals('true', (new Json(true))->to_json());
    }

    public function test_wraps_objects()
    {
        $json = new Json((object)['a' => 1, 'b' => (object)['c' => 2]]);
        $this->assert_true($json->is_object());
        $this->assert_equals(['a' => 1, 'b' => ['c' => 2]], $json->to_array());

        $serializable = new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['from' => 'jsonSerialize'];
            }
        };
        $this->assert_equals(['from' => 'jsonSerialize'], (new Json($serializable))->to_array());
    }

    public function test_copies_another_document()
    {
        $original = new Json(['a' => 1]);
        $copy = new Json($original);
        $copy['a'] = 2;

        $this->assert_equals(1, $original['a']);
        $this->assert_equals(2, $copy['a']);
    }

    public function test_decode()
    {
        $json = Json::decode(' {"a": {"b": [1, 2.0, "x", null, true]}} ');
        $this->assert_true($json->is_object());
        $this->assert_same(['a' => ['b' => [1, 2.0, 'x', null, true]]], $json->to_array());
        $this->assert_equals('{"a":{"b":[1,2.0,"x",null,true]}}', $json->to_json());
    }

    public function test_decode_preserves_empty_object()
    {
        $this->assert_equals('{}', Json::decode('{}')->to_json());
        $this->assert_equals('[]', Json::decode('[]')->to_json());
        $this->assert_true(Json::decode('{}')->is_object());
        $this->assert_false(Json::decode('[]')->is_object());
    }

    public function test_decode_scalars()
    {
        $this->assert_same(42, Json::decode('42')->value());
        $this->assert_same('str', Json::decode('"str"')->value());
        $this->assert_null(Json::decode('null')->value());
    }

    public function test_decode_invalid_json_throws()
    {
        $this->expectException(JsonException::class);
        Json::decode('{not json');
    }

    public function test_array_access()
    {
        $json = new Json(['a' => 1]);

        $this->assert_true(isset($json['a']));
        $this->assert_false(isset($json['missing']));
        $this->assert_equals(1, $json['a']);

        $json['b'] = 2;
        $this->assert_equals(['a' => 1, 'b' => 2], $json->to_array());

        unset($json['a']);
        $this->assert_equals(['b' => 2], $json->to_array());
        $this->assert_false(isset($json['a']));
    }

    public function test_append()
    {
        $json = new Json([1]);
        $json[] = 2;
        $this->assert_equals('[1,2]', $json->to_json());
    }

    public function test_nested_writes_go_through()
    {
        $json = new Json(['nested' => ['count' => 1], 'tags' => ['a']]);

        $json['nested']['count'] = 2;
        $json['tags'][] = 'b';
        $json['new']['deep']['key'] = 'value';

        $this->assert_equals(2, $json['nested']['count']);
        $this->assert_equals(['a', 'b'], $json['tags']);
        $this->assert_equals(['key' => 'value'], $json['new']['deep']);
    }

    public function test_reading_a_missing_key_does_not_change_the_document()
    {
        $json = new Json(['a' => 1]);
        $this->assert_null($json['missing']);
        $this->assert_false($json->is_changed());
        $this->assert_equals('{"a":1}', $json->to_json());
    }

    public function test_countable_and_iterable()
    {
        $json = new Json(['a' => 1, 'b' => 2]);
        $this->assert_equals(2, count($json));

        $seen = [];
        foreach ($json as $key => $value) {
            $seen[$key] = $value;
        }
        $this->assert_equals(['a' => 1, 'b' => 2], $seen);
    }

    public function test_is_changed_tracks_in_place_modifications()
    {
        $json = new Json(['nested' => ['count' => 1]]);
        $this->assert_false($json->is_changed());

        $json['nested']['count'] = 2;
        $this->assert_true($json->is_changed());

        $json->mark_clean();
        $this->assert_false($json->is_changed());

        $json['nested']['count'] = 1;
        $this->assert_true($json->is_changed());
    }

    public function test_offset_set_flags_the_model_dirty()
    {
        $model = $this->model_spy();
        $json = new Json(['a' => 1]);
        $json->attribute_of($model, 'payload');

        $json['a'] = 2;
        $this->assert_equals(['payload'], $model->dirty);

        unset($json['a']);
        $this->assert_equals(['payload', 'payload'], $model->dirty);
    }

    public function test_attachment_queries()
    {
        $model = $this->model_spy();
        $json = new Json();
        $this->assert_false($json->is_attached());

        $json->attribute_of($model, 'payload');
        $this->assert_true($json->is_attached());
        $this->assert_true($json->is_attribute_of($model, 'payload'));
        $this->assert_false($json->is_attribute_of($model, 'other'));
        $this->assert_false($json->is_attribute_of($this->model_spy(), 'payload'));
    }

    public function test_clone_detaches_from_model()
    {
        $model = $this->model_spy();
        $json = new Json(['a' => 1]);
        $json->attribute_of($model, 'payload');

        $copy = clone $json;
        $copy['a'] = 2;

        $this->assert_false($copy->is_attached());
        $this->assert_equals([], $model->dirty);
        $this->assert_equals(1, $json['a']);
    }

    public function test_json_serializable_nests_in_json_encode()
    {
        $json = new Json(['a' => 1]);
        $this->assert_equals('{"doc":{"a":1}}', json_encode(['doc' => $json]));
    }

    public function test_encode_flags_keep_unicode_and_slashes()
    {
        $json = new Json(['name' => 'José', 'url' => 'http://example.com/a']);
        $this->assert_equals('{"name":"José","url":"http://example.com/a"}', $json->to_json());
    }

    public function test_map_raw_type()
    {
        $column = new Column();

        $column->raw_type = 'json';
        $this->assert_equals(Column::JSON, $column->map_raw_type());

        $column->raw_type = 'jsonb';
        $this->assert_equals(Column::JSON, $column->map_raw_type());
    }

    public function test_cast_string()
    {
        $column = new Column();
        $column->type = Column::JSON;

        $value = $column->cast('{"a":1}', null);
        $this->assert_true($value instanceof Json);
        $this->assert_equals(['a' => 1], $value->to_array());
    }

    public function test_cast_array_and_object()
    {
        $column = new Column();
        $column->type = Column::JSON;

        $this->assert_equals(['a' => 1], $column->cast(['a' => 1], null)->to_array());
        $this->assert_equals(['a' => 1], $column->cast((object)['a' => 1], null)->to_array());
        $this->assert_same(5, $column->cast(5, null)->value());
    }

    public function test_cast_keeps_documents_and_nulls()
    {
        $column = new Column();
        $column->type = Column::JSON;

        $json = new Json(['a' => 1]);
        $this->assert_same($json, $column->cast($json, null));
        $this->assert_null($column->cast(null, null));
    }

    public function test_cast_invalid_string_throws()
    {
        $column = new Column();
        $column->type = Column::JSON;

        $this->expectException(JsonException::class);
        $column->cast('{oops', null);
    }

    public function test_cast_default()
    {
        $column = new Column();
        $column->type = Column::JSON;

        $this->assert_equals('{}', $column->cast_default('{}', null)->to_json());
        $this->assert_equals('{"a":1}', $column->cast_default("'{\"a\":1}'", null)->to_json());
        $this->assert_null($column->cast_default('json_object()', null));
        $this->assert_null($column->cast_default(null, null));

        // other types are unaffected
        $column->type = Column::INTEGER;
        $this->assert_same(3, $column->cast_default('3', null));
    }
}
