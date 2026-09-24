<?php

declare(strict_types=1);

use App\Http\Controllers\PaiementRetourController;
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

// Page où le fournisseur de paiement renvoie le client (publique, sans donnée sensible).
Route::get('paiements/retour/{paiement}', PaiementRetourController::class)->middleware('throttle:60,1')->name('paiements.retour');

Route::middleware('guest')->group(function (): void {
    Route::get('connexion', Login::class)->name('connexion');
});

Route::post('deconnexion', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('connexion');
})->middleware('auth')->name('deconnexion');

Route::middleware(['auth', 'tenant'])->group(function (): void {
    Route::get('tableau-de-bord', Dashboard::class)->name('tableau-de-bord')->middleware('permission:dashboard.view');

    Route::get('produits', ProduitsIndex::class)->name('produits.index')->middleware('permission:produits.view');
    Route::get('categories', CategoriesIndex::class)->name('categories.index')->middleware('permission:categories.view');
    Route::get('stocks', StocksIndex::class)->name('stocks.index')->middleware('permission:stocks.view');
    Route::get('ventes', VentesIndex::class)->name('ventes.index')->middleware('permission:ventes.view');
    Route::get('clients', ClientsIndex::class)->name('clients.index')->middleware('permission:clients.view');
    Route::get('utilisateurs', UtilisateursIndex::class)->name('utilisateurs.index')->middleware('permission:utilisateurs.view');
});
