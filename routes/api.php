<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AbonnementController;
use App\Http\Controllers\Api\AchatController;
use App\Http\Controllers\Api\AppareilController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoutiqueController;
use App\Http\Controllers\Api\CategorieProduitController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ClotureController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EquipeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaysController;
use App\Http\Controllers\Api\ProduitController;
use App\Http\Controllers\Api\RapportController;
use App\Http\Controllers\Api\SessionCaisseController;
use App\Http\Controllers\Api\VenteController;
use Illuminate\Support\Facades\Route;

Route::get('pays', [PaysController::class, 'index']);

// Version de l'application. Même adresse et même format que Ngoni Pay : c'est
// ce qui annonce aux applications Ngoni Pay 1.x qu'elles doivent se mettre à
// jour (et devenir e-caisse) après la bascule.
Route::get('app-version', fn () => response()->json([
    'latest_version' => \App\Support\VersionApplication::derniere(),
    'store_url' => config('mobile.store_url'),
    'minimum_version' => config('mobile.minimum_version'),
]));
// Version publiée sur le Play Store, signalée par la CI (jeton secret) : annonce automatique.
Route::post('publication-play', \App\Http\Controllers\Api\PublicationPlayController::class)->middleware('throttle:10,1');

// Catalogue public des plans : l'écran d'abonnement s'affiche même abonnement expiré.
Route::get('plans', [AbonnementController::class, 'plans']);
Route::post('inscription', [AuthController::class, 'register']);
Route::post('connexion', [AuthController::class, 'login']);
// Limités : un code à 6 chiffres ne doit pas pouvoir être deviné en rafale.
Route::post('mot-de-passe-oublie', [AuthController::class, 'motDePasseOublie'])->middleware('throttle:reinit-demande');
Route::post('reinitialiser-mot-de-passe', [AuthController::class, 'reinitialiserMotDePasse'])->middleware('throttle:reinit-code');

// Console de l'exploitant dans l'application : hors du contexte d'une
// boutique (pas de « tenant »), elle voit tous les comptes.
Route::middleware(['auth:sanctum', 'plateforme'])->prefix('plateforme')->controller(\App\Http\Controllers\Api\PlateformeController::class)->group(function (): void {
    Route::get('tableau', 'tableau');
    Route::get('activite', 'activite');
    Route::get('demandes', 'demandes');
    Route::get('demandes/{demande}/preuve', 'preuve')->name('api.plateforme.preuve');
    Route::post('demandes/{demande}/approuver', 'approuver');
    Route::post('demandes/{demande}/refuser', 'refuser');
    Route::get('comptes', 'comptes');
    Route::post('comptes/{user}/accorder', 'accorder');
    Route::post('comptes/{user}/revoquer', 'revoquer');
    Route::get('utilisateurs', 'utilisateurs');
    Route::post('utilisateurs/{user}/basculer', 'basculer');
    Route::post('utilisateurs/{user}/mot-de-passe', 'motDePasse');
    Route::get('utilisateurs/{user}/suppression', 'apercuSuppression');
    Route::delete('utilisateurs/{user}', 'supprimerCompte');
    Route::get('annonces', 'annonces');
    Route::post('annonces', 'envoyerAnnonce');
    Route::get('annonces/modele/{type}', 'modeleAnnonce');
    Route::post('annonces/apercu', 'apercuAnnonce');
    Route::get('annonces/comptes', 'comptesAnnonce');
    Route::post('annonces/{annonce}/envoyer', 'envoyerAnnonceMaintenant');
    Route::post('annonces/{annonce}/arreter', 'arreterAnnonce');
});

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::post('deconnexion', [AuthController::class, 'logout']);
    Route::get('moi', [AuthController::class, 'me']);
    // Son propre compte, pour tout membre : profil et mot de passe (jamais le rôle).
    Route::put('moi', [\App\Http\Controllers\Api\ProfilController::class, 'update']);
    Route::put('moi/mot-de-passe', [\App\Http\Controllers\Api\ProfilController::class, 'motDePasse'])->middleware('throttle:10,1');

    Route::post('appareils', [AppareilController::class, 'store']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/tout-lu', [NotificationController::class, 'toutLu']);
    Route::post('notifications/{notification}/lue', [NotificationController::class, 'lue'])->whereNumber('notification');
    Route::delete('notifications/{notification}', [NotificationController::class, 'supprimer'])->whereNumber('notification');

    // Boutiques du compte. Toute personne connectée peut voir les siennes et en
    // créer une (elle en devient propriétaire) ; les limites viennent du plan.
    Route::get('boutiques', [BoutiqueController::class, 'index']);
    Route::post('boutiques', [BoutiqueController::class, 'store']);
    Route::put('boutique', [BoutiqueController::class, 'update'])->middleware('permission:boutique.update');
    Route::post('boutique/logo', [BoutiqueController::class, 'logo'])->middleware('permission:boutique.update');
    Route::delete('boutique/logo', [BoutiqueController::class, 'supprimerLogo'])->middleware('permission:boutique.update');
    Route::put('boutiques/{boutique}/par-defaut', [BoutiqueController::class, 'parDefaut']);

    // Abonnement du propriétaire de la boutique active. Consultable par tous ;
    // les demandes engagent le propriétaire, donc réservées à l'admin.
    Route::get('abonnement', [AbonnementController::class, 'show']);
    Route::get('abonnement/demandes', [AbonnementController::class, 'demandes'])->middleware('permission:abonnement.manage');
    Route::post('abonnement/demandes', [AbonnementController::class, 'demander'])->middleware('permission:abonnement.manage');
    Route::delete('abonnement/demandes/{demande}', [AbonnementController::class, 'annuler'])->middleware('permission:abonnement.manage');
    // Code du propriétaire, ses filleuls et ce qu'ils lui ont rapporté.
    Route::get('parrainage', fn (\Illuminate\Http\Request $request) => response()->json(['data' => app(\App\Services\Parrainage::class)->resume($request->user())]))
        ->middleware('permission:abonnement.manage');

    Route::get('dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.view');
    Route::get('rapports', RapportController::class)->middleware('permission:rapports.view');
    Route::get('statistiques', \App\Http\Controllers\Api\StatistiqueController::class)->middleware('permission:rapports.view');
    Route::get('journee', [ClotureController::class, 'journee']);
    Route::get('clotures', [ClotureController::class, 'index'])->middleware('permission:rapports.view');
    Route::post('clotures', [ClotureController::class, 'store'])->middleware('permission:rapports.view');

    Route::get('categories', [CategorieProduitController::class, 'index'])->middleware('permission:categories.view');
    Route::post('categories', [CategorieProduitController::class, 'store'])->middleware(['permission:categories.create', 'abonnement']);
    Route::put('categories/{categorie}', [CategorieProduitController::class, 'update'])->middleware(['permission:categories.update', 'abonnement']);
    Route::delete('categories/{categorie}', [CategorieProduitController::class, 'destroy'])->middleware(['permission:categories.delete', 'abonnement']);

    Route::get('produits', [ProduitController::class, 'index'])->middleware('permission:produits.view');
    Route::post('produits', [ProduitController::class, 'store'])->middleware(['permission:produits.create', 'abonnement']);
    Route::put('produits/{produit}', [ProduitController::class, 'update'])->middleware(['permission:produits.update', 'abonnement']);
    Route::post('produits/{produit}/ajuster-stock', [ProduitController::class, 'ajusterStock'])->middleware(['permission:stocks.update', 'abonnement']);
    Route::post('produits/{produit}/photo', [ProduitController::class, 'photo'])->middleware(['permission:produits.update', 'abonnement']);
    Route::delete('produits/{produit}/photo', [ProduitController::class, 'supprimerPhoto'])->middleware('permission:produits.update');
    Route::delete('produits/{produit}', [ProduitController::class, 'destroy'])->middleware(['permission:produits.delete', 'abonnement']);

    Route::get('clients', [ClientController::class, 'index'])->middleware('permission:clients.view');
    Route::post('clients', [ClientController::class, 'store'])->middleware(['permission:clients.create', 'abonnement']);
    Route::put('clients/{client}', [ClientController::class, 'update'])->middleware(['permission:clients.update', 'abonnement']);
    Route::get('clients/{client}/credit', [ClientController::class, 'credit'])->middleware('permission:clients.view');
    Route::post('clients/{client}/reglements', [ClientController::class, 'reglement'])->middleware(['permission:ventes.create', 'abonnement:caisse']);
    Route::delete('clients/{client}', [ClientController::class, 'destroy'])->middleware(['permission:clients.delete', 'abonnement']);

    Route::get('equipe', [EquipeController::class, 'index'])->middleware('permission:utilisateurs.view');
    Route::post('equipe', [EquipeController::class, 'store'])->middleware(['permission:utilisateurs.create', 'abonnement']);
    Route::put('equipe/{membre}', [EquipeController::class, 'update'])->middleware(['permission:utilisateurs.update', 'abonnement']);
    Route::delete('equipe/{membre}', [EquipeController::class, 'destroy'])->middleware('permission:utilisateurs.delete');

    Route::get('fournisseurs', [AchatController::class, 'fournisseurs'])->middleware('permission:achats.view');
    Route::post('fournisseurs', [AchatController::class, 'creerFournisseur'])->middleware(['permission:achats.create', 'abonnement']);
    Route::post('fournisseurs/{fournisseur}/paiements', [AchatController::class, 'payer'])->middleware(['permission:achats.create', 'abonnement']);
    Route::get('achats', [AchatController::class, 'index'])->middleware('permission:achats.view');
    Route::post('achats', [AchatController::class, 'store'])->middleware(['permission:achats.create', 'abonnement']);

    Route::get('ventes', [VenteController::class, 'index'])->middleware('permission:ventes.view');
    Route::get('ventes/{vente}', [VenteController::class, 'show'])->middleware('permission:ventes.view');
    Route::post('ventes/{vente}/annuler', [VenteController::class, 'annuler'])->middleware('permission:ventes.delete');
    Route::post('ventes', [VenteController::class, 'store'])->middleware(['permission:ventes.create', 'abonnement:caisse'])->name('ventes.store');

    Route::get('sessions-caisse', [SessionCaisseController::class, 'index'])->middleware('permission:sessions_caisse.view');
    Route::get('sessions-caisse/courante', [SessionCaisseController::class, 'courante'])->middleware('permission:sessions_caisse.view');
    Route::get('sessions-caisse/suggestion-ouverture', [SessionCaisseController::class, 'suggestionOuverture'])->middleware('permission:sessions_caisse.view');
    Route::post('sessions-caisse', [SessionCaisseController::class, 'ouvrir'])->middleware(['permission:sessions_caisse.create', 'abonnement']);
    Route::put('sessions-caisse/{session}/fermer', [SessionCaisseController::class, 'fermer'])->middleware('permission:sessions_caisse.update');
});
