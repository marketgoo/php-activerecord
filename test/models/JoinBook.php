<?php

namespace TestModels;

use ActiveRecord\Model;

class JoinBook extends Model
{
    static $table_name = 'books';

    static $belongs_to = [];
}
