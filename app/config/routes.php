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

$router->get('/api/cars', 'CarController::cars');
$router->get('/api/cars/available', 'CarController::availableCars');
$router->get('/api/cars/{id}', 'CarController::car');
$router->get('/api/reservations', 'ReservationController::reservations');
$router->get('/api/reservations/{id}', 'ReservationController::reservation');
$router->post('/api/reservations', 'ReservationController::createReservation');
$router->patch('/api/reservations/{id}', 'ReservationController::updateReservation');
$router->post('/api/cars', 'CarController::createCar');
$router->put('/api/cars/{id}', 'CarController::updateCar');
$router->patch('/api/cars/{id}', 'CarController::updateCar');
$router->delete('/api/cars/{id}', 'CarController::deleteCar');
$router->post('/api/cars/{id}/images', 'CarController::uploadImages');
$router->delete('/api/cars/{id}/images/{imageId}', 'CarController::deleteImage');
$router->post('/api/login', 'AuthController::login');
$router->post('/api/register', 'AuthController::register');
$router->post('/api/logout', 'AuthController::logout');
$router->post('/api/refresh', 'AuthController::refresh');
$router->get('/api/me', 'AuthController::me');
$router->get('/api/profile', 'ApiController::profile');
$router->post('/api/profile', 'ApiController::profile');
$router->get('/api/profile/verification-photo', 'ApiController::verificationPhoto');
$router->get('/api/maintenance', 'MaintenanceController::maintenanceRecords');
$router->get('/api/maintenance/predictions', 'MaintenanceController::maintenancePredictions');
$router->post('/api/maintenance/records', 'MaintenanceController::createMaintenanceRecord');
$router->patch('/api/maintenance/records/{id}', 'MaintenanceController::updateMaintenanceRecord');
$router->patch('/api/maintenance/mileage', 'MaintenanceController::updateVehicleMileage');
$router->post('/api/maintenance/predictions/generate', 'MaintenanceController::generateMaintenancePredictions');
$router->post('/api/chat', 'ChatController::chat');

$router->options('/api/cars', 'CarController::cars');
$router->options('/api/cars/available', 'CarController::availableCars');
$router->options('/api/cars/{id}', 'CarController::car');
$router->options('/api/reservations', 'ReservationController::reservations');
$router->options('/api/reservations/{id}', 'ReservationController::reservation');
$router->options('/api/cars/{id}/images', 'CarController::uploadImages');
$router->options('/api/cars/{id}/images/{imageId}', 'CarController::deleteImage');
$router->options('/api/login', 'AuthController::login');
$router->options('/api/register', 'AuthController::register');
$router->options('/api/logout', 'AuthController::logout');
$router->options('/api/refresh', 'AuthController::refresh');
$router->options('/api/me', 'AuthController::me');
$router->options('/api/profile', 'ApiController::profile');
$router->options('/api/profile/verification-photo', 'ApiController::verificationPhoto');
$router->options('/api/maintenance', 'MaintenanceController::maintenanceRecords');
$router->options('/api/maintenance/predictions', 'MaintenanceController::maintenancePredictions');
$router->options('/api/maintenance/records', 'MaintenanceController::createMaintenanceRecord');
$router->options('/api/maintenance/records/{id}', 'MaintenanceController::updateMaintenanceRecord');
$router->options('/api/maintenance/mileage', 'MaintenanceController::updateVehicleMileage');
$router->options('/api/maintenance/predictions/generate', 'MaintenanceController::generateMaintenancePredictions');
$router->options('/api/chat', 'ChatController::chat');