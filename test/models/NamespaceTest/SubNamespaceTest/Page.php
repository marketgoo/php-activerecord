<?php

namespace TestModels\NamespaceTest\SubNamespaceTest;

use ActiveRecord\Model;

class Page extends Model
{
    static $belong_to = array(
        array('book', 'class_name' => '\TestModels\NamespaceTest\Book'),
    );
}
