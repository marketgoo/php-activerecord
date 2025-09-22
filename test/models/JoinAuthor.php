<?php

namespace TestModels;

use ActiveRecord\Model;

class JoinAuthor extends Model
{
    static $table_name = 'authors';
    static $pk = 'author_id';
}
