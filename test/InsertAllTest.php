<?php

use ActiveRecord\Exceptions\ActiveRecordException;
use TestHelpers\DatabaseTest;
use TestModels\Author;
use TestModels\Book;
use TestModels\Document;
use TestModels\Venue;

class InsertAllTest extends DatabaseTest
{
    public function test_inserts_every_row_and_returns_the_count()
    {
        $before = Author::count();

        $count = Author::insert_all([
            ['name' => 'First'],
            ['name' => 'Second'],
            ['name' => 'Third']
        ]);

        $this->assert_equals(3, $count);
        $this->assert_equals($before + 3, Author::count());
        $this->assert_equals(3, Author::count(['conditions' => ['name IN(?)', ['First', 'Second', 'Third']]]));
    }

    public function test_generates_primary_keys()
    {
        Author::insert_all([['name' => 'Generated A'], ['name' => 'Generated B']]);

        $ids = array_map(function ($author) {
            return $author->author_id;
        }, Author::find('all', ['conditions' => ['name LIKE ?', 'Generated%']]));

        $this->assert_equals(2, count(array_unique($ids)));
        $this->assert_true(min($ids) > 0);
    }

    public function test_uses_one_statement_per_batch()
    {
        Author::insert_all([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);
        $this->assert_equals(3, substr_count(Author::table()->last_sql, '(?'), 'three rows in one INSERT');

        Author::insert_all([['name' => 'd'], ['name' => 'e'], ['name' => 'f']], ['batch_size' => 2]);
        $this->assert_equals(1, substr_count(Author::table()->last_sql, '(?'), 'the last batch holds the third row');
        $this->assert_equals(1, Author::count(['conditions' => ['name = ?', 'f']]));
    }

    public function test_rows_may_set_different_columns()
    {
        Author::insert_all([
            ['name' => 'Only name'],
            ['name' => 'With parent', 'parent_author_id' => 2],
            ['parent_author_id' => 3, 'name' => 'Same columns, other order']
        ]);

        $this->assert_null(Author::find_by_name('Only name')->parent_author_id);
        $this->assert_equals(2, Author::find_by_name('With parent')->parent_author_id);
        $this->assert_equals(3, Author::find_by_name('Same columns, other order')->parent_author_id);
    }

    public function test_resolves_inflected_names_and_aliases()
    {
        // books has an Author_Id column; venues aliases marquee to name and mycity to city
        Book::insert_all([['name' => 'Inflected', 'author_id' => 2]]);
        $this->assert_equals(2, Book::find_by_name('Inflected')->author_id);

        Venue::insert_all([['marquee' => 'Aliased', 'mycity' => 'Somewhere', 'address' => 'x']]);
        $this->assert_equals('Somewhere', Venue::find_by_name('Aliased')->city);
    }

    public function test_converts_dates_and_fills_timestamps()
    {
        Author::insert_all([
            ['name' => 'Dated', 'some_date' => new \DateTime('2026-09-24 15:00:00')],
            ['name' => 'Stamped']
        ]);

        $this->assert_equals('2026-09-24', Author::find_by_name('Dated')->some_date->format('Y-m-d'));

        $stamped = Author::find_by_name('Stamped');
        $this->assert_not_null($stamped->created_at);
        $this->assert_not_null($stamped->updated_at);
    }

    public function test_encodes_json_columns()
    {
        Document::insert_all([['title' => 'Bulk', 'payload' => ['a' => [1, 2]], 'settings' => ['x' => true]]]);

        $document = Document::find_by_title('Bulk');
        $this->assert_equals(['a' => [1, 2]], $document->payload->to_array());
        $this->assert_equals(['x' => true], $document->settings->to_array());
    }

    public function test_skips_setters_validations_and_callbacks()
    {
        // Author::set_name() upper-cases names; insert_all() builds no model
        Author::insert_all([['name' => 'lower case']]);
        $this->assert_equals(1, Author::count(['conditions' => ['name = ?', 'lower case']]));
    }

    public function test_nothing_to_insert()
    {
        $before = Author::count();
        $this->assert_equals(0, Author::insert_all([]));
        $this->assert_equals($before, Author::count());
    }

    public function test_rejects_unknown_options()
    {
        $this->expectException(ActiveRecordException::class);
        Author::insert_all([['name' => 'x']], ['batch' => 10]);
    }

    public function test_rejects_rows_that_are_not_hashes()
    {
        $this->expectException(ActiveRecordException::class);
        Author::insert_all(['just a string']);
    }

    public function test_rejects_a_batch_size_below_one()
    {
        $this->expectException(ActiveRecordException::class);
        Author::insert_all([['name' => 'x']], ['batch_size' => 0]);
    }
}
