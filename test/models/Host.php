<?php

namespace TestModels;

use ActiveRecord\Model;

class Host extends Model
{
    static $has_many = [
        'events',
        ['venues', 'through' => 'events']
    ];
}
