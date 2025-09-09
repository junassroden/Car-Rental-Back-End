<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: TestController
 * 
 * Automatically generated via CLI.
 */
class TestController extends Controller {
    public function __construct()
    {
        parent::__construct();
    }

    function index($p1, $p2)
    {
        echo "This is the index method of TestController.";
        echo "<br>Parameter 1: " . $p1;
        echo "<br>Parameter 2: " . $p2;
    }
}