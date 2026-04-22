<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/**
 * ------------------------------------------------------
 *  Class Response
 * ------------------------------------------------------
 */
class Response
{
    /**
     * HTTP Status Code
     *
     * @var int
     */
    private $status_code;

    /**
     * Response Headers
     *
     * @var array
     */
    private $headers = [];

    /**
     * Response Content
     *
     * @var mixed
     */
    private $content;

    /**
     * Set HTTP Status Code
     *
     * @param  int $status_code
     * @return void
     */
    public function set_status_code($status_code)
    {
        $this->status_code = $status_code;
    }

    /**
     * Add Response Header(s)
     * Accepts a single name/value pair or an associative array of headers.
     *
     * @param  string|array $name
     * @param  string       $value
     * @return void
     */
    public function add_header($name, $value = '')
    {
        if (is_array($name))
        {
            foreach ($name as $key => $val)
            {
                $this->headers[$key] = $val;
            }
        }
        else
        {
            $this->headers[$name] = $value;
        }
    }

    /**
     * Set Response Content
     *
     * @param  mixed $content
     * @return void
     */
    public function set_content($content)
    {
        $this->content = $content;
    }

    /**
     * Set HTML Response Content
     * Automatically adds Content-Type: text/html header.
     *
     * @param  mixed $content
     * @return void
     */
    public function set_html_content($content)
    {
        $this->add_header('Content-Type', 'text/html');
        $this->set_content($content);
    }

    /**
     * Send Response
     * Flushes the status code, all queued headers, and the content body.
     *
     * @return void
     */
    public function send()
    {
        http_response_code($this->status_code);

        foreach ($this->headers as $name => $value)
        {
            header("$name: $value");
        }

        echo $this->content;
    }

    /**
     * Send JSON Response
     * Sets Content-Type: application/json, encodes data, and sends.
     *
     * @param  mixed $data
     * @return void
     */
    public function send_json($data)
    {
        $this->add_header('Content-Type', 'application/json');
        $this->set_content(json_encode($data));
        $this->send();
    }
}