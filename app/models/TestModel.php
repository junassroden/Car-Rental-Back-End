<?php

class TestModel extends Model {
    public function __construct()
    {
        parent::__construct();
    }

    public function getData()
    {
        return "Data from TestModel";
    }
}