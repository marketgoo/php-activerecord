<?php

namespace TestModels\Biz;

use ActiveRecord\Model;

class UserNewsletter extends Model
{
    public static $belong_to = [
        ['user'],
        ['newsletter'],
    ];
}
