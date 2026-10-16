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

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Welcome::index');

$router->get('/api/cars', 'Api::cars');
$router->get('/api/cars/available', 'Api::availableCars');
$router->get('/api/cars/{id}', 'Api::car');
$router->post('/api/cars', 'Api::createCar');
$router->put('/api/cars/{id}', 'Api::updateCar');
$router->patch('/api/cars/{id}', 'Api::updateCar');
$router->delete('/api/cars/{id}', 'Api::deleteCar');
$router->post('/api/cars/{id}/images', 'Api::uploadImages');
$router->delete('/api/cars/{id}/images/{imageId}', 'Api::deleteImage');
$router->post('/api/login', 'Api::login');
$router->post('/api/register', 'Api::register');
$router->post('/api/logout', 'Api::logout');
$router->get('/api/me', 'Api::me');

$router->options('/api/cars', 'Api::cars');
$router->options('/api/cars/available', 'Api::availableCars');
$router->options('/api/cars/{id}', 'Api::car');
$router->options('/api/cars/{id}/images', 'Api::uploadImages');
$router->options('/api/cars/{id}/images/{imageId}', 'Api::deleteImage');
$router->options('/api/login', 'Api::login');
$router->options('/api/register', 'Api::register');
$router->options('/api/logout', 'Api::logout');
$router->options('/api/me', 'Api::me');