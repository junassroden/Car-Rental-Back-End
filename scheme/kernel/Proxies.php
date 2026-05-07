<?php
namespace LavaLust\Kernel\Proxies;
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
abstract class Proxy
{
    /**
     * The library name to load via lava_instance()->call->library()
     * Override in child class. Return null to skip library loading (e.g. Loader).
     */
    protected static function get_library()
    {
        return null;
    }

    /**
     * The property name on lava_instance() that holds the resolved instance.
     * Override in child class.
     */
    abstract protected static function get_property();

    /**
     * Resolve the underlying library instance.
     */
    protected static function resolve()
    {
        $library = static::get_library();

        if ($library !== null) {
            lava_instance()->call->library($library);
        }

        $property = static::get_property();
        $instance = lava_instance()->$property;

        if ($instance === null) {
            throw new \RuntimeException(
                static::class . ": Could not resolve property '{$property}' from lava_instance()."
            );
        }

        return $instance;
    }

    /**
     * Handle dynamic static method calls to the resolved instance.
     *
     * @param string $method The method being called statically.
     * @param array $args The arguments passed to the method.
     * @return mixed The result of the method call on the resolved instance.
     */
    public static function __callStatic(string $method, array $args): mixed
    {
        $instance = static::resolve();

        if (!method_exists($instance, $method)) {
            throw new \BadMethodCallException(
                static::class . "::{$method}() does not exist."
            );
        }

        return $instance->$method(...$args);
    }
}

class DB extends Proxy
{
    protected static function get_library() { return 'database'; }
    protected static function get_property() { return 'db'; }
}

class Session extends Proxy
{
    protected static function get_library() { return 'session'; }
    protected static function get_property() { return 'session'; }
}

class Api extends Proxy
{
    protected static function get_library() { return 'api'; }
    protected static function get_property() { return 'api'; }
}

class Ember extends Proxy
{
    protected static function get_library() { return 'ember'; }
    protected static function get_property() { return 'ember'; }
}

class Cache extends Proxy
{
    protected static function get_library() { return 'cache'; }
    protected static function get_property() { return 'cache'; }
}

class Email extends Proxy
{
    protected static function get_library() { return 'email'; }
    protected static function get_property() { return 'email'; }
}

class Encryption extends Proxy
{
    protected static function get_library() { return 'encryption'; }
    protected static function get_property() { return 'encryption'; }
}

class Validation extends Proxy
{
    protected static function get_library() { return 'form_validation'; }
    protected static function get_property() { return 'form_validation'; }
}

class Migration extends Proxy
{
    protected static function get_library() { return 'migration'; }
    protected static function get_property() { return 'migration'; }
}

class Pagination extends Proxy
{
    protected static function get_library() { return 'pagination'; }
    protected static function get_property() { return 'pagination'; }
}

class Profiler extends Proxy
{
    protected static function get_library() { return 'profiler'; }
    protected static function get_property() { return 'profiler'; }
}

class Upload extends Proxy
{
    protected static function get_library() { return 'upload'; }
    protected static function get_property() { return 'upload'; }
}

// Loader is special — no library to load, property is 'call'
class Loader extends Proxy
{
    protected static function get_library() { return null; }
    protected static function get_property() { return 'call'; }
}