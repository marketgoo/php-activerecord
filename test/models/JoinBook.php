<?php

namespace TestModels;

use ActiveRecord\Model;

class JoinBook extends Model
{
    public static $table_name = 'books';

    public static $belongs_to = [];
}
