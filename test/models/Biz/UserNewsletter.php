<?php

namespace TestModels\Biz;

use ActiveRecord\Model;

class UserNewsletter extends Model
{
    static $belong_to = array(
        array('user'),
        array('newsletter'),
    );
}
