<?php

namespace TestModels;

use ActiveRecord\Model;

class VenueAfterCreate extends Model
{
    public static $table_name = 'venues';
    public static $after_create = ['change_name_after_create_if_name_is_change_me'];

    public function change_name_after_create_if_name_is_change_me()
    {
        if ($this->name == 'change me') {
            $this->name = 'changed!';
            $this->save();
        }
    }
}
