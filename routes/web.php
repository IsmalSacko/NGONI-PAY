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

// Pages publiques reprises de Ngoni Pay : la politique de confidentialité est
// celle que cite la fiche Play Store, et les annonces pointent sur /telecharger.
Route::view('privacy', 'privacy')->name('confidentialite');

Route::get('telecharger', fn () => view('telecharger', [
    'titre' => 'Ngoni Caisse — La caisse de votre commerce',
    'description' => 'Ventes, stocks, reçus et équipe depuis votre téléphone, même hors ligne.',
    'version' => config('mobile.latest_version'),
    'storeUrl' => config('mobile.store_url'),
]))->name('telecharger');

// Anciennes adresses du panneau Ngoni Pay, gardées en favoris.
Route::redirect('login', '/connexion');
Route::redirect('admin/{reste?}', '/plateforme')->where('reste', '.*');

// Site vitrine pour les visiteurs ; un compte connecté va à son espace.
Route::get('/', \App\Http\Controllers\VitrineController::class)->name('vitrine');

Route::middleware('guest')->group(function (): void {
    Route::get('connexion', Login::class)->name('connexion');
    Route::get('mot-de-passe-oublie', \App\Livewire\Auth\MotDePasseOublie::class)->name('mot-de-passe-oublie');
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

Route::middleware(['auth', 'tenant', 'backoffice'])->group(function (): void {
    Route::get('boutiques', \App\Livewire\Boutiques\Index::class)->name('boutiques.index');
    Route::get('achats', \App\Livewire\Achats\Index::class)->name('achats.index')->middleware('permission:achats.view');
    Route::get('rapports', \App\Livewire\Rapports\Index::class)->name('rapports.index')->middleware('permission:rapports.view');
    Route::get('rapports/imprimer', [\App\Http\Controllers\ExportController::class, 'imprimer'])->name('rapports.imprimer')->middleware('permission:rapports.view');
    Route::get('exports/ventes', [\App\Http\Controllers\ExportController::class, 'ventes'])->name('exports.ventes')->middleware('permission:rapports.view');
    Route::get('exports/stocks', [\App\Http\Controllers\ExportController::class, 'stocks'])->name('exports.stocks')->middleware('permission:rapports.view');
    Route::get('exports/credits', [\App\Http\Controllers\ExportController::class, 'credits'])->name('exports.credits')->middleware('permission:rapports.view');
    Route::get('tableau-de-bord', Dashboard::class)->name('tableau-de-bord')->middleware('permission:dashboard.view');

    Route::get('produits', ProduitsIndex::class)->name('produits.index')->middleware('permission:produits.view');
    Route::get('categories', CategoriesIndex::class)->name('categories.index')->middleware('permission:categories.view');
    Route::get('stocks', StocksIndex::class)->name('stocks.index')->middleware('permission:stocks.view');
    Route::get('ventes', VentesIndex::class)->name('ventes.index')->middleware('permission:ventes.view');
    Route::get('clients', ClientsIndex::class)->name('clients.index')->middleware('permission:clients.view');
    Route::get('utilisateurs', UtilisateursIndex::class)->name('utilisateurs.index')->middleware('permission:utilisateurs.view');
});

// Console de l'exploitant : comptes, abonnements, demandes, plans, utilisateurs.
Route::middleware(['auth', 'plateforme'])->prefix('plateforme')->name('plateforme.')->group(function (): void {
    Route::get('/', \App\Livewire\Plateforme\Tableau::class)->name('tableau');
    Route::get('comptes', \App\Livewire\Plateforme\Comptes::class)->name('comptes');
    Route::get('demandes', \App\Livewire\Plateforme\Demandes::class)->name('demandes');
    Route::get('plans', \App\Livewire\Plateforme\Plans::class)->name('plans');
    Route::get('utilisateurs', \App\Livewire\Plateforme\Utilisateurs::class)->name('utilisateurs');
    Route::get('annonces', \App\Livewire\Plateforme\Annonces::class)->name('annonces');

    // Preuve de paiement : stockée hors du disque public, servie à l'exploitant seul.
    Route::get('demandes/{demande}/preuve', function (\App\Models\DemandeAbonnement $demande) {
        abort_unless($demande->preuveExiste(), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($demande->preuve_chemin);
    })->name('preuve');
});
