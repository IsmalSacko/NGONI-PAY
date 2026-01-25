<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\PaymentController;

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\BusinessUserController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentCallbackController;
use App\Http\Controllers\Api\SubscriptionController;

// Auth routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/update-profile', [AuthController::class, 'updateProfile']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
// Business routes
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('businesses', BusinessController::class);
});
// Business User (Staff) routes
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/businesses/{business}/staff', [BusinessUserController::class, 'index']);
    Route::post('/businesses/{business}/staff', [BusinessUserController::class, 'store']);
    Route::put('/businesses/{business}/staff/{user}', [BusinessUserController::class, 'update']);
    Route::delete('/businesses/{business}/staff/{user}', [BusinessUserController::class, 'destroy']);
});
// Client routes
Route::middleware('auth:sanctum')->group(function () {

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

// Invoice routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/payments/{payment}/invoice', [InvoiceController::class, 'show']);
});


// Payment routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/businesses/{business}/payments', [PaymentController::class, 'index']);
    Route::post('/businesses/{business}/payments', [PaymentController::class, 'store']);
    Route::get('/businesses/{business}/payments/{payment}', [PaymentController::class, 'show']);
});

// Payment Callback (Mobile Money) - No auth
Route::post(
    '/payments/callback',
    [PaymentCallbackController::class, 'handle']
);
Route::post('/callback-test', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'message' => 'Callback route OK',
        'data' => $request->all(),
    ]);
});
