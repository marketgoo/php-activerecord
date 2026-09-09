# Contributing to PHP ActiveRecord #

We always appreciate contributions to PHP ActiveRecord, but we are not always able to respond as quickly as we would like.
Please do not take delays personal and feel free to remind us by commenting on issues.

### Testing ###

PHP ActiveRecord has a full set of unit tests, which are run by PHPUnit.

In order to run these unit tests, you need to install the required packages using [Composer](https://getcomposer.org/):

```sh
composer install
```

After that you can run the tests by invoking the local PHPUnit

To run all test simply use:

```sh
vendor/bin/phpunit
```

Or run a single test file by specifying its path:

```sh
vendor/bin/phpunit test/InflectorTest.php
```

#### Databases ####

Most tests need a MySQL server, and the PostgreSQL and memcached tests are skipped when their server
or PHP extension is missing. To see why a test was skipped:

```sh
vendor/bin/phpunit --display-skipped
```

The suite connects to `mysql://test:test@127.0.0.1/test` and `pgsql://test:test@127.0.0.1/test` by
default, and to memcached on `localhost:11211`. Override the database locations with the `PHPAR_MYSQL`,
`PHPAR_PGSQL` and `PHPAR_SQLITE` environment variables. The model-level tests run against MySQL unless
`PHPAR_ADAPTER` names another connection (`pgsql` or `sqlite`); the adapter tests always cover all three.

The easiest way to get the servers is Docker. `compose.yaml` defines the same versions that CI tests,
each on its own port, so you can run several side by side:

```sh
docker compose up -d mysql84 pgsql18 memcached
PHPAR_MYSQL=mysql://test:test@127.0.0.1:33084/test \
PHPAR_PGSQL=pgsql://test:test@127.0.0.1:54318/test \
vendor/bin/phpunit
```

Ports follow the version: `mysql57` is 33057, `mysql80` is 33080, `mysql84` is 33084, `mariadb1011` is
33111, `mariadb118` is 33118, `mariadb123` is 33123, `pgsql14` is 54314, `pgsql16` is 54316 and `pgsql18`
is 54318. MariaDB uses the MySQL adapter, so point `PHPAR_MYSQL` at it. The containers keep their data on tmpfs and skip fsyncs, so
they start empty every time and run fast even when Docker lives inside a VM.

You also need the PHP extensions `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite` and `memcached`.

#### Continuous integration ####

`.github/workflows/tests.yml` runs the suite on GitHub Actions against a small matrix: a baseline of
PHP 8.4, MySQL 8.4 and PostgreSQL 18, plus one job per other supported version of each, changing a
single axis at a time, and three MariaDB versions through the MySQL adapter. The PostgreSQL rows run
the model-level tests with PostgreSQL as the default adapter. Update the matrix when a PHP or database version reaches end of life.
