<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ChatController extends ApiController
{
    public function chat()
    {
        $this->handleChatRequest();
    }
}
