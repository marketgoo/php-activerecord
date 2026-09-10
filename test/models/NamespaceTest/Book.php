<?php

namespace TestModels\NamespaceTest;

use ActiveRecord\Model;

class Book extends Model
{
    public static $belongs_to = [
        ['parent_book', 'class_name' => '\TestModels\NamespaceTest\Book'],
        ['parent_book_2', 'class_name' => 'Book'],
        ['parent_book_3', 'class_name' => '\TestModels\Book'],
    ];

    public static $has_many = [
        ['pages', 'class_name' => '\TestModels\NamespaceTest\SubNamespaceTest\Page'],
        ['pages_2', 'class_name' => 'SubNamespaceTest\Page'],
    ];
}
