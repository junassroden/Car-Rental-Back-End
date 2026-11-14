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

// `php lava migration run|status` reaches these through public/index.php on the command line only;
// they are never registered for web requests. Rollback actions are deliberately not exposed.
if (PHP_SAPI === 'cli') {
    $router->get('/migrate', 'MigrationController::migrate');
    $router->get('/status', 'MigrationController::status');
    // Scheduled job (`php lava reservations expire`): releases unpaid reservations whose hold ran out.
    $router->get('/reservations/expire', 'ReservationController::expirePending');
}

// JSON API. CORS preflight (OPTIONS) requests are answered by the router itself.
$router->group(['prefix' => '/api'], function ($router) {
    // Authentication
    $router->post('/login', 'AuthController::login');
    $router->post('/google-auth', 'AuthController::googleLogin');
    $router->post('/register', 'AuthController::register');
    $router->post('/logout', 'AuthController::logout');
    $router->post('/refresh', 'AuthController::refresh');
    $router->get('/me', 'AuthController::me');
    $router->post('/email/verify', 'AuthController::verifyEmail');
    $router->post('/email/resend', 'AuthController::resendVerification');
    $router->post('/password/forgot', 'AuthController::forgotPassword');
    $router->post('/password/reset', 'AuthController::resetPassword');
    // Linking Google needs a signed-in owner; it never happens automatically by matching email.
    $router->post('/account/google/link', 'AuthController::linkGoogle');
    $router->post('/account/google/unlink', 'AuthController::unlinkGoogle');

    // Customer profile
    $router->get('/profile', 'ProfileController::show');
    $router->post('/profile', 'ProfileController::update');
    $router->get('/profile/verification-photo', 'ProfileController::verificationPhoto');

    // Vehicles ("available" must come before "{id}")
    $router->get('/cars', 'CarController::cars');
    $router->get('/cars/available', 'CarController::availableCars');
    $router->get('/cars/{id}/availability', 'CarController::availability');
    $router->get('/cars/{id}', 'CarController::car');
    $router->post('/cars', 'CarController::createCar');
    $router->put('/cars/{id}', 'CarController::updateCar');
    $router->patch('/cars/{id}', 'CarController::updateCar');
    $router->delete('/cars/{id}', 'CarController::deleteCar');
    $router->post('/cars/{id}/images', 'CarController::uploadImages');
    $router->patch('/cars/{id}/images/{imageId}', 'CarController::updateImage');
    $router->delete('/cars/{id}/images/{imageId}', 'CarController::deleteImage');
    $router->post('/cars/{id}/variants', 'CarController::createVariant');
    $router->patch('/cars/{id}/variants/{variantId}', 'CarController::updateVariant');
    $router->delete('/cars/{id}/variants/{variantId}', 'CarController::deleteVariant');

    // Reservations
    $router->get('/reservations', 'ReservationController::reservations');
    $router->get('/reservations/{id}', 'ReservationController::reservation');
    $router->post('/reservations', 'ReservationController::createReservation');
    $router->patch('/reservations/{id}', 'ReservationController::updateReservation');
    $router->post('/reservations/{id}/checkout', 'ReservationController::vehicleCheckout');
    $router->post('/reservations/{id}/return', 'ReservationController::vehicleReturn');
    // Payments are confirmed only by the gateway webhooks below, never by hand or by the browser.
    $router->post('/reservations/{id}/payment', 'PaymentController::createCheckout');

    // Xendit posts invoice events (PAID, SETTLED, EXPIRED) here, authenticated by X-Callback-Token.
    $router->post('/xendit/webhook', 'PaymentController::xenditWebhook');
    // Paid attempts an administrator must act on: payment after cancellation or expiry, duplicates.
    $router->get('/payments/review', 'PaymentController::reviewQueue');
    // Maintenance (admin only)
    $router->get('/maintenance', 'MaintenanceController::maintenanceRecords');
    $router->get('/maintenance/predictions', 'MaintenanceController::maintenancePredictions');
    $router->post('/maintenance/predictions/generate', 'MaintenanceController::generateMaintenancePredictions');
    $router->post('/maintenance/records', 'MaintenanceController::createMaintenanceRecord');
    $router->patch('/maintenance/records/{id}', 'MaintenanceController::updateMaintenanceRecord');
    $router->patch('/maintenance/mileage', 'MaintenanceController::updateVehicleMileage');

    // Component-level inspection and schedules for the 3D view (active administrators only).
    $router->get('/maintenance/vehicles/{id}/components', 'ComponentMaintenanceController::components');
    $router->get('/maintenance/vehicles/{id}/components/{key}/history', 'ComponentMaintenanceController::history');
    $router->post('/maintenance/vehicles/{id}/components/{key}/inspections', 'ComponentMaintenanceController::createInspection');
    $router->put('/maintenance/vehicles/{id}/components/{key}/schedule', 'ComponentMaintenanceController::saveSchedule');
    $router->delete('/maintenance/vehicles/{id}/components/{key}/schedule', 'ComponentMaintenanceController::deleteSchedule');

    // Vehicle assessments (active administrators only). Fixed paths come before "{id}".
    $router->get('/assessments/training/overview', 'VehicleAssessmentController::trainingOverview');
    $router->get('/assessments/training/{category}/dataset', 'VehicleAssessmentController::trainingDataset');
    $router->post('/assessments/training/{category}/train', 'VehicleAssessmentController::train');
    $router->post('/assessments/training/{category}/accept', 'VehicleAssessmentController::acceptModel');
    $router->post('/assessments/training/{category}/revoke', 'VehicleAssessmentController::revokeModel');
    $router->get('/assessments/catalog', 'VehicleAssessmentController::catalog');
    $router->get('/assessments/fleet', 'VehicleAssessmentController::fleet');
    $router->get('/assessments/vehicles/{id}', 'VehicleAssessmentController::vehicle');
    $router->patch('/assessments/vehicles/{id}/profile', 'VehicleAssessmentController::updateProfile');
    $router->get('/assessments/vehicles/{id}/categories/{key}/history', 'VehicleAssessmentController::categoryHistory');
    $router->get('/assessments/evidence/{id}', 'VehicleAssessmentController::evidence');
    $router->post('/assessments/outcomes/{id}/verify', 'VehicleAssessmentController::verifyOutcome');
    $router->post('/assessments', 'VehicleAssessmentController::create');
    $router->get('/assessments/{id}', 'VehicleAssessmentController::show');
    $router->post('/assessments/{id}/analysis', 'VehicleAssessmentController::analyze');
    $router->post('/assessments/{id}/review', 'VehicleAssessmentController::review');
    $router->post('/assessments/{id}/categories/{key}/evidence', 'VehicleAssessmentController::uploadEvidence');
    $router->post('/assessments/{id}/categories/{key}/outcomes', 'VehicleAssessmentController::createOutcome');

    // AI chatbot (forwards to the Python AI service)
    $router->post('/chat', 'ChatController::chat');
});
