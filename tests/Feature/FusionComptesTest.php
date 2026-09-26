<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Deux comptes d'une même personne, hérités de Ngoni Pay : l'un en essai
 * expiré (PHARMACIE), l'autre en Pro (OIL). Après fusion, un seul compte,
 * les deux boutiques, et le Pro.
 */
class FusionComptesTest extends TestCase
{
    use RefreshDatabase;

    private function api(User $user)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    public function test_la_fusion_reunit_boutiques_ventes_et_meilleur_abonnement(): void
    {
        ['user' => $ismael, 'boutique' => $pharmacie] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'PHARMACIE', 'pays' => 'FR', 'telephone' => '0605758494',
            'email' => null, 'password' => 'provisoire1', 'nom_utilisateur' => 'iSMAEL SACKO',
        ]);
        ['user' => $ismo, 'boutique' => $oil] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'OIL', 'pays' => 'FR', 'telephone' => '0605758495',
            'email' => 'ismo@example.com', 'password' => 'autre-mdp', 'nom_utilisateur' => 'ISMO',
        ]);
        // Numéros hérités tels quels de Ngoni Pay.
        $ismael->forceFill(['phone' => '0605758494'])->save();
        $ismo->forceFill(['phone' => '+33605758494'])->save();
        $ismael->abonnement()->update(['fin' => now()->subMonth()->toDateString()]);
        $ismo->abonnement()->update(['plan' => 'pro', 'fin' => now()->addMonth()->toDateString()]);

        $venteOil = $this->api($ismo)->withHeader('X-Boutique', $oil->id)->postJson('/api/ventes', [
            'reference_locale' => (string) Str::uuid(),
            'lignes' => [['libelle' => 'Vidange', 'prix_unitaire' => 15000, 'quantite' => 1]],
            'moyen_paiement' => 'especes',
        ])->assertCreated()->json('id');

        $this->artisan('ecaisse:fusionner-comptes', ['garde' => $ismael->id, 'absorbe' => $ismo->id, '--force' => true])
            ->assertSuccessful();

        $ismael->refresh();
        $this->assertSame('ismo@example.com', $ismael->email);
        $this->assertSoftDeleted('users', ['id' => $ismo->id]);
        $this->assertSame($ismael->id, $oil->fresh()->proprietaire_id);
        $this->assertDatabaseHas('ventes', ['id' => $venteOil, 'user_id' => $ismael->id]);

        // Même numéro, son mot de passe : le compte gardé, avec les deux
        // boutiques, et le Pro partout.
        $jeton = $this->postJson('/api/connexion', ['telephone' => '0605758494', 'pays' => 'FR', 'password' => 'provisoire1'])
            ->assertOk()->assertJsonPath('user.id', $ismael->id)->json('token');
        $this->postJson('/api/connexion', ['telephone' => '0605758494', 'pays' => 'FR', 'password' => 'autre-mdp'])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($jeton)->getJson('/api/boutiques')->assertJsonCount(2, 'data');
        foreach ([$pharmacie, $oil] as $b) {
            $this->withToken($jeton)->withHeader('X-Boutique', $b->id)->getJson('/api/abonnement')
                ->assertJsonPath('data.plan', 'pro')->assertJsonPath('data.est_en_cours', true);
            $this->withToken($jeton)->withHeader('X-Boutique', $b->id)->getJson('/api/moi')
                ->assertJsonPath('roles', ['admin']);
        }
    }
}
