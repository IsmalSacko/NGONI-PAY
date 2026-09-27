<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Services\NettoyageOrphelins;
use App\Services\VenteService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NettoyageOrphelinsTest extends TestCase
{
    use RefreshDatabase;

    public function test_apercu_puis_nettoyage_des_restes_sans_toucher_aux_boutiques_saines(): void
    {
        Mail::fake();
        Storage::fake('local');
        $inscrire = fn (string $nom, string $tel) => app(BoutiqueRegistrationService::class)->register([
            'nom' => $nom, 'pays' => 'ML', 'telephone' => $tel, 'email' => null, 'password' => 'password123', 'nom_utilisateur' => $nom,
        ]);
        ['boutique' => $saine] = $inscrire('Saine', '76008201');
        ['user' => $aicha, 'boutique' => $perdue] = $inscrire('Perdue', '76008202');

        // Aicha travaille aussi dans la boutique saine.
        app(TenantContext::class)->setBoutique($saine->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($saine->id);
        $aicha->assignRole('caissier');

        app(TenantContext::class)->setBoutique($perdue->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($perdue->id);
        $produit = Produit::first();
        app(VenteService::class)->encaisser([
            'reference_locale' => (string) Str::uuid(), 'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'moyen_paiement' => 'especes', 'montant_recu' => $produit->prix_vente, 'vendue_hors_ligne' => false,
        ], $aicha);

        // La boutique disparaît sans ses données, comme en production.
        $fantome = (string) Str::uuid();
        DB::statement('PRAGMA defer_foreign_keys = ON');
        foreach (['users', 'ventes', 'produits', 'clients', 'categories_produits', 'mouvements_stock', 'sessions_caisse', 'roles', 'model_has_roles'] as $t) {
            DB::table($t)->where('boutique_id', $perdue->id)->update(['boutique_id' => $fantome]);
        }
        DB::table('boutiques')->where('id', $perdue->id)->delete();

        $nettoyage = app(NettoyageOrphelins::class);
        $this->assertSame([$fantome], $nettoyage->fantomes());
        $apercu = $nettoyage->apercu()[0];
        $this->assertSame(1, $apercu['compte']['ventes']);
        $this->assertSame(2, $apercu['compte']['produits']);
        $this->assertSame(['Perdue · +22376008202'], $apercu['comptes']);

        $this->artisan('ecaisse:nettoyer-orphelins')->expectsOutputToContain('Aperçu seulement')->assertSuccessful();
        $this->assertSame(1, DB::table('ventes')->where('boutique_id', $fantome)->count(), 'l’aperçu ne touche à rien');

        $this->artisan('ecaisse:nettoyer-orphelins', ['--confirmer' => true])->expectsOutputToContain('Sauvegarde')->assertSuccessful();

        $this->assertSame([], $nettoyage->fantomes());
        foreach (['ventes', 'produits', 'clients', 'categories_produits', 'roles', 'model_has_roles'] as $t) {
            $this->assertSame(0, DB::table($t)->where('boutique_id', $fantome)->count(), "$t nettoyée");
        }
        // Aicha est conservée, rattachée à la boutique saine ; celle-ci est intacte.
        $this->assertSame($saine->id, User::find($aicha->id)->boutique_id);
        $this->assertSame(2, DB::table('produits')->where('boutique_id', $saine->id)->count());
        $this->assertCount(1, Storage::disk('local')->files('suppressions'));
    }
}
