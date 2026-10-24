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

$router->get('/api/cars', 'ApiController::cars');
$router->get('/api/cars/available', 'ApiController::availableCars');
$router->get('/api/cars/{id}', 'ApiController::car');
$router->get('/api/reservations', 'ApiController::reservations');
$router->get('/api/reservations/{id}', 'ApiController::reservation');
$router->post('/api/reservations', 'ApiController::createReservation');
$router->patch('/api/reservations/{id}', 'ApiController::updateReservation');
$router->post('/api/cars', 'ApiController::createCar');
$router->put('/api/cars/{id}', 'ApiController::updateCar');
$router->patch('/api/cars/{id}', 'ApiController::updateCar');
$router->delete('/api/cars/{id}', 'ApiController::deleteCar');
$router->post('/api/cars/{id}/images', 'ApiController::uploadImages');
$router->delete('/api/cars/{id}/images/{imageId}', 'ApiController::deleteImage');
$router->post('/api/login', 'ApiController::login');
$router->post('/api/register', 'ApiController::register');
$router->post('/api/logout', 'ApiController::logout');
$router->get('/api/me', 'ApiController::me');
$router->get('/api/profile', 'ApiController::profile');
$router->post('/api/profile', 'ApiController::profile');
$router->get('/api/profile/verification-photo', 'ApiController::verificationPhoto');
$router->get('/api/maintenance', 'ApiController::maintenanceRecords');
$router->get('/api/maintenance/predictions', 'ApiController::maintenancePredictions');
$router->post('/api/maintenance/records', 'ApiController::createMaintenanceRecord');
$router->patch('/api/maintenance/records/{id}', 'ApiController::updateMaintenanceRecord');
$router->patch('/api/maintenance/mileage', 'ApiController::updateVehicleMileage');
$router->post('/api/maintenance/predictions/generate', 'ApiController::generateMaintenancePredictions');
$router->post('/api/chat', 'ApiController::chat');

$router->options('/api/cars', 'ApiController::cars');
$router->options('/api/cars/available', 'ApiController::availableCars');
$router->options('/api/cars/{id}', 'ApiController::car');
$router->options('/api/reservations', 'ApiController::reservations');
$router->options('/api/reservations/{id}', 'ApiController::reservation');
$router->options('/api/cars/{id}/images', 'ApiController::uploadImages');
$router->options('/api/cars/{id}/images/{imageId}', 'ApiController::deleteImage');
$router->options('/api/login', 'ApiController::login');
$router->options('/api/register', 'ApiController::register');
$router->options('/api/logout', 'ApiController::logout');
$router->options('/api/me', 'ApiController::me');
$router->options('/api/profile', 'ApiController::profile');
$router->options('/api/profile/verification-photo', 'ApiController::verificationPhoto');
$router->options('/api/maintenance', 'ApiController::maintenanceRecords');
$router->options('/api/maintenance/predictions', 'ApiController::maintenancePredictions');
$router->options('/api/maintenance/records', 'ApiController::createMaintenanceRecord');
$router->options('/api/maintenance/records/{id}', 'ApiController::updateMaintenanceRecord');
$router->options('/api/maintenance/mileage', 'ApiController::updateVehicleMileage');
$router->options('/api/maintenance/predictions/generate', 'ApiController::generateMaintenancePredictions');
$router->options('/api/chat', 'ApiController::chat');