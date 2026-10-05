<?php

declare(strict_types=1);

use App\Enums\StatutDemande;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\VitrineController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\MotDePasseOublie;
use App\Livewire\Boutiques\Index;
use App\Livewire\Categories\Index as CategoriesIndex;
use App\Livewire\Clients\Index as ClientsIndex;
use App\Livewire\Dashboard;
use App\Livewire\MonCompte;
use App\Livewire\Plateforme\Annonces;
use App\Livewire\Plateforme\Comptes;
use App\Livewire\Plateforme\Demandes;
use App\Livewire\Plateforme\Plans;
use App\Livewire\Plateforme\Tableau;
use App\Livewire\Plateforme\Utilisateurs;
use App\Livewire\Produits\Index as ProduitsIndex;
use App\Livewire\Stocks\Index as StocksIndex;
use App\Livewire\Utilisateurs\Index as UtilisateursIndex;
use App\Livewire\Ventes\Index as VentesIndex;
use App\Models\DemandeAbonnement;
use App\Models\Plan;
use App\Services\ConditionsUtilisation;
use App\Services\PaiementJeko;
use App\Support\Tenancy\BoutiqueActive;
use App\Support\VersionApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Pages publiques reprises de Ngoni Pay : la politique de confidentialité est
// celle que cite la fiche Play Store, et les annonces pointent sur /telecharger.
// Pages juridiques (resources/views/juridique) : la version est dans config/conditions.php.
// /privacy reste l'adresse donnée au Play Store ; elle montre la même page.
Route::view('confidentialite', 'juridique.confidentialite')->name('confidentialite');
Route::view('privacy', 'juridique.confidentialite');
Route::view('conditions', 'juridique.conditions')->name('conditions');
Route::view('mentions-legales', 'juridique.mentions')->name('mentions-legales');
// Texte exact d'une version déjà acceptée (preuve en cas de litige).
Route::get('conditions/archives/{version}/{document}', function (string $version, string $document) {
    abort_unless(in_array($document, ['conditions', 'confidentialite'], true), 404);
    $texte = DB::table('versions_conditions')->where('version', $version)->value($document);
    abort_if($texte === null, 404);

    return response($texte)->header('X-Robots-Tag', 'noindex');
})->where('version', '[0-9.\-]+')->name('conditions.archive');
// Back-office : accepter la nouvelle version avant de continuer.
Route::middleware('auth')->group(function (): void {
    Route::get('conditions/accepter', fn () => view('juridique.accepter'))->name('conditions.accepter');
    Route::post('conditions/accepter', function (Request $request) {
        $request->validate(['conditions_acceptees' => ['accepted']], ['conditions_acceptees.accepted' => 'Cochez la case pour accepter les conditions et continuer.']);
        app(ConditionsUtilisation::class)->accepter($request->user(), $request, 'web');

        return redirect()->intended('/');
    });
});
// Adresse déclarée à Google Play pour la suppression du compte.
Route::redirect('suppression-compte', '/confidentialite#suppression-compte')->name('suppression-compte');

Route::get('telecharger', fn () => view('telecharger', [
    'titre' => 'Ngoni Caisse — La caisse de votre commerce',
    'description' => 'Ventes, stocks, reçus et équipe depuis votre téléphone, même hors ligne.',
    'version' => VersionApplication::derniere(),
    'storeUrl' => config('mobile.store_url'),
]))->name('telecharger');

// Référencement automatique : plan du site et consignes aux robots.
// Retour du commerçant après son paiement Mobile Money (Jèko) : le paiement
// est relu chez Jèko, l'abonnement s'active s'il est payé.
Route::get('paiement-abonnement', function (Request $request) {
    $reference = (string) $request->query('reference', '');
    $demande = str_starts_with($reference, 'NGONI-ABO-')
        ? DemandeAbonnement::find((int) substr($reference, strlen('NGONI-ABO-'))) : null;
    if ($demande !== null) {
        $demande = app(PaiementJeko::class)->verifier($demande);
    }
    $abonnement = $demande?->proprietaire?->abonnement()->first();

    return view('paiement-abonnement', [
        'etat' => match ($demande?->statut) {
            StatutDemande::Approuvee => 'paye',
            StatutDemande::Annulee, StatutDemande::Refusee => 'echec',
            default => $request->query('issue') === 'echec' ? 'echec' : 'attente',
        },
        'plan' => Plan::parCode((string) $demande?->plan)?->nom ?? '',
        'fin' => $abonnement?->fin?->format('d/m/Y'),
    ]);
})->middleware('throttle:30,1')->name('paiement-abonnement');

Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [SeoController::class, 'robots'])->name('robots');

// Logo et photos d'articles, sans session (tickets, application).
Route::get('images/logos/{boutique}', [ImageController::class, 'logo'])->name('image.logo');
Route::get('images/logos/{boutique}/vignette', [ImageController::class, 'logoVignette'])->name('image.logo.vignette');
Route::get('images/produits/{produit}', [ImageController::class, 'photo'])->name('image.produit');
Route::get('images/produits/{produit}/vignette', [ImageController::class, 'photoVignette'])->name('image.produit.vignette');

// Anciennes adresses du panneau Ngoni Pay, gardées en favoris.
Route::redirect('login', '/connexion');
Route::redirect('admin/{reste?}', '/plateforme')->where('reste', '.*');

// Site vitrine pour les visiteurs ; un compte connecté va à son espace.
Route::get('/', VitrineController::class)->name('vitrine');

// Page de départ du site installé (manifeste) : la connexion, ou l'espace du
// compte — jamais la vitrine, qui s'adresse aux visiteurs.
Route::get('espace', function () {
    $user = Auth::user();

    return redirect($user === null ? route('connexion') : ($user->est_admin_plateforme ? '/plateforme' : route('tableau-de-bord')));
})->name('espace');

Route::middleware('guest')->group(function (): void {
    Route::get('connexion', Login::class)->name('connexion');
    Route::get('mot-de-passe-oublie', MotDePasseOublie::class)->name('mot-de-passe-oublie');
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

    session([BoutiqueActive::CLE_SESSION => $boutique]);
    $user->update(['boutique_id' => $boutique]);

    return redirect()->route('tableau-de-bord');
})->middleware('auth')->name('boutique-active');

Route::middleware(['auth', 'conditions', 'tenant', 'backoffice'])->group(function (): void {
    Route::get('boutiques', Index::class)->name('boutiques.index');
    Route::get('achats', App\Livewire\Achats\Index::class)->name('achats.index')->middleware(['permission:achats.view', 'fonctionnalite:achats_fournisseurs']);
    Route::get('rapports', App\Livewire\Rapports\Index::class)->name('rapports.index')->middleware('permission:rapports.view');
    Route::get('statistiques', App\Livewire\Statistiques\Index::class)->name('statistiques.index')->middleware('permission:rapports.view');
    Route::get('rapports/imprimer', [ExportController::class, 'imprimer'])->name('rapports.imprimer')->middleware('permission:rapports.view');
    Route::get('exports/ventes', [ExportController::class, 'ventes'])->name('exports.ventes')->middleware(['permission:rapports.view', 'fonctionnalite:factures_exports']);
    Route::get('exports/stocks', [ExportController::class, 'stocks'])->name('exports.stocks')->middleware(['permission:rapports.view', 'fonctionnalite:factures_exports']);
    Route::get('exports/credits', [ExportController::class, 'credits'])->name('exports.credits')->middleware(['permission:rapports.view', 'fonctionnalite:factures_exports']);
    // Accueil du back-office : sans le droit de voir le chiffre d'affaires,
    // Dashboard::mount renvoie à la première page permise (pas de 403).
    Route::get('tableau-de-bord', Dashboard::class)->name('tableau-de-bord');

    Route::get('produits', ProduitsIndex::class)->name('produits.index')->middleware('permission:produits.view');
    Route::get('categories', CategoriesIndex::class)->name('categories.index')->middleware('permission:categories.view');
    Route::get('stocks', StocksIndex::class)->name('stocks.index')->middleware('permission:stocks.view');
    Route::get('ventes', VentesIndex::class)->name('ventes.index')->middleware('permission:ventes.view');
    Route::get('clients', ClientsIndex::class)->name('clients.index')->middleware('permission:clients.view');
    Route::get('utilisateurs', UtilisateursIndex::class)->name('utilisateurs.index')->middleware('permission:utilisateurs.view');
});

// Son propre compte (nom, téléphone, e-mail, mot de passe) : commerçant comme exploitant.
Route::get('mon-compte', MonCompte::class)->middleware(['auth', 'tenant'])->name('mon-compte');

// Console de l'exploitant : comptes, abonnements, demandes, plans, utilisateurs.
Route::middleware(['auth', 'conditions', 'plateforme'])->prefix('plateforme')->name('plateforme.')->group(function (): void {
    Route::get('/', Tableau::class)->name('tableau');
    Route::get('comptes', Comptes::class)->name('comptes');
    Route::get('demandes', Demandes::class)->name('demandes');
    Route::get('plans', Plans::class)->name('plans');
    Route::get('utilisateurs', Utilisateurs::class)->name('utilisateurs');
    Route::get('annonces', Annonces::class)->name('annonces');

    // Preuve de paiement : stockée hors du disque public, servie à l'exploitant seul.
    Route::get('demandes/{demande}/preuve', function (DemandeAbonnement $demande) {
        abort_unless($demande->preuveExiste(), 404);

        return Storage::disk('local')->response($demande->preuve_chemin);
    })->name('preuve');
});
