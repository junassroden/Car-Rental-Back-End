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
 * @since Version 4
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/**
* ------------------------------------------------------
*  Class Ember_registrar
* ------------------------------------------------------
 */
class Ember_registrar {
    public function register($engine)
    {
        $_lava = lava_instance();
        $_lava->call->helper('url');
        /** ----------------------------------------------------------
         *  GLOBALS
         * ---------------------------------------------------------- */
        $engine->add_global('app_name', config_item('app_name') ?? 'Default App');
        $engine->add_global('base_url', base_url());
        $engine->add_global('site_url', site_url());

        /** ----------------------------------------------------------
         *  FILTERS
         * ---------------------------------------------------------- */

        // String filters
        $engine->add_filter('upper', fn($v) => strtoupper($v));
        $engine->add_filter('lower', fn($v) => strtolower($v));
        $engine->add_filter('title', fn($v) => ucwords(strtolower($v)));
        $engine->add_filter('capitalize', fn($v) => ucfirst($v));
        $engine->add_filter('trim', fn($v) => trim($v));
        $engine->add_filter('reverse', fn($v) => is_array($v) ? array_reverse($v) : strrev($v));

        // HTML and escaping
        $engine->add_filter('escape', fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
        $engine->add_filter('e', fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'));
        $engine->add_filter('raw', fn($v) => $v);
        $engine->add_filter('nl2br', fn($v) => nl2br($v));

        // Array & string info
        $engine->add_filter('length', fn($v) => is_array($v) ? count($v) : strlen((string) $v));
        $engine->add_filter('default', fn($v, $d = '') => empty($v) ? $d : $v);
        $engine->add_filter('join', fn($v, $sep = ', ') => is_array($v) ? implode($sep, $v) : $v);
        $engine->add_filter('slice', fn($v, $start, $len = null) =>
            is_array($v) ? array_slice($v, $start, $len) : substr($v, $start, $len)
        );

        // Numbers and formatting
        $engine->add_filter('number_format', fn($v, $d = 0) => number_format($v, $d));
        $engine->add_filter('abs', fn($v) => abs($v));
        $engine->add_filter('round', fn($v, $p = 0) => round($v, $p));

        // Dates
        $engine->add_filter('date', fn($v, $format = 'Y-m-d H:i:s') =>
            date($format, is_numeric($v) ? $v : strtotime($v))
        );

        // JSON and replacements
        $engine->add_filter('json_encode', fn($v) => json_encode($v));
        $engine->add_filter('replace', fn($v, $map = []) => strtr($v, $map));

        // Custom extras
        $engine->add_filter('money', fn($v, $symbol = '₱') => $symbol . number_format($v, 2));
        $engine->add_filter('slug', fn($v) =>
            strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $v), '-'))
        );
        $engine->add_filter('pluralize', fn($count, $word) =>
            $count == 1 ? $word : $word . 's'
        );


        /** ----------------------------------------------------------
         *  FUNCTIONS
         * ---------------------------------------------------------- */

        // URL helpers
        $engine->add_function('url', fn($p = '') => '/' . ltrim($p, '/'));
        $engine->add_function('asset', fn($p) => base_url('public/' . ltrim($p, '/')));
        $engine->add_function('site_url', fn($p = '') => site_url(ltrim($p, '/')));
        $engine->add_function('base_url', fn($p = '') => base_url(ltrim($p, '/')));
        $engine->add_function('active', fn($a) => active($a));

        // App helpers
        $engine->add_function('config', fn($key) => config_item($key));
        $engine->add_function('session', function($key = null) {
            $LAVA = lava_instance();
            $userdata = $LAVA->session->get_userdata();

            if ($key === null) {
                return $userdata;
            }

            return $userdata[$key] ?? null;
        });

        //
        $engine->add_function('upper', fn($str) => strtoupper($str));
        $engine->add_function('repeat', fn($str, $n) => str_repeat($str, $n));

        // Date/time helpers
        $engine->add_function('now', fn() => date('Y-m-d H:i:s'));
        $engine->add_function('date', fn($format = 'Y-m-d H:i:s', $time = null) =>
            date($format, $time ?? time())
        );

        // Template includes & debug
        $engine->add_function('include', fn($tpl, $vars = []) => $engine->render($tpl, $vars));
        $engine->add_function('dump', fn($v) => '<pre>' . htmlspecialchars(print_r($v, true)) . '</pre>');

        // Security / forms
        $engine->add_function('csrf_field', fn() =>
            csrf_field()
        );
    }
}