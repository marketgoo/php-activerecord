<?php

namespace TestModels\Biz;

use ActiveRecord\Model;

class User extends Model
{
    public static $has_many = [
        ['user_newsletters'],
        ['newsletters', 'through' => 'user_newsletters']
    ];
}
