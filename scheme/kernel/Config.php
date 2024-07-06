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
*  Class Config
* ------------------------------------------------------
 */
class Config {

    /**
     * Array of configurations
     *
     * @var array
     */
    private $config = [];


    /**
     * Get configuration value
     *
     * @param string $key
     * @param string $source
     * @return void
     */
    public function get($key, $source = 'config'){
        return $this->_get($key, $source);
    }

    /**
     * Set Configuration value
     *
     * @param string $key
     * @param mixed $value
     * @param string $source
     * @return void
     */
    public function set($key, $value, $source){
        $this->_set($key, $value, $source = 'config');
    }

    /**
     * Get configuration value
     *
     * @param string $conf_key
     * @param string $source
     * @return void
     */
    private function _get($conf_key, $source)
    {
        static $config;

        if (empty($this->config)) {

            $config_file = APP_DIR . 'config/' . $source . '.php';       

            if (!file_exists($config_file)) {
                throw new Exception("Configuration file " . $source . " doesn't exist");
            }

            require $config_file;           
            
            $this->config = $config;

            if ( isset($config) OR is_array($config) )
			{
				foreach( $config as $key => $val )
				{
					$config[$key] = $val;
				}
				return $config[$conf_key];
			}   
        }

        return $this->config[$conf_key];
    }

    /**
     * Set default or new config value
     *
     * @param string $key
     * @param string $value
     * @param string $source
     * @return void
     */
    private function _set($conf_key, $value, $source)
    {
        if (empty($this->config))
        {
            $this->_get($conf_key, $source);
        }

        if($conf_key && $source)
        {
            $this->config[$conf_key] = $value;
        }
    }
}
