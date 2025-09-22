<?php

namespace TestModels\NamespaceTest;

use ActiveRecord\Model;

class Book extends Model
{
    static $belongs_to = array(
        array('parent_book', 'class_name' => '\TestModels\NamespaceTest\Book'),
        array('parent_book_2', 'class_name' => 'Book'),
        array('parent_book_3', 'class_name' => '\TestModels\Book'),
    );

    static $has_many = array(
        array('pages', 'class_name' => '\TestModels\NamespaceTest\SubNamespaceTest\Page'),
        array('pages_2', 'class_name' => 'SubNamespaceTest\Page'),
    );
}
