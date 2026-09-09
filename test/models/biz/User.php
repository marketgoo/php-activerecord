<?php

namespace foo\bar\biz;

use ActiveRecord\Model;

class User extends Model
{
    static $has_many = array(
        array('user_newsletters'),
        array('newsletters', 'through' => 'user_newsletters')
    );
}
