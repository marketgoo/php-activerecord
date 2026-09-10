<?php

namespace TestModels;

use ActiveRecord\Model;

class Document extends Model
{
    // settings is a plain text column holding JSON documents
    public static $json_attributes = ['settings'];
}
