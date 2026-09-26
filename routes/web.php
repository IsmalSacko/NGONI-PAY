<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Livewire\Categories\Index as CategoriesIndex;
use App\Livewire\Clients\Index as ClientsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Produits\Index as ProduitsIndex;
use App\Livewire\Stocks\Index as StocksIndex;
use App\Livewire\Utilisateurs\Index as UtilisateursIndex;
use App\Livewire\Ventes\Index as VentesIndex;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tableau-de-bord');

Route::middleware('guest')->group(function (): void {
    Route::get('connexion', Login::class)->name('connexion');
});

Route::post('deconnexion', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('connexion');
})->middleware('auth')->name('deconnexion');

// Change la boutique de travail du back-office (gardée en session), si le compte
// y a un rôle. Elle devient aussi sa boutique par défaut.
Route::post('boutique-active', function () {
    $boutique = (string) request('boutique');
    $user = Auth::user();

    abort_unless($user->appartientA($boutique), 403, 'Vous n’avez pas accès à cette boutique.');

    session([\App\Support\Tenancy\BoutiqueActive::CLE_SESSION => $boutique]);
    $user->update(['boutique_id' => $boutique]);

    return redirect()->route('tableau-de-bord');
})->middleware('auth')->name('boutique-active');

Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('tableau-de-bord', Dashboard::class)->name('tableau-de-bord')->middleware('permission:dashboard.view');

    Route::get('produits', ProduitsIndex::class)->name('produits.index')->middleware('permission:produits.view');
    Route::get('categories', CategoriesIndex::class)->name('categories.index')->middleware('permission:categories.view');
    Route::get('stocks', StocksIndex::class)->name('stocks.index')->middleware('permission:stocks.view');
    Route::get('ventes', VentesIndex::class)->name('ventes.index')->middleware('permission:ventes.view');
    Route::get('clients', ClientsIndex::class)->name('clients.index')->middleware('permission:clients.view');
    Route::get('utilisateurs', UtilisateursIndex::class)->name('utilisateurs.index')->middleware('permission:utilisateurs.view');
});
