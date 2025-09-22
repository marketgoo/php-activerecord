<?php

namespace TestModels;

use ActiveRecord\Model;

class BookAttrProtected extends Model
{
    static $pk = 'book_id';
    static $table_name = 'books';
    static $belongs_to = [
        ['author', 'class_name' => 'AuthorAttrAccessible', 'primary_key' => 'author_id']
    ];

    // No attributes should be accessible
    static $attr_accessible = [null];
}
