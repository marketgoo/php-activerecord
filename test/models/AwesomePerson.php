<?php

namespace TestModels;

use ActiveRecord\Model;

class AwesomePerson extends Model
{
    static $belongs_to = ['author'];
}
