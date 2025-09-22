<?php

namespace TestModels;

use ActiveRecord\Model;

class Amenity extends Model
{
    static $table_name = 'amenities';
    static $primary_key = 'amenity_id';

    static $has_many = [
        'property_amenities'
    ];
}
