<?php

namespace TestHelpers;

use PDO;
use JsonException;
use ActiveRecord\Column;
use ActiveRecord\Config;
use ActiveRecord\Json;
use TestModels\Document;

/**
 * Model-level tests for JSON columns. Subclasses set $connection_name so the
 * same tests run against MySQL/MariaDB, PostgreSQL and SQLite in every job,
 * independently of the default adapter.
 */
abstract class JsonModelTestCase extends DatabaseTest
{
    public function setUp(): void
    {
        if (
            !in_array($this->connection_name, PDO::getAvailableDrivers()) ||
            Config::instance()->get_connection($this->connection_name) == 'skip'
        ) {
            $this->mark_test_skipped($this->connection_name . ' drivers are not present');
        } else {
            parent::setUp();
        }
    }

    public function tearDown(): void
    {
        if ($this->status()->asString() == "skipped") {
            return;
        }

        parent::tearDown();
    }

    /**
     * The raw text stored in a column, bypassing the model.
     */
    protected function raw_column($column, $id)
    {
        $values = [$id];
        return $this->conn->query_and_fetch_one("SELECT $column FROM documents WHERE id = ?", $values);
    }

    /**
     * Normalises JSON text so databases that reformat documents (MySQL adds spaces) compare equal.
     */
    protected function assert_json_text($expected, $actual)
    {
        $normalise = function ($json) {
            return json_encode(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
        };
        $this->assert_same($normalise($expected), $normalise($actual));
    }

    /**
     * The JSON type this database reports for the documents.payload column.
     */
    abstract protected function expected_raw_type();

    public function test_native_json_columns_are_detected()
    {
        $columns = $this->conn->columns('documents');

        $this->assert_equals($this->expected_raw_type(), $columns['payload']->raw_type);
        $this->assert_equals(Column::JSON, $columns['payload']->type);
        $this->assert_equals(Column::JSON, $columns['metadata']->type);

        // settings is text at the database level and only JSON through the model
        $this->assert_equals(Column::STRING, $columns['settings']->type);
        $this->assert_equals(Column::JSON, Document::table()->columns['settings']->type);
    }

    public function test_read_decodes_object()
    {
        $doc = Document::find(1);

        $this->assert_true($doc->payload instanceof Json);
        $this->assert_equals('dark', $doc->payload['theme']);
        $this->assert_equals(['php', 'sql'], $doc->payload['tags']);
        $this->assert_same(1, $doc->payload['nested']['count']);
        $this->assert_equals(
            ['theme' => 'dark', 'tags' => ['php', 'sql'], 'nested' => ['count' => 1]],
            $doc->payload->to_array()
        );
    }

    public function test_read_decodes_list()
    {
        $doc = Document::find(2);

        $this->assert_same([1, 2, 3], $doc->payload->to_array());
        $this->assert_false($doc->payload->is_object());
        $this->assert_equals(3, count($doc->payload));
    }

    public function test_found_record_is_not_dirty()
    {
        $doc = Document::find(1);

        // reading, including nested reads, must not flag anything
        $doc->payload['theme'];
        $doc->payload['nested']['count'];
        $doc->payload['missing'];

        $this->assert_false($doc->is_dirty());
        $this->assert_null($doc->dirty_attributes());
    }

    public function test_save_without_changes_does_not_update()
    {
        $doc = Document::find(1);
        $before = Document::table()->last_sql;

        $this->assert_true($doc->save());
        $this->assert_equals($before, Document::table()->last_sql);
    }

    public function test_assign_array_round_trips()
    {
        $doc = Document::find(1);
        $doc->payload = ['a' => 1, 'b' => [true, null, 1.5, 'x'], 'c' => ['d' => 'e']];

        $this->assert_true($doc->payload instanceof Json);
        $this->assert_true($doc->attribute_is_dirty('payload'));
        $this->assert_true($doc->save());

        $reloaded = Document::find(1);
        // MySQL reorders object keys, so compare without regard to order
        $this->assert_equals(['a' => 1, 'b' => [true, null, 1.5, 'x'], 'c' => ['d' => 'e']], $reloaded->payload->to_array());
        $this->assert_same(1, $reloaded->payload['a']);
        $this->assert_same([true, null, 1.5, 'x'], $reloaded->payload['b']);
        $this->assert_json_text('{"a":1,"b":[true,null,1.5,"x"],"c":{"d":"e"}}', $this->raw_column('payload', 1));
    }

    public function test_assign_encoded_string_still_works()
    {
        $doc = Document::find(1);
        $doc->payload = json_encode(['legacy' => true]);

        $this->assert_true($doc->payload instanceof Json);
        $this->assert_true($doc->payload['legacy']);
        $doc->save();

        $this->assert_same(['legacy' => true], Document::find(1)->payload->to_array());
    }

    public function test_assign_object()
    {
        $doc = Document::find(1);
        $doc->payload = (object)['from' => 'stdClass'];
        $doc->save();

        $this->assert_same(['from' => 'stdClass'], Document::find(1)->payload->to_array());
    }

    public function test_assign_invalid_json_string_throws()
    {
        $doc = Document::find(1);

        $this->expectException(JsonException::class);
        $doc->payload = '{not json';
    }

    public function test_top_level_write_flags_dirty()
    {
        $doc = Document::find(1);
        $doc->payload['theme'] = 'light';

        $this->assert_true($doc->attribute_is_dirty('payload'));
        $this->assert_equals(['payload'], array_keys($doc->dirty_attributes()));
        $doc->save();

        $this->assert_equals('light', Document::find(1)->payload['theme']);
    }

    public function test_nested_write_is_saved()
    {
        $doc = Document::find(1);
        $doc->payload['nested']['count'] = 2;
        $doc->payload['tags'][] = 'json';
        $doc->payload['new']['deep'] = true;

        $this->assert_true($doc->is_dirty());
        $doc->save();

        $reloaded = Document::find(1);
        $this->assert_same(2, $reloaded->payload['nested']['count']);
        $this->assert_equals(['php', 'sql', 'json'], $reloaded->payload['tags']);
        $this->assert_true($reloaded->payload['new']['deep']);
    }

    public function test_unset_key_is_saved()
    {
        $doc = Document::find(1);
        unset($doc->payload['theme']);
        $doc->save();

        $this->assert_false(isset(Document::find(1)->payload['theme']));
    }

    public function test_model_is_clean_after_save()
    {
        $doc = Document::find(1);
        $doc->payload['nested']['count'] = 2;
        $doc->save();

        $this->assert_false($doc->is_dirty());

        $doc->payload['nested']['count'] = 3;
        $this->assert_true($doc->is_dirty());
    }

    public function test_null_document()
    {
        $doc = Document::create(['title' => 'nothing']);
        $this->assert_null(Document::find($doc->id)->payload);

        $doc = Document::find(1);
        $doc->payload = null;
        $doc->save();
        $this->assert_null(Document::find(1)->payload);
        $this->assert_null($this->raw_column('payload', 1));
    }

    public function test_empty_object_and_empty_list_round_trip()
    {
        $doc = Document::find(1);
        $this->assert_true($doc->metadata->is_object());
        $this->assert_equals('{}', $doc->metadata->to_json());

        $doc->metadata = Json::decode('{}');
        $doc->payload = [];
        $doc->save();

        $this->assert_json_text('{}', $this->raw_column('metadata', 1));
        $this->assert_json_text('[]', $this->raw_column('payload', 1));
        $this->assert_true(Document::find(1)->metadata->is_object());
    }

    public function test_scalar_document()
    {
        $doc = Document::find(1);
        $doc->payload = 42;
        $doc->save();

        $this->assert_same(42, Document::find(1)->payload->value());
    }

    public function test_unicode_round_trips()
    {
        $doc = Document::find(1);
        $doc->payload = ['name' => 'José Pérez', 'city' => '東京', 'emoji' => '😀'];
        $doc->save();

        $this->assert_equals(['name' => 'José Pérez', 'city' => '東京', 'emoji' => '😀'], Document::find(1)->payload->to_array());
    }

    public function test_create_with_array()
    {
        $doc = Document::create(['title' => 'created', 'payload' => ['k' => 'v'], 'settings' => ['notify' => false]]);

        $reloaded = Document::find($doc->id);
        $this->assert_same(['k' => 'v'], $reloaded->payload->to_array());
        $this->assert_same(['notify' => false], $reloaded->settings->to_array());
    }

    public function test_update_attributes()
    {
        $doc = Document::find(1);
        $doc->update_attributes(['payload' => ['z' => 1]]);

        $this->assert_same(['z' => 1], Document::find(1)->payload->to_array());
    }

    public function test_new_records_get_their_own_default_document()
    {
        $a = new Document();
        $b = new Document();

        if ($a->metadata === null) {
            // this database does not allow a default on the column
            $this->assert_null($b->metadata);
            return;
        }

        $this->assert_true($a->metadata instanceof Json);
        $this->assert_equals('{}', $a->metadata->to_json());
        $this->assert_false($a->metadata === $b->metadata);

        $a->metadata['only'] = 'a';
        $this->assert_equals('{}', $b->metadata->to_json());
        $this->assert_true($a->attribute_is_dirty('metadata'));

        $a->save();
        $this->assert_equals(['only' => 'a'], Document::find($a->id)->metadata->to_array());
    }

    public function test_json_attributes_opt_in_for_text_column()
    {
        $doc = Document::find(1);

        $this->assert_true($doc->settings instanceof Json);
        $this->assert_true($doc->settings['notify']);

        $doc->settings['notify'] = false;
        $doc->settings['level'] = 3;
        $doc->save();

        $this->assert_same(['notify' => false, 'level' => 3], Document::find(1)->settings->to_array());
        $this->assert_json_text('{"notify":false,"level":3}', $this->raw_column('settings', 1));
    }

    public function test_assigning_a_document_from_another_model_copies_it()
    {
        $a = Document::find(1);
        $b = Document::find(2);

        $b->payload = $a->payload;
        $b->payload['theme'] = 'light';

        $this->assert_equals('dark', $a->payload['theme']);
        $this->assert_false($a->is_dirty());
        $this->assert_true($b->is_dirty());

        $b->save();
        $this->assert_equals('light', Document::find(2)->payload['theme']);
        $this->assert_equals('dark', Document::find(1)->payload['theme']);
    }

    public function test_assigning_between_attributes_copies_it()
    {
        $doc = Document::find(1);
        $doc->metadata = $doc->payload;
        $doc->metadata['theme'] = 'light';

        $this->assert_equals('dark', $doc->payload['theme']);
        $this->assert_equals('light', $doc->metadata['theme']);
    }

    public function test_reload_discards_changes()
    {
        $doc = Document::find(1);
        $doc->payload['theme'] = 'light';
        $doc->payload['nested']['count'] = 9;
        $doc->reload();

        $this->assert_equals('dark', $doc->payload['theme']);
        $this->assert_same(1, $doc->payload['nested']['count']);
        $this->assert_false($doc->is_dirty());
    }

    public function test_to_json_nests_the_document()
    {
        $decoded = json_decode(Document::find(1)->to_json(), true);

        $this->assert_equals('dark', $decoded['payload']['theme']);
        $this->assert_equals(['php', 'sql'], $decoded['payload']['tags']);
        $this->assert_same([], $decoded['metadata']);
        $this->assert_true(is_array($decoded['payload']));
        $this->assert_true($decoded['settings']['notify']);
        $this->assert_equals('{}', json_encode(json_decode(Document::find(1)->to_json())->metadata));
    }

    public function test_to_array_returns_plain_arrays()
    {
        $array = Document::find(1)->to_array();

        $this->assert_true(is_array($array['payload']));
        $this->assert_equals(['theme' => 'dark', 'tags' => ['php', 'sql'], 'nested' => ['count' => 1]], $array['payload']);
        $this->assert_same(['notify' => true], $array['settings']);
    }

    public function test_to_xml_nests_the_document()
    {
        $xml = Document::find(1)->to_xml();

        $this->assert_true(strpos($xml, '<theme>dark</theme>') !== false);
        $this->assert_true(strpos($xml, '<nested><count>1</count></nested>') !== false);
    }

    public function test_find_by_title_keeps_working()
    {
        $doc = Document::find_by_title('List');
        $this->assert_same([1, 2, 3], $doc->payload->to_array());
    }

    public function test_table_update_with_raw_array()
    {
        $data = ['payload' => ['raw' => true]];
        Document::table()->update($data, ['id' => 2]);

        $this->assert_same(['raw' => true], Document::find(2)->payload->to_array());
    }
}
