<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord\Adapters;

use ActiveRecord\Column;
use ActiveRecord\Inflector;
use ActiveRecord\Connection;

/**
 * Adapter for MySQL.
 *
 * @package ActiveRecord
 */
class MysqlAdapter extends Connection
{
    static $DEFAULT_PORT = 3306;

    private $supports_datetime_offsets;

    public function limit($sql, $offset, $limit)
    {
        $offset = is_null($offset) ? '' : intval($offset) . ',';
        $limit = intval($limit);
        return "$sql LIMIT {$offset}$limit";
    }

    public function query_column_info($table)
    {
        return $this->query("SHOW COLUMNS FROM $table");
    }

    public function query_for_tables()
    {
        return $this->query('SHOW TABLES');
    }

    public function create_column(&$column)
    {
        $c = new Column();
        $c->inflected_name  = Inflector::instance()->variablize($column['field']);
        $c->name            = $column['field'];
        $c->nullable        = ($column['null'] === 'YES' ? true : false);
        $c->pk              = ($column['key'] === 'PRI' ? true : false);
        $c->auto_increment  = ($column['extra'] === 'auto_increment' ? true : false);

        if ($column['type'] == 'timestamp' || $column['type'] == 'datetime') {
            $c->raw_type = 'datetime';
            $c->length = 19;
        } elseif ($column['type'] == 'date') {
            $c->raw_type = 'date';
            $c->length = 10;
        } elseif ($column['type'] == 'time') {
            $c->raw_type = 'time';
            $c->length = 8;
        } else {
            preg_match('/^([A-Za-z0-9_]+)(\(([0-9]+(,[0-9]+)?)\))?/', $column['type'], $matches);

            $c->raw_type = (count($matches) > 0 ? $matches[1] : $column['type']);

            if (count($matches) >= 4) {
                $c->length = intval($matches[3]);
            }
        }

        $c->map_raw_type();
        $c->default = $c->cast($column['default'], $this);

        return $c;
    }

    /**
     * MySQL accepts a time zone offset in DATETIME literals only since 8.0.19.
     * Older servers, and MariaDB, reject the offset under the default strict
     * sql_mode, so they get the plain wall-clock value instead.
     */
    public function datetime_to_string($datetime)
    {
        if ($this->supports_datetime_offsets()) {
            return parent::datetime_to_string($datetime);
        }

        return $datetime->format('Y-m-d H:i:s');
    }

    private function supports_datetime_offsets()
    {
        if ($this->supports_datetime_offsets === null) {
            $version = (string)$this->connection->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $this->supports_datetime_offsets = stripos($version, 'mariadb') === false
                && version_compare($version, '8.0.19', '>=');
        }

        return $this->supports_datetime_offsets;
    }

    public function set_encoding($charset)
    {
        $params = array($charset);
        $this->query('SET NAMES ?', $params);
    }

    public function accepts_limit_and_order_for_update_and_delete()
    {
        return true;
    }

    public function native_database_types()
    {
        return [
            'primary_key' => 'int(11) UNSIGNED DEFAULT NULL auto_increment PRIMARY KEY',
            'string' => ['name' => 'varchar', 'length' => 255],
            'text' => ['name' => 'text'],
            'integer' => ['name' => 'int', 'length' => 11],
            'float' => ['name' => 'float'],
            'datetime' => ['name' => 'datetime'],
            'timestamp' => ['name' => 'datetime'],
            'time' => ['name' => 'time'],
            'date' => ['name' => 'date'],
            'binary' => ['name' => 'blob'],
            'boolean' => ['name' => 'tinyint', 'length' => 1]
        ];
    }
}
