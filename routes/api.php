<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\PaymentController;

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\BusinessUserController;

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
// Route::middleware(['auth:sanctum'])->group(function () {
//     Route::get('/payments', [PaymentController::class, 'index']);
//     Route::post('/payments', [PaymentController::class, 'store']);
//     Route::get('/payments/{id}', [PaymentController::class, 'show']);
// });
