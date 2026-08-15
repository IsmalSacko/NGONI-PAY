<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Users\Index as AdminUsersIndex;
use App\Livewire\Admin\Businesses\Index as AdminBusinessesIndex;
use App\Livewire\Admin\Businesses\Show as AdminBusinessesShow;
use App\Livewire\Admin\Payments\Index as AdminPaymentsIndex;
use App\Livewire\Admin\Subscriptions\Index as AdminSubscriptionsIndex;
use App\Livewire\Admin\Campaigns\Index as AdminCampaignsIndex;

Route::prefix('api')->group(function () {
    Route::get('/', [HomeController::class, 'index']);
});

Route::view('/privacy', 'privacy');

// --- Panneau d'administration ---

Route::get('/', fn () => Auth::guard('web')->check()
    ? redirect()->route('admin.dashboard')
    : redirect()->route('login')
)->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'admin.only'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/users', AdminUsersIndex::class)->name('users.index');
    Route::get('/businesses', AdminBusinessesIndex::class)->name('businesses.index');
    Route::get('/businesses/{business}', AdminBusinessesShow::class)->name('businesses.show');
    Route::get('/subscriptions', AdminSubscriptionsIndex::class)->name('subscriptions.index');
    Route::get('/payments', AdminPaymentsIndex::class)->name('payments.index');
    Route::get('/campagnes', AdminCampaignsIndex::class)->name('campaigns.index');
});
