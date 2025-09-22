<?php

/**
 * @package ActiveRecord
 */

namespace ActiveRecord;

/**
 * Interface for a table relationship.
 *
 * @package ActiveRecord
 */
interface RelationshipInterface
{
    public function __construct($options = []);
    public function build_association(Model $model, $attributes = [], $guard_attributes = true);
    public function create_association(Model $model, $attributes = [], $guard_attributes = true);
}
