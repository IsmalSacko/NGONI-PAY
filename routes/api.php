<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AbonnementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoutiqueController;
use App\Http\Controllers\Api\CategorieProduitController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PaysController;
use App\Http\Controllers\Api\ProduitController;
use App\Http\Controllers\Api\SessionCaisseController;
use App\Http\Controllers\Api\VenteController;
use Illuminate\Support\Facades\Route;

Route::get('pays', [PaysController::class, 'index']);
// Catalogue public des plans : l'écran d'abonnement s'affiche même abonnement expiré.
Route::get('plans', [AbonnementController::class, 'plans']);
Route::post('inscription', [AuthController::class, 'register']);
Route::post('connexion', [AuthController::class, 'login']);
// Limités : un code à 6 chiffres ne doit pas pouvoir être deviné en rafale.
Route::post('mot-de-passe-oublie', [AuthController::class, 'motDePasseOublie'])->middleware('throttle:reinit-demande');
Route::post('reinitialiser-mot-de-passe', [AuthController::class, 'reinitialiserMotDePasse'])->middleware('throttle:reinit-code');

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::post('deconnexion', [AuthController::class, 'logout']);
    Route::get('moi', [AuthController::class, 'me']);

    // Boutiques du compte. Toute personne connectée peut voir les siennes et en
    // créer une (elle en devient propriétaire) ; les limites viennent du plan.
    Route::get('boutiques', [BoutiqueController::class, 'index']);
    Route::post('boutiques', [BoutiqueController::class, 'store']);
    Route::put('boutiques/{boutique}/par-defaut', [BoutiqueController::class, 'parDefaut']);

    // Abonnement du propriétaire de la boutique active. Consultable par tous ;
    // les demandes engagent le propriétaire, donc réservées à l'admin.
    Route::get('abonnement', [AbonnementController::class, 'show']);
    Route::get('abonnement/demandes', [AbonnementController::class, 'demandes'])->middleware('permission:abonnement.manage');
    Route::post('abonnement/demandes', [AbonnementController::class, 'demander'])->middleware('permission:abonnement.manage');
    Route::delete('abonnement/demandes/{demande}', [AbonnementController::class, 'annuler'])->middleware('permission:abonnement.manage');

    Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view');

    Route::get('categories', [CategorieProduitController::class, 'index'])->middleware('permission:categories.view');
    Route::post('categories', [CategorieProduitController::class, 'store'])->middleware(['permission:categories.create', 'abonnement']);
    Route::put('categories/{categorie}', [CategorieProduitController::class, 'update'])->middleware(['permission:categories.update', 'abonnement']);
    Route::delete('categories/{categorie}', [CategorieProduitController::class, 'destroy'])->middleware(['permission:categories.delete', 'abonnement']);

    Route::get('produits', [ProduitController::class, 'index'])->middleware('permission:produits.view');
    Route::post('produits', [ProduitController::class, 'store'])->middleware(['permission:produits.create', 'abonnement']);
    Route::put('produits/{produit}', [ProduitController::class, 'update'])->middleware(['permission:produits.update', 'abonnement']);
    Route::post('produits/{produit}/ajuster-stock', [ProduitController::class, 'ajusterStock'])->middleware(['permission:stocks.update', 'abonnement']);
    Route::delete('produits/{produit}', [ProduitController::class, 'destroy'])->middleware(['permission:produits.delete', 'abonnement']);

    Route::get('clients', [ClientController::class, 'index'])->middleware('permission:clients.view');
    Route::post('clients', [ClientController::class, 'store'])->middleware(['permission:clients.create', 'abonnement']);
    Route::put('clients/{client}', [ClientController::class, 'update'])->middleware(['permission:clients.update', 'abonnement']);
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->middleware(['permission:clients.delete', 'abonnement']);

    Route::get('ventes', [VenteController::class, 'index'])->middleware('permission:ventes.view');
    Route::get('ventes/{vente}', [VenteController::class, 'show'])->middleware('permission:ventes.view');
    Route::post('ventes', [VenteController::class, 'store'])->middleware(['permission:ventes.create', 'abonnement'])->name('ventes.store');

    Route::get('sessions-caisse', [SessionCaisseController::class, 'index'])->middleware('permission:sessions_caisse.view');
    Route::get('sessions-caisse/courante', [SessionCaisseController::class, 'courante'])->middleware('permission:sessions_caisse.view');
    Route::post('sessions-caisse', [SessionCaisseController::class, 'ouvrir'])->middleware(['permission:sessions_caisse.create', 'abonnement']);
    Route::put('sessions-caisse/{session}/fermer', [SessionCaisseController::class, 'fermer'])->middleware('permission:sessions_caisse.update');
});
