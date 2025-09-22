<?php

namespace TestModels;

use ActiveRecord\Model;

class PropertyAmenity extends Model
{
    static $table_name = 'property_amenities';
    static $primary_key = 'id';

    static $belongs_to = [
        'amenity',
        'property'
    ];
}
