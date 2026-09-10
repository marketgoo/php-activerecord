<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord\Adapters;

use PDO;
use ActiveRecord\Utils;
use ActiveRecord\Column;
use ActiveRecord\Connection;
use ActiveRecord\Inflector;
use ActiveRecord\Exceptions\DatabaseException;
use ActiveRecord\Exceptions\ActiveRecordException;

/**
 * Adapter for SQLite.
 *
 * @package ActiveRecord
 */
class SqliteAdapter extends Connection
{
    /**
     * Whether this build accepts ORDER BY/LIMIT on UPDATE and DELETE; detected on first use.
     */
    private $update_delete_limit = null;

    protected function __construct($info)
    {
        if (!file_exists($info->host)) {
            throw new DatabaseException("Could not find sqlite db: $info->host");
        }

        $this->connection = new PDO("sqlite:$info->host", null, null, static::$PDO_OPTIONS);
    }

    public function limit($sql, $offset, $limit)
    {
        $offset = is_null($offset) ? '' : intval($offset) . ',';
        $limit = intval($limit);
        return "$sql LIMIT {$offset}$limit";
    }

    public function query_column_info($table)
    {
        return $this->query("pragma table_info($table)");
    }

    public function query_for_tables()
    {
        return $this->query("SELECT name FROM sqlite_master");
    }

    public function create_column($column)
    {
        $c = new Column();
        $c->inflected_name  = Inflector::instance()->variablize($column['name']);
        $c->name            = $column['name'];
        $c->nullable        = $column['notnull'] ? false : true;
        $c->pk              = $column['pk'] ? true : false;
        $c->auto_increment  = in_array(
            strtoupper($column['type']),
            ['INT', 'INTEGER']
        ) && $c->pk;

        $column['type'] = preg_replace('/ +/', ' ', $column['type']);
        $column['type'] = str_replace(['(',')'], ' ', $column['type']);
        $column['type'] = Utils::squeeze(' ', $column['type']);
        $matches = explode(' ', $column['type']);

        if (!empty($matches)) {
            $c->raw_type = strtolower($matches[0]);

            if (count($matches) > 1) {
                $c->length = intval($matches[1]);
            }
        }

        $c->map_raw_type();

        if ($c->type == Column::DATETIME) {
            $c->length = 19;
        } elseif ($c->type == Column::DATE) {
            $c->length = 10;
        }

        // From SQLite3 docs: The value is a signed integer, stored in 1, 2, 3, 4, 6,
        // or 8 bytes depending on the magnitude of the value.
        // so is it ok to assume it's possible an int can always go up to 8 bytes?
        if ($c->type == Column::INTEGER && !$c->length) {
            $c->length = 8;
        }

        $c->default = $c->cast_default($column['dflt_value'], $this);

        return $c;
    }

    public function set_encoding($charset)
    {
        throw new ActiveRecordException("SqliteAdapter::set_charset not supported.");
    }

    /**
     * SQLite only parses ORDER BY and LIMIT on UPDATE and DELETE when built with
     * SQLITE_ENABLE_UPDATE_DELETE_LIMIT, which distributions differ on.
     */
    public function accepts_limit_and_order_for_update_and_delete()
    {
        if ($this->update_delete_limit === null) {
            $options = $this->query('PRAGMA compile_options')->fetchAll(PDO::FETCH_COLUMN);
            $this->update_delete_limit = in_array('ENABLE_UPDATE_DELETE_LIMIT', $options);
        }

        return $this->update_delete_limit;
    }

    public function native_database_types()
    {
        return [
            'primary_key' => 'integer not null primary key',
            'string' => ['name' => 'varchar', 'length' => 255],
            'text' => ['name' => 'text'],
            'integer' => ['name' => 'integer'],
            'float' => ['name' => 'float'],
            'decimal' => ['name' => 'decimal'],
            'datetime' => ['name' => 'datetime'],
            'timestamp' => ['name' => 'datetime'],
            'time' => ['name' => 'time'],
            'date' => ['name' => 'date'],
            'binary' => ['name' => 'blob'],
            'boolean' => ['name' => 'boolean']
        ];
    }
}
