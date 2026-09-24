<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord\Exceptions;

use ActiveRecord\Connection;

/**
 * Thrown when there was an error performing a database operation.
 *
 * The error will be specific to whatever database you are running.
 *
 * @package ActiveRecord
 */
class DatabaseException extends ActiveRecordException
{
    /**
     * @param mixed $adapter_or_string_or_mystery A Connection, a PDOStatement, an exception or a message
     * @param int $code Error code for a plain message, such as a ClickHouse error code
     */
    public function __construct($adapter_or_string_or_mystery, $code = 0)
    {
        if ($adapter_or_string_or_mystery instanceof Connection) {
            parent::__construct(
                implode(", ", $adapter_or_string_or_mystery->connection->errorInfo()),
                intval($adapter_or_string_or_mystery->connection->errorCode())
            );
        } elseif ($adapter_or_string_or_mystery instanceof \PDOStatement) {
            parent::__construct(
                implode(", ", $adapter_or_string_or_mystery->errorInfo()),
                intval($adapter_or_string_or_mystery->errorCode())
            );
        } else {
            parent::__construct($adapter_or_string_or_mystery, $code);
        }
    }
}
