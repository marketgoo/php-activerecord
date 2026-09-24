# PHP ActiveRecord - Version 1.0 #

[![Build Status](https://travis-ci.org/jpfuentes2/php-activerecord.png?branch=master)](https://travis-ci.org/jpfuentes2/php-activerecord)

by 

* [@kla](https://github.com/kla) - Kien La
* [@jpfuentes2](https://github.com/jpfuentes2) - Jacques Fuentes
* [And these terrific Contributors](https://github.com/kla/php-activerecord/contributors)

<http://www.phpactiverecord.org/> 

## Introduction ##
A brief summarization of what ActiveRecord is:

> Active record is an approach to access data in a database. A database table or view is wrapped into a class,
> thus an object instance is tied to a single row in the table. After creation of an object, a new row is added to
> the table upon save. Any object loaded gets its information from the database; when an object is updated, the
> corresponding row in the table is also updated. The wrapper class implements accessor methods or properties for
> each column in the table or view.

More details can be found [here](http://en.wikipedia.org/wiki/Active_record_pattern).

This implementation is inspired and thus borrows heavily from Ruby on Rails' ActiveRecord.
We have tried to maintain their conventions while deviating mainly because of convenience or necessity.
Of course, there are some differences which will be obvious to the user if they are familiar with rails.

## Minimum Requirements ##

- PHP 8.0+
- PDO driver for your respective database
- The curl extension for ClickHouse, which is reached over HTTP instead of PDO

## Supported Databases ##

- MySQL
- SQLite
- PostgreSQL
- ClickHouse 25.9 or later, for analytics (see [ClickHouse](#clickhouse))

## Features ##

- Finder methods
- Dynamic finder methods
- Writer methods
- Relationships
- Validations
- Callbacks
- Serializations (json/xml)
- Transactions
- Support for multiple adapters
- Miscellaneous options such as: aliased/protected/accessible attributes

## Installation ##

Setup is very easy and straight-forward. There are essentially only three configuration points you must concern yourself with:

1. Setting the model autoload directory.
2. Configuring your database connections.
3. Setting the database connection to use for your environment.

Example:

```php
ActiveRecord\Config::initialize(function($cfg)
{
   $cfg->set_model_directory('/path/to/your/model_directory');
   $cfg->set_connections(
     [
       'development' => 'mysql://username:password@localhost/development_database_name',
       'test' => 'mysql://username:password@localhost/test_database_name',
       'production' => 'mysql://username:password@localhost/production_database_name'
     ]
   );
});
```

Alternatively, without the closure:

```php
$cfg = ActiveRecord\Config::instance();
$cfg->set_model_directory('/path/to/your/model_directory');
$cfg->set_connections(
  [
    'development' => 'mysql://username:password@localhost/development_database_name',
    'test' => 'mysql://username:password@localhost/test_database_name',
    'production' => 'mysql://username:password@localhost/production_database_name'
  ]
);
```

PHP ActiveRecord will default to use your development database. For testing or production, you simply set the default
connection according to your current environment ('test' or 'production'):

```php
ActiveRecord\Config::initialize(function($cfg)
{
  $cfg->set_default_connection(your_environment);
});
```

Once you have configured these three settings you are done. ActiveRecord takes care of the rest for you.
It does not require that you map your table schema to yaml/xml files. It will query the database for this information and
cache it so that it does not make multiple calls to the database for a single schema.

## Basic CRUD ##

### Retrieve ###
These are your basic methods to find and retrieve records from your database.
See the *Finders* section for more details.

```php
$post = Post::find(1);
echo $post->title; # 'My first blog post!!'
echo $post->author_id; # 5

# also the same since it is the first record in the db
$post = Post::first();

# finding using dynamic finders
$post = Post::find_by_name('The Decider');
$post = Post::find_by_name_and_id('The Bridge Builder',100);
$post = Post::find_by_name_or_id('The Bridge Builder',100);

# finding using a conditions array
$posts = Post::find('all',['conditions' => ['name=? or id > ?','The Bridge Builder',100]]);
```

### Create ###
Here we create a new post by instantiating a new object and then invoking the save() method.

```php
$post = new Post();
$post->title = 'My first blog post!!';
$post->author_id = 5;
$post->save();
# INSERT INTO `posts` (title,author_id) VALUES('My first blog post!!', 5)
```

### Update ###
To update you would just need to find a record first and then change one of its attributes.
It keeps an array of attributes that are "dirty" (that have been modified) and so our
sql will only update the fields modified.

```php
$post = Post::find(1);
echo $post->title; # 'My first blog post!!'
$post->title = 'Some real title';
$post->save();
# UPDATE `posts` SET title='Some real title' WHERE id=1

$post->title = 'New real title';
$post->author_id = 1;
$post->save();
# UPDATE `posts` SET title='New real title', author_id=1 WHERE id=1
```

### Delete ###
Deleting a record will not *destroy* the object. This means that it will call sql to delete
the record in your database but you can still use the object if you need to.

```php
$post = Post::find(1);
$post->delete();
# DELETE FROM `posts` WHERE id=1
echo $post->title; # 'New real title'
```

### Bulk inserts ###
`insert_all()` writes many records with multi-row INSERT statements, on every supported database.
It builds no models, so validations, callbacks and custom setters do not run, and it is much faster
than calling `create()` in a loop.

```php
Post::insert_all([
  ['title' => 'First', 'author_id' => 5],
  ['title' => 'Second', 'author_id' => 5, 'published_on' => new DateTime('2026-09-24')]
]);
# INSERT INTO `posts`(`author_id`,`created_at`,`title`,`updated_at`) VALUES(?,?,?,?),(?,?,?,?)
# INSERT INTO `posts`(`author_id`,`created_at`,`published_on`,`title`,`updated_at`) VALUES(?,?,?,?,?)

Post::insert_all($rows, ['batch_size' => 5000]);
```

- Attribute names and aliases are resolved as in `create()`, dates and JSON values are converted,
  and missing `created_at`/`updated_at` columns get the current time.
- A missing primary key is generated wherever `save()` would generate it: auto-increment in MySQL
  and SQLite, the sequence in PostgreSQL and a UUIDv7 in ClickHouse.
- Rows need not all set the same attributes: rows that set the same columns share a statement.
- Statements hold up to `batch_size` rows: 1000 by default, 100000 for ClickHouse. They hold fewer
  when rows times columns would exceed the bound parameters the database accepts in one statement.
- It returns the number of rows sent to the database.

## JSON columns ##

Columns with a native JSON type (MySQL and MariaDB `JSON`, PostgreSQL `json` and `jsonb`,
SQLite `JSON`) are detected automatically. Their attributes hold an `ActiveRecord\Json`
document that behaves like an array and is encoded back to JSON text when the record is saved.
Changes made through the array syntax, including nested ones, mark the attribute as dirty.

```php
$doc = Document::find(1);
$doc->payload['theme'];              # 'dark'
$doc->payload['tags'][] = 'php';     # nested change
$doc->payload['nested']['count']++;  # also picked up
$doc->save();
# UPDATE `documents` SET payload='{"theme":"dark","tags":["php"],"nested":{"count":2}}' WHERE id=1

$doc->payload = ['theme' => 'light'];        # arrays and objects are wrapped for you
$doc->payload = '{"theme": "light"}';        # so is JSON text, as before
$doc->payload->to_array();                   # ['theme' => 'light']
echo $doc->payload;                          # {"theme":"light"}
$doc->to_json();                             # nests the document instead of double-encoding it
```

Text columns that hold JSON can get the same treatment by listing them in the model:

```php
class Document extends ActiveRecord\Model
{
    static $json_attributes = ['settings'];
}
```

Documents are decoded to associative arrays, so a nested empty object is written back as
`[]`. Only the top-level `{}` is preserved.

## ClickHouse ##

The ClickHouse adapter is meant for analytics: reading large results and inserting a lot, rarely
updating. Its defaults favour speed, except where that would lose data silently. It needs
ClickHouse 25.9 or later, the first release where lightweight `UPDATE` is on by default, and
talks to the HTTP interface through PHP's curl extension, with no PDO driver involved.

```php
$cfg->set_connections([
  'analytics' => 'clickhouse://user:password@localhost:8123/analytics'
]);

class PageView extends ActiveRecord\Model
{
  static $connection = 'analytics';
}
```

Connection URL options:

- `secure=1` connects over HTTPS (default port 8443 instead of 8123).
- `compress=0` turns off compressed responses (on by default).
- `connect_timeout` and `timeout`, in seconds (10 and no limit by default).
- Any other parameter is sent as a ClickHouse setting with every query. For example,
  `?async_insert=0&max_execution_time=30`. Do not set `session_timezone`: see *Dates* below.

### Inserts ###

Inserts are synchronous by default (`async_insert=0`). That is the fastest for each `save()`,
about 2 ms, and errors are reported. Pick the insert method by how you insert:

| How you insert | Use | Why |
|---|---|---|
| Many rows at once (imports, jobs, backfills) | `insert_all()` | Fastest of all: 100000 rows in about 50 ms, written as one data part |
| Records one at a time, a few per second per table (user actions, back office) | the default | Lowest latency, errors reported, merges keep up |
| Small inserts from many clients at a high rate that the application cannot batch (event tracking, logs) | a connection with `?async_insert=1` | The server batches them, so it is not flooded with parts |

Each synchronous insert writes a new data part to disk, which background merges then combine. At a
few inserts per second per table that costs nothing. At hundreds, merges fall behind: ClickHouse first
delays inserts (at 1000 active parts per partition by default) and then rejects them with a "too many
parts" error. Nothing is lost without an error, but that load needs async inserts.

With `async_insert=1` the server buffers the rows from every client and writes them together. The
adapter keeps `wait_for_async_insert=1`, as ClickHouse recommends, so each insert waits until its
batch is written. Errors are still reported, and a busy server can slow clients down. The price is
latency: an insert returns when the buffer is flushed, 50 to 200 ms later by default (the
`async_insert_busy_timeout_*` settings). Even then, `insert_all()` statements of 1000 rows or more skip
the buffer, because they are batched already and are written faster straight away. Change that
threshold with `ActiveRecord\Adapters\ClickhouseAdapter::$SYNC_INSERT_MIN_ROWS` (0 sends every
statement through the buffer).

Measured on a local ClickHouse 26.8 (25.9 gave the same picture) with single-row inserts:

| | sync<br>(default) | async, wait=1<br>`?async_insert=1` | async, wait=0<br>(not recommended) |
|---|---:|---:|---:|
| One process, one insert after another | 1.9 ms each | 62 ms each | 0.5 ms each |
| 32 processes for 20 s, merges running | 3222 inserts/s | 158 inserts/s | |
| Data parts written in those 20 s | ~64400 | ~150 | |
| Most active parts at once | 940 | 5 | |
| One INSERT of 100000 rows | 52 ms | 245 ms | 7 ms |
| Row readable right after the insert | yes | yes | no |
| Invalid row | error | error | **lost, no error** |

The 940 active parts came from a laptop with the data in memory. With real disks, bigger rows or
replicated tables, the limits arrive much sooner. `wait_for_async_insert=0` is the fastest on paper,
but every insert looks successful whether or not it was written, and nothing stops a client from
outrunning the server. You can still set it in the connection URL, knowing that failed inserts are
silently lost.

`DELETE` is not waited for (`lightweight_deletes_sync=0`); add `?lightweight_deletes_sync=2` to
change that.

### Primary keys ###

ClickHouse has no auto-increment, and the columns in a table's `ORDER BY` are not unique, so the
adapter never takes them as the primary key. The primary key is a column named `id`, or whatever the
model declares in `$primary_key`. When a new record has no key, one is generated in PHP as a
[UUIDv7](https://www.rfc-editor.org/rfc/rfc9562#section-5.7). That happens when the key column's
type can hold one:

- `UInt128` (recommended), `Int128`, `UInt256` or `Int256`: the model holds the key as a decimal string.
  It sorts numerically, so `ORDER BY id` follows creation order.
- `UUID` or `String`: the model holds UUID text. Avoid `UUID` if you sort by it: ClickHouse compares
  UUIDs by their second half first, so the order does not follow creation time.

`ActiveRecord\Uuid` converts between the two forms:

```php
$view = PageView::create(['url' => '/']);
$view->id;                                # '2164240782629168885377694484224845435'
ActiveRecord\Uuid::to_string($view->id);  # '01a0d14f-161f-709f-9d89-561ae88ca67b'
PageView::find(ActiveRecord\Uuid::from_string('01a0d14f-161f-709f-9d89-561ae88ca67b'));
ActiveRecord\Uuid::v7();                  # a new key, e.g. to set it before insert_all()
```

Tables without a primary key, typical for aggregated data, work for inserting and querying. Saving
or deleting a loaded record of such a table throws, because there is no key to find the row by.
`update_all()` and `delete_all()` still work.

### Updates and deletes ###

`save()` on a loaded record, `update_all()` and `delete_all()` use lightweight `UPDATE` and `DELETE`.
Lightweight `UPDATE` only works on tables created with these settings:

```sql
CREATE TABLE page_views (...) ENGINE = MergeTree ORDER BY (site_id, id)
SETTINGS enable_block_number_column = 1, enable_block_offset_column = 1;
```

Columns in the sorting key cannot be updated. ClickHouse does not report how many rows an `UPDATE`
or `DELETE` changed, so `update_all()` and `delete_all()` return `null` instead of a count. Their
`limit` and `order` options are ignored, as in PostgreSQL.

### Types ###

| ClickHouse | Attribute |
|---|---|
| `Int8`...`Int64`, `UInt8`...`UInt32`, `UInt64` up to `PHP_INT_MAX` | int |
| larger `UInt64`, `(U)Int128`, `(U)Int256` | numeric string |
| `Float32`, `Float64`, `Decimal` | float |
| `Bool` | int (0 or 1), like `tinyint(1)` in MySQL |
| `Date`, `Date32`, `DateTime`, `DateTime64` | `ActiveRecord\DateTime` |
| `JSON` | `ActiveRecord\Json` (see [JSON columns](#json-columns)) |
| `Array`, `Map`, `Tuple` | PHP array |
| `String`, `FixedString`, `LowCardinality`, `Enum`, `UUID`... | string |

`Nullable(...)` columns accept `null`. Inserting `null` into a column that is not `Nullable` stores
the column's default (its `DEFAULT` expression, or `''`, `0`...) without an error. `MATERIALIZED` and `ALIAS`
columns are not attributes, but you can read them by naming them in `select`.

### Dates ###

`DateTime` values are written as their Unix timestamp, so ClickHouse stores the exact instant
whatever time zone the column, the server or PHP use. When they are read back, they are moved into
PHP's time zone. A `DateTime` in PHP comes back as the same wall-clock time. Seconds are the smallest
unit written, as with the other adapters.

This holds for values that go through a model or are bound as `DateTime` objects. A date *string*
in a hand-written condition or in `insert_all()` is read by ClickHouse in the column's or the server's
time zone. Before 26.2, `INSERT` and `UPDATE` did not agree on how `session_timezone` applies to such
strings, which is why the adapter does not set it and you should not either.

### Not supported ###

- Transactions: `transaction()`, `commit()`, `rollback()` and `Model::transaction()` throw.
- The number of rows affected by `UPDATE` and `DELETE` (see above).
- Users with `readonly=1`: the adapter sends settings with every query, which such users may not do.
  `readonly=2` works.

## Contributing ##

Please refer to [CONTRIBUTING.md](https://github.com/jpfuentes2/php-activerecord/blob/master/CONTRIBUTING.md) for information on how to contribute to PHP ActiveRecord.
