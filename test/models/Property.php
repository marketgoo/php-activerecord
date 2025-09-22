<?php

namespace TestModels;

use ActiveRecord\Model;

class Property extends Model
{
    static $table_name = 'property';
    static $primary_key = 'property_id';

    static $has_many = [
        'property_amenities',
        ['amenities', 'through' => 'property_amenities']
    ];
}
