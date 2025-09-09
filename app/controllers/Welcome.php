<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Welcome extends Controller {
	public function index() {
		$this->call->model('test/test/TestModel');
		echo $this->TestModel->getData();
		echo "<hr>";
		$this->call->controller('test/TestController', 'index', ['Hello', 'World']);
		$this->call->view('test/welcome_page');
	}
}
?>