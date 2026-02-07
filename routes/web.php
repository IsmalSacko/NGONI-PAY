<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;

Route::prefix('api')->group(function () {
    Route::get('/', [HomeController::class, 'index']);
});

Route::view('/privacy', 'privacy');
