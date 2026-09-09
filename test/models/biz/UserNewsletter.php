<?php

namespace foo\bar\biz;

use ActiveRecord\Model;

class UserNewsletter extends Model
{
    static $belong_to = array(
        array('user'),
        array('newsletter'),
    );
}
