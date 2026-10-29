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

// JSON API. CORS preflight (OPTIONS) requests are answered by the router itself.
$router->group(['prefix' => '/api'], function ($router) {
    // Authentication
    $router->post('/login', 'AuthController::login');
    $router->post('/register', 'AuthController::register');
    $router->post('/logout', 'AuthController::logout');
    $router->post('/refresh', 'AuthController::refresh');
    $router->get('/me', 'AuthController::me');

    // Customer profile
    $router->get('/profile', 'ProfileController::show');
    $router->post('/profile', 'ProfileController::update');
    $router->get('/profile/verification-photo', 'ProfileController::verificationPhoto');

    // Vehicles ("available" must come before "{id}")
    $router->get('/cars', 'CarController::cars');
    $router->get('/cars/available', 'CarController::availableCars');
    $router->get('/cars/{id}', 'CarController::car');
    $router->post('/cars', 'CarController::createCar');
    $router->put('/cars/{id}', 'CarController::updateCar');
    $router->patch('/cars/{id}', 'CarController::updateCar');
    $router->delete('/cars/{id}', 'CarController::deleteCar');
    $router->post('/cars/{id}/images', 'CarController::uploadImages');
    $router->delete('/cars/{id}/images/{imageId}', 'CarController::deleteImage');

    // Reservations
    $router->get('/reservations', 'ReservationController::reservations');
    $router->get('/reservations/{id}', 'ReservationController::reservation');
    $router->post('/reservations', 'ReservationController::createReservation');
    $router->patch('/reservations/{id}', 'ReservationController::updateReservation');

    // Maintenance (admin only)
    $router->get('/maintenance', 'MaintenanceController::maintenanceRecords');
    $router->get('/maintenance/predictions', 'MaintenanceController::maintenancePredictions');
    $router->post('/maintenance/predictions/generate', 'MaintenanceController::generateMaintenancePredictions');
    $router->post('/maintenance/records', 'MaintenanceController::createMaintenanceRecord');
    $router->patch('/maintenance/records/{id}', 'MaintenanceController::updateMaintenanceRecord');
    $router->patch('/maintenance/mileage', 'MaintenanceController::updateVehicleMileage');

    // Evidence-backed maintenance data (active administrators only).
    $router->get('/maintenance/data-records/export', 'MaintenanceDataController::export');
    $router->get('/maintenance/data-records', 'MaintenanceDataController::records');
    $router->post('/maintenance/data-records', 'MaintenanceDataController::create');
    $router->get('/maintenance/data-records/{id}', 'MaintenanceDataController::detail');
    $router->patch('/maintenance/data-records/{id}', 'MaintenanceDataController::update');
    $router->post('/maintenance/data-records/{id}/submit', 'MaintenanceDataController::submit');
    $router->post('/maintenance/data-records/{id}/verify', 'MaintenanceDataController::verify');
    $router->post('/maintenance/data-records/{id}/supersede', 'MaintenanceDataController::supersede');

    // Component-level inspection and schedules for the 3D view (active administrators only).
    $router->get('/maintenance/vehicles/{id}/components', 'ComponentMaintenanceController::components');
    $router->get('/maintenance/vehicles/{id}/components/{key}/history', 'ComponentMaintenanceController::history');
    $router->post('/maintenance/vehicles/{id}/components/{key}/inspections', 'ComponentMaintenanceController::createInspection');
    $router->put('/maintenance/vehicles/{id}/components/{key}/schedule', 'ComponentMaintenanceController::saveSchedule');
    $router->delete('/maintenance/vehicles/{id}/components/{key}/schedule', 'ComponentMaintenanceController::deleteSchedule');

    // AI chatbot (forwards to the Python AI service)
    $router->post('/chat', 'ChatController::chat');
});
