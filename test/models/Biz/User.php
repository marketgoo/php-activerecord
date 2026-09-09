<?php

namespace TestModels\Biz;

use ActiveRecord\Model;

class User extends Model
{
    static $has_many = array(
        array('user_newsletters'),
        array('newsletters', 'through' => 'user_newsletters')
    );
}
