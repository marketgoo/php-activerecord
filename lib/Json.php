<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonException;
use JsonSerializable;
use Traversable;

/**
 * Value object for JSON columns.
 *
 * Attributes backed by a JSON column (MySQL/MariaDB JSON, PostgreSQL json and jsonb,
 * SQLite JSON, or any text column listed in the model's $json_attributes) hold an
 * instance of this class instead of the raw JSON text. It behaves like an array
 * and, like {@link DateTime}, flags the owning model as dirty when modified so that
 * nested changes are picked up by save().
 *
 * <code>
 * $doc = Document::find(1);
 * $doc->payload['theme'] = 'dark';       # flagged dirty immediately
 * $doc->payload['tags'][] = 'php';       # nested change, detected on save()
 * $doc->save();
 *
 * $doc->payload = ['a' => 1];            # plain arrays are wrapped automatically
 * $doc->payload->to_array();             # ['a' => 1]
 * (string)$doc->payload;                 # '{"a":1}'
 * </code>
 *
 * Documents are decoded to associative arrays. An empty object ({}) at the top
 * level is remembered so that it is written back as {} and not as [].
 *
 * @package ActiveRecord
 */
class Json implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    /**
     * Flags passed to json_encode() when writing a document to the database.
     */
    public static $ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * The decoded value: an array for JSON objects and arrays, or a scalar.
     * @var mixed
     */
    private $value;

    /**
     * True when the top-level value is a JSON object.
     * @var boolean
     */
    private $object;

    /**
     * Encoded form of the value the last time the document was clean.
     * @var string
     */
    private $clean;

    /**
     * Keys created by offsetGet() for a key that did not exist. They are dropped
     * again unless something was written through the returned reference.
     * @var array
     */
    private $vivified = [];

    private $model;
    private $attribute_name;

    /**
     * @param mixed $value Array, scalar, stdClass/JsonSerializable object or another Json
     * @param boolean|null $object Force the top-level value to be encoded as an object; detected when null
     */
    public function __construct($value = [], $object = null)
    {
        if ($value instanceof self) {
            $object = $object ?? $value->object;
            $value = $value->value;
        } elseif (is_object($value)) {
            $object = $object ?? true;
            $value = json_decode(json_encode($value, static::$ENCODE_FLAGS | JSON_THROW_ON_ERROR), true);
        }

        $this->value = $value;
        $this->object = $object ?? (is_array($value) && !static::is_list($value));
        $this->mark_clean();
    }

    /**
     * True if $array has sequential integer keys starting at zero, i.e. it encodes as a JSON array.
     */
    private static function is_list(array $array)
    {
        return $array === [] || array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * Builds a document from JSON text.
     *
     * @param string $json JSON text
     * @return static
     * @throws JsonException if $json is not valid JSON
     */
    public static function decode($json)
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return new static($value, ltrim($json)[0] === '{');
    }

    /**
     * Associates this document with a model attribute so that changes flag the model as dirty.
     */
    public function attribute_of($model, $attribute_name)
    {
        $this->model = $model;
        $this->attribute_name = $attribute_name;
    }

    /**
     * True if this document belongs to the given model attribute.
     */
    public function is_attribute_of($model, $attribute_name)
    {
        return $this->model === $model && $this->attribute_name === $attribute_name;
    }

    /**
     * True if this document is attached to a model attribute.
     */
    public function is_attached()
    {
        return $this->model !== null;
    }

    /**
     * The decoded value: an array for objects and arrays, a scalar otherwise.
     * @return mixed
     */
    public function value()
    {
        $this->prune();
        return $this->value;
    }

    /**
     * The decoded value as a PHP array.
     * @return array
     */
    public function to_array()
    {
        return (array)$this->value();
    }

    /**
     * The value as JSON text, the form written to the database.
     * @return string
     */
    public function to_json()
    {
        return json_encode($this->jsonSerialize(), static::$ENCODE_FLAGS | JSON_THROW_ON_ERROR);
    }

    /**
     * True when the top-level value is a JSON object rather than a list or scalar.
     * @return boolean
     */
    public function is_object()
    {
        return $this->object;
    }

    /**
     * True if the value differs from the last clean state, including nested changes
     * made through references that bypassed offsetSet().
     * @return boolean
     */
    public function is_changed()
    {
        return $this->to_json() !== $this->clean;
    }

    /**
     * Records the current value as the clean state.
     */
    public function mark_clean()
    {
        $this->clean = $this->to_json();
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_array($this->value) && isset($this->value[$offset]);
    }

    /**
     * Returns by reference so that nested writes ($json['a']['b'] = 1, $json['list'][] = 2)
     * modify the document instead of a copy. Those writes are detected by is_changed().
     */
    public function &offsetGet(mixed $offset): mixed
    {
        if (!is_array($this->value)) {
            $this->value = (array)$this->value;
        }

        if (!array_key_exists($offset, $this->value)) {
            // A slot is needed for writes through the reference ($json['new']['deep'] = 1)
            // to land in the document. A plain read leaves it null and prune() removes it,
            // so reading a missing key does not change the document.
            $this->value[$offset] = null;
            $this->vivified[$offset] = true;
        }

        return $this->value[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!is_array($this->value)) {
            $this->value = (array)$this->value;
        }

        if ($offset === null) {
            $this->value[] = $value;
        } else {
            $this->value[$offset] = $value;
            unset($this->vivified[$offset]);
        }

        $this->flag_dirty();
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_array($this->value)) {
            unset($this->value[$offset], $this->vivified[$offset]);
            $this->flag_dirty();
        }
    }

    /**
     * Drops the keys created by reads of missing offsets that were never written to.
     */
    private function prune()
    {
        foreach ($this->vivified as $offset => $unused) {
            if (is_array($this->value) && array_key_exists($offset, $this->value) && $this->value[$offset] === null) {
                unset($this->value[$offset]);
            }
        }

        $this->vivified = [];
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->to_array());
    }

    public function count(): int
    {
        return count($this->to_array());
    }

    /**
     * Value used by json_encode(). An empty top-level object is returned as an
     * object so it serializes to {} rather than [].
     */
    public function jsonSerialize(): mixed
    {
        $value = $this->value();

        if ($this->object && $value === []) {
            return (object)[];
        }

        return $value;
    }

    public function __toString(): string
    {
        return $this->to_json();
    }

    /**
     * A cloned document no longer belongs to the model it was copied from.
     */
    public function __clone()
    {
        $this->model = null;
        $this->attribute_name = null;
    }

    private function flag_dirty()
    {
        if ($this->model) {
            $this->model->flag_dirty($this->attribute_name);
        }
    }
}
