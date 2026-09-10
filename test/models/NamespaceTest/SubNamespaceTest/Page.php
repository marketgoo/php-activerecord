<?php

namespace TestModels\NamespaceTest\SubNamespaceTest;

use ActiveRecord\Model;

class Page extends Model
{
    public static $belong_to = [
        ['book', 'class_name' => '\TestModels\NamespaceTest\Book'],
    ];
}
