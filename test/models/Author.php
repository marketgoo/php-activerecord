<?php

namespace TestModels;

use ActiveRecord\Model;

class Author extends Model
{
    static $pk = 'author_id';
//  static $has_one = array(array('awesome_person', 'foreign_key' => 'author_id', 'primary_key' => 'author_id'),
//  array('parent_author', 'class_name' => 'Author', 'foreign_key' => 'parent_author_id'));
    static $has_many = ['books'];
    static $has_one = [
        ['awesome_person', 'foreign_key' => 'author_id', 'primary_key' => 'author_id'],
        ['parent_author', 'class_name' => 'Author', 'foreign_key' => 'parent_author_id']
    ];
    static $belongs_to = [];

    public function set_password($plaintext): void
    {
        $this->encrypted_password = md5($plaintext);
    }

    public function set_name($value): void
    {
        $value = strtoupper($value);
        $this->assign_attribute('name', $value);
    }

    public function return_something(): Array
    {
        return ["sharks" => "lasers"];
    }
}
