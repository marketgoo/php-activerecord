<?php

use ActiveRecord\Uuid;
use ActiveRecord\Exceptions\DatabaseException;
use ActiveRecord\Exceptions\ActiveRecordException;
use TestHelpers\ClickhouseTestCase;
use TestModels\Clickhouse\Site;
use TestModels\Clickhouse\Session;
use TestModels\Clickhouse\PageView;
use TestModels\Clickhouse\DailyMetric;
use TestModels\Clickhouse\AsyncPageView;

class ClickhouseModelTest extends ClickhouseTestCase
{
    private function view($attributes = [])
    {
        return PageView::create($attributes + ['site_id' => 1, 'url' => '/', 'viewed_on' => '2026-09-24']);
    }

    public function test_create_generates_a_uuidv7_key()
    {
        $view = $this->view();

        $this->assert_true(ctype_digit($view->id));
        $this->assert_matches_regular_expression('/^[0-9a-f]{8}-[0-9a-f]{4}-7/', Uuid::to_string($view->id));
    }

    public function test_find_by_the_generated_key()
    {
        $view = $this->view(['url' => '/found']);

        $this->assert_equals('/found', PageView::find($view->id)->url);
        $this->assert_equals('/found', PageView::find(Uuid::from_string(Uuid::to_string($view->id)))->url);
    }

    public function test_keys_follow_creation_order()
    {
        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $ids[] = $this->view(['url' => "/$i"])->id;
        }

        $found = array_map(function ($view) {
            return $view->id;
        }, PageView::find('all', ['order' => 'id']));

        $this->assert_equals($ids, $found);
    }

    public function test_an_explicit_key_is_kept()
    {
        $view = $this->view(['id' => '12345']);

        $this->assert_equals('12345', $view->id);
        $this->assert_equals('12345', PageView::find('12345')->id);
    }

    public function test_uuid_column_gets_uuid_text()
    {
        $session = Session::create(['site_id' => 1]);

        $this->assert_matches_regular_expression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab]/', $session->id);
        $this->assert_equals($session->id, Session::find($session->id)->id);
    }

    public function test_save_updates_with_a_lightweight_update()
    {
        $view = $this->view(['url' => '/before']);

        $view->url = '/after';
        $view->duration = 2.5;
        $view->save();

        $found = PageView::find($view->id);
        $this->assert_equals('/after', $found->url);
        $this->assert_equals(2.5, $found->duration);
    }

    public function test_delete()
    {
        $view = $this->view();
        $this->view();

        $view->delete();

        $this->assert_equals(1, PageView::count());
        $this->assert_false(PageView::exists($view->id));
    }

    public function test_update_all_and_delete_all_return_null()
    {
        $this->view(['site_id' => 1]);
        $this->view(['site_id' => 2]);

        $this->assert_null(PageView::update_all(['set' => ['url' => '/all'], 'conditions' => ['site_id' => 1]]));
        $this->assert_equals(1, PageView::count(['conditions' => ['url' => '/all']]));

        $this->assert_null(PageView::delete_all(['conditions' => ['site_id' => 2]]));
        $this->assert_equals(1, PageView::count());
    }

    public function test_sorting_key_columns_are_not_the_primary_key()
    {
        $this->assert_equals(['id'], PageView::table()->pk);
        $this->assert_equals([], DailyMetric::table()->pk);
        $this->assert_null(DailyMetric::table()->sequence);
    }

    public function test_table_without_primary_key()
    {
        DailyMetric::create(['site_id' => 1, 'day' => '2026-09-24', 'metric' => 'visits', 'value' => 10]);

        $metric = DailyMetric::first();
        $this->assert_equals(10, $metric->value);

        $metric->value = 11;
        try {
            $metric->save();
            $this->fail('saving a loaded record needs a primary key');
        } catch (ActiveRecordException $e) {
            $this->assert_string_contains_string('no primary key', $e->getMessage());
        }

        $this->expectException(ActiveRecordException::class);
        $metric->delete();
    }

    public function test_literal_defaults()
    {
        $metric = new DailyMetric();

        $this->assert_equals(1.5, $metric->value);
        $this->assert_equals("it's \\ fine", $metric->note);
    }

    public function test_arrays_json_and_nulls_round_trip()
    {
        $view = $this->view([
            'tags' => ['a', "b'c", 'd\\e'],
            'payload' => ['theme' => 'dark', 'counts' => [1, 2]]
        ]);

        $found = PageView::find($view->id);
        $this->assert_equals(['a', "b'c", 'd\\e'], $found->tags);
        $this->assert_equals(['theme' => 'dark', 'counts' => [1, 2]], $found->payload->to_array());
        $this->assert_null($found->referrer);

        $found->payload['theme'] = 'light';
        $found->save();
        $this->assert_equals('light', PageView::find($view->id)->payload['theme']);
    }

    public function test_dates_keep_wall_clock_time_in_any_timezone()
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Europe/Madrid');

        try {
            $view = $this->view();
            $view->created_at = new \DateTime('2026-09-09 18:00:00');
            $view->save();

            $found = PageView::find($view->id);
            $this->assert_equals('2026-09-09 18:00:00 +02:00', $found->created_at->format('Y-m-d H:i:s P'));
            $this->assert_equals('2026-09-24', $found->viewed_on->format('Y-m-d'));
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_timestamps_are_set()
    {
        $view = PageView::find($this->view()->id);

        $this->assert_not_null($view->created_at);
        $this->assert_not_null($view->updated_at);
    }

    public function test_alias_attribute()
    {
        $view = PageView::create(['site_id' => 1, 'path' => '/aliased']);
        $this->assert_equals('/aliased', PageView::find($view->id)->path);
    }

    public function test_relationships()
    {
        Site::create(['id' => 7, 'name' => 'Example']);
        $view = $this->view(['site_id' => 7]);
        $this->view(['site_id' => 7]);

        $this->assert_equals('Example', PageView::find($view->id)->site->name);
        $this->assert_equals(2, count(Site::find(7)->page_views));

        $sites = Site::find('all', ['include' => ['page_views']]);
        $this->assert_equals(2, count($sites[0]->page_views));
    }

    public function test_aggregates()
    {
        $this->view(['site_id' => 1, 'duration' => 1.0]);
        $this->view(['site_id' => 1, 'duration' => 2.0]);
        $this->view(['site_id' => 2, 'duration' => 4.0]);

        $this->assert_equals(2, PageView::count(['conditions' => ['site_id' => 1]]));

        $rows = PageView::find('all', [
            'select' => 'site_id, sum(duration) AS total',
            'group' => 'site_id',
            'order' => 'site_id'
        ]);
        $this->assert_equals([3.0, 4.0], [$rows[0]->total, $rows[1]->total]);
    }

    public function test_insert_all_generates_keys_in_batches()
    {
        $rows = [];
        for ($i = 0; $i < 250; $i++) {
            $rows[] = ['site_id' => $i % 3, 'path' => "/$i", 'viewed_on' => '2026-09-24', 'tags' => ["t$i"]];
        }

        $this->assert_equals(250, PageView::insert_all($rows, ['batch_size' => 100]));
        $this->assert_string_contains_string('(50 rows)', PageView::table()->last_sql);

        $this->assert_equals(250, PageView::count());
        $this->assert_equals(250, PageView::connection()->query_and_fetch_one('SELECT uniqExact(id) FROM page_views'));
        $this->assert_equals(['t7'], PageView::find('first', ['conditions' => ['url' => '/7']])->tags);
        $this->assert_not_null(PageView::find('first')->created_at);
    }

    public function test_insert_all_keeps_given_keys()
    {
        PageView::insert_all([['id' => '5', 'site_id' => 1, 'url' => '/5'], ['site_id' => 1, 'url' => '/generated']]);

        $this->assert_equals('/5', PageView::find('5')->url);
        $this->assert_equals(2, PageView::count());
    }

    public function test_insert_all_without_primary_key()
    {
        DailyMetric::insert_all([
            ['site_id' => 1, 'day' => '2026-09-24', 'metric' => 'visits', 'value' => 3],
            ['site_id' => 1, 'day' => '2026-09-24', 'metric' => 'signups']
        ]);

        $this->assert_equals(4.5, DailyMetric::connection()->query_and_fetch_one('SELECT sum(value) FROM daily_metrics'));
    }

    public function test_async_inserts_are_waited_for()
    {
        $view = AsyncPageView::create(['site_id' => 1, 'url' => '/async', 'viewed_on' => '2026-09-24']);
        AsyncPageView::insert_all([['site_id' => 1, 'url' => '/async-bulk']]);

        // readable straight away: save() returned once its batch was written
        $this->assert_equals('/async', PageView::find($view->id)->url);
        $this->assert_equals(1, PageView::count(['conditions' => ['url' => '/async-bulk']]));
    }

    public function test_async_insert_errors_are_reported()
    {
        $this->expectException(DatabaseException::class);
        AsyncPageView::connection()->query("INSERT INTO page_views (id, site_id) VALUES (1, 'not a number')");
    }

    public function test_transactions_are_not_supported()
    {
        $this->expectException(DatabaseException::class);

        PageView::transaction(function () {
            $this->view();
        });
    }
}
