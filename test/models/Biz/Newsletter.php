<?php

namespace TestModels\Biz;

use ActiveRecord\Model;

class Newsletter extends Model
{
    public static $has_many = [
        ['user_newsletters'],
        ['users', 'through' => 'user_newsletters'],
    ];
}
