<?php

namespace TestModels\Biz;

use ActiveRecord\Model;

class Newsletter extends Model
{
    static $has_many = array(
        array('user_newsletters'),
        array('users', 'through' => 'user_newsletters'),
    );
}
