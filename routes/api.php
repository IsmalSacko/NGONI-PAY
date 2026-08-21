<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\PaymentController;

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\BusinessUserController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentCallbackController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\Admin\AdminSubscriptionController;

// Catalogue public des pays : l'inscription et la connexion en ont besoin
// avant toute session, pour l'indicatif du numéro et la devise proposée.
Route::get('/countries', [CountryController::class, 'index']);

// Auth routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/update-profile', [AuthController::class, 'updateProfile']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::delete('/delete', [AuthController::class, 'destroy']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
// Business routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/businesses/deactivated', [BusinessController::class, 'deactivated']);
    Route::patch('/businesses/{business}/reactivate', [BusinessController::class, 'reactivate']);
    Route::apiResource('businesses', BusinessController::class);
    Route::get('/users', [AuthController::class, 'users']);
    Route::delete('/users/{user}', [AuthController::class, 'deleteUser']);
});
// Business User (Staff) routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/businesses/{business}/stats', [BusinessController::class, 'stats']);
    Route::get('/businesses/{business}/stats/daily', [BusinessController::class, 'dailyStats']);
    Route::get('/businesses/{business}/stats/weekly', [BusinessController::class, 'stats']);
    Route::get('/businesses/{business}/stats/monthly', [BusinessController::class, 'stats']);
    Route::get('/businesses/{business}/staff', [BusinessUserController::class, 'index']);
    Route::post('/businesses/{business}/staff', [BusinessUserController::class, 'store']);
    Route::put('/businesses/{business}/staff/{user}', [BusinessUserController::class, 'update']);
    Route::delete('/businesses/{business}/staff/{user}', [BusinessUserController::class, 'destroy']);
});
// Client routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/businesses/{business}/clients/find',  [ClientController::class, 'findByPhone']);
    Route::get('/businesses/{business}/clients', [ClientController::class, 'index']);
    Route::post('/businesses/{business}/clients', [ClientController::class, 'store']);
    Route::get('/businesses/{business}/clients/{client}', [ClientController::class, 'show']);
    Route::put('/businesses/{business}/clients/{client}', [ClientController::class, 'update']);
    Route::delete('/businesses/{business}/clients/{client}', [ClientController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/businesses/{business}/subscription', [SubscriptionController::class, 'show']);
    Route::post('/businesses/{business}/subscription', [SubscriptionController::class, 'store']);
    Route::put('/businesses/{business}/subscription/{subscription}', [SubscriptionController::class, 'update']);
});

// Admin routes (system_admin uniquement)
Route::middleware(['auth:sanctum', 'role:system_admin'])->prefix('admin')->group(function () {
    Route::get('/businesses', [AdminSubscriptionController::class, 'index']);
    Route::post('/businesses/{business}/subscription', [AdminSubscriptionController::class, 'grant']);
    Route::delete('/businesses/{business}/subscription', [AdminSubscriptionController::class, 'revoke']);
});

// Invoice routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/payments/{payment}/invoice', [InvoiceController::class, 'show']);
});


// Payment routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/businesses/{business}/payments', [PaymentController::class, 'index']);
    Route::post('/businesses/{business}/payments', [PaymentController::class, 'store']);
    Route::get('/businesses/{business}/payments/{payment}', [PaymentController::class, 'show']);
    Route::patch('/businesses/{business}/payments/{payment}/cancel', [PaymentController::class, 'cancel']);
});

// Payment Callback (Mobile Money) - No auth
Route::post(
    '/payments/callback',
    [PaymentCallbackController::class, 'handle']
);

// Version de l'app mobile - No auth (appelé avant/sans connexion)
Route::get('/app-version', function () {
    return response()->json([
        'latest_version' => config('mobile.latest_version'),
    ]);
});
