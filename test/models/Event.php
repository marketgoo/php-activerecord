<?php

namespace TestModels;

use ActiveRecord\Model;

class Event extends Model
{
    static $belongs_to = [
        'host',
        'venue'
    ];

    static $delegate = [
        ['state', 'address', 'to' => 'venue'],
        ['name', 'to' => 'host', 'prefix' => 'woot']
    ];
}
