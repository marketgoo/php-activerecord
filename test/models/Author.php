<?php

namespace TestModels;

use ActiveRecord\Model;

class Author extends Model
{
    public static $pk = 'author_id';
    public static $has_many = ['books'];
    public static $has_one = [
        ['awesome_person', 'foreign_key' => 'author_id', 'primary_key' => 'author_id'],
        ['parent_author', 'class_name' => 'Author', 'foreign_key' => 'parent_author_id']
    ];
    public static $belongs_to = [];

    public function set_password($plaintext): void
    {
        $this->encrypted_password = md5($plaintext);
    }

    public function set_name($value): void
    {
        $value = strtoupper($value);
        $this->assign_attribute('name', $value);
    }

    public function return_something(): array
    {
        return ["sharks" => "lasers"];
    }
}
