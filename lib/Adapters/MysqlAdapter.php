<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord\Adapters;

use PDO;
use ActiveRecord\Column;
use ActiveRecord\Inflector;
use ActiveRecord\Connection;
use ActiveRecord\Exceptions\DatabaseException;

/**
 * Adapter for MySQL.
 *
 * @package ActiveRecord
 */
class MysqlAdapter extends Connection
{
    public static $DEFAULT_PORT = 3306;

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

    public function columns($table)
    {
        $columns = parent::columns($table);

        if ($this->is_mariadb()) {
            $this->detect_mariadb_json_columns($table, $columns);
        }

        return $columns;
    }

    /**
     * True when the server is MariaDB rather than MySQL.
     * @return boolean
     */
    public function is_mariadb()
    {
        return stripos((string)$this->connection->getAttribute(PDO::ATTR_SERVER_VERSION), 'mariadb') !== false;
    }

    /**
     * MariaDB stores JSON columns as LONGTEXT with a json_valid() check constraint
     * and reports them as longtext, so the constraints are what identify them.
     *
     * @param string $table Possibly quoted and schema-qualified table name
     * @param array $columns Column objects indexed by name, updated in place
     */
    private function detect_mariadb_json_columns($table, array $columns)
    {
        $parts = explode('.', str_replace('`', '', $table));
        $name = array_pop($parts);
        $schema = array_pop($parts);

        $sql = 'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS'
             . ' WHERE CONSTRAINT_SCHEMA = COALESCE(?, DATABASE()) AND TABLE_NAME = ?';
        $values = [$schema, $name];

        try {
            $sth = $this->query($sql, $values);
        } catch (DatabaseException $e) {
            // MariaDB before 10.3.10 has no CHECK_CONSTRAINTS table; leave the columns as text
            return;
        }

        while (($row = $sth->fetch())) {
            $clause = $row['CHECK_CLAUSE'] ?? $row['check_clause'] ?? '';

            if (preg_match('/^json_valid\(`?([^`)]+)`?\)$/i', $clause, $matches) && isset($columns[$matches[1]])) {
                $column = $columns[$matches[1]];
                $column->raw_type = 'json';
                $column->map_raw_type();
                $column->default = $column->cast_default($column->default, $this);
            }
        }
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
        $c->default = $c->cast_default($column['default'], $this);

        return $c;
    }

    public function set_encoding($charset)
    {
        $params = [$charset];
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
