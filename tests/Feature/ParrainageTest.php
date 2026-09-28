<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CycleFacturation;
use App\Models\Boutique;
use App\Models\NotificationApp;
use App\Models\User;
use App\Services\AbonnementService;
use App\Services\BoutiqueRegistrationService;
use App\Services\Parrainage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Le filleul gagne une semaine d'essai à l'inscription ; le parrain gagne un
 * mois quand le filleul paie — jamais avant, jamais deux fois.
 */
class ParrainageTest extends TestCase
{
    use RefreshDatabase;

    private User $awa;

    private Boutique $boutique;

    private User $exploitant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        ['user' => $this->awa, 'boutique' => $this->boutique] = $this->inscrire('Awa', '76008201');
        $this->exploitant = User::create(['name' => 'Exploitant', 'phone' => '+33605758494', 'password' => 'x']);
    }

    /** @return array{user: User, boutique: Boutique} */
    private function inscrire(string $nom, string $telephone, ?string $code = null): array
    {
        return app(BoutiqueRegistrationService::class)->register([
            'nom' => "Boutique {$nom}", 'pays' => 'ML', 'telephone' => $telephone, 'email' => null,
            'password' => 'password123', 'nom_utilisateur' => $nom, 'code_parrainage' => $code,
        ]);
    }

    private function api(User $user)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    private function codeAwa(): string
    {
        return $this->api($this->awa)->getJson('/api/parrainage')->assertOk()->json('data.code');
    }

    private function payer(User $user, Boutique $boutique, string $plan = 'basic'): void
    {
        $service = app(AbonnementService::class);
        $service->approuver($service->soumettre($boutique, $user, $plan, CycleFacturation::Mensuel), $this->exploitant);
    }

    public function test_chaque_proprietaire_a_un_code_lisible_et_un_message_a_partager(): void
    {
        $data = $this->api($this->awa)->getJson('/api/parrainage')->assertOk()->json('data');

        $this->assertMatchesRegularExpression('/^AWA-[A-HJ-NP-Z2-9]{3}$/', $data['code']);
        $this->assertStringContainsString($data['code'], $data['message_partage']);
        $this->assertStringContainsString('14 jours', $data['message_partage']);
        $this->assertSame($data['code'], $this->api($this->awa)->getJson('/api/parrainage')->json('data.code'), 'le code ne change pas');
    }

    public function test_le_filleul_a_14_jours_d_essai(): void
    {
        $code = $this->codeAwa();

        $this->postJson('/api/inscription', [
            'nom_boutique' => 'Boutique Fatou', 'pays' => 'ML', 'telephone' => '76008202',
            'password' => 'password123', 'nom_utilisateur' => 'Fatou', 'code_parrainage' => strtolower($code),
        ])->assertCreated();

        $fatou = User::where('name', 'Fatou')->sole();
        $this->assertSame($this->awa->id, $fatou->parraine_par);
        $this->assertSame(now()->addDays(14)->toDateString(), $fatou->abonnement->fin->toDateString());
    }

    public function test_un_code_inconnu_ou_le_sien_est_refuse_sans_rien_creer(): void
    {
        $code = $this->codeAwa();
        $avant = User::count();

        $this->postJson('/api/inscription', [
            'nom_boutique' => 'X', 'pays' => 'ML', 'telephone' => '76008209', 'password' => 'password123',
            'nom_utilisateur' => 'X', 'code_parrainage' => 'FAUX-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('code_parrainage');

        $this->postJson('/api/inscription', [
            'nom_boutique' => 'Awa bis', 'pays' => 'ML', 'telephone' => '76008201', 'password' => 'password123',
            'nom_utilisateur' => 'Awa', 'code_parrainage' => $code,
        ])->assertUnprocessable();

        $this->assertSame($avant, User::count());
    }

    public function test_rien_pour_le_parrain_tant_que_le_filleul_ne_paie_pas(): void
    {
        ['user' => $fatou] = $this->inscrire('Fatou', '76008202', $this->codeAwa());
        $this->awa->abonnement()->update(['plan' => 'basic', 'fin' => now()->addDays(10)->toDateString()]);

        // Un accès offert à la main n'est pas un paiement.
        app(AbonnementService::class)->accorder($fatou, 'basic', now()->addMonth(), $this->exploitant);

        $this->assertSame(now()->addDays(10)->toDateString(), $this->awa->abonnement()->first()->fin->toDateString());
        $this->assertSame(0, NotificationApp::where('user_id', $this->awa->id)->count());
    }

    public function test_un_parrain_abonne_gagne_30_jours_des_le_premier_paiement_du_filleul(): void
    {
        ['user' => $fatou, 'boutique' => $boutiqueFatou] = $this->inscrire('Fatou', '76008202', $this->codeAwa());
        $this->awa->abonnement()->update(['plan' => 'basic', 'fin' => now()->addDays(10)->toDateString()]);

        $this->payer($fatou, $boutiqueFatou);

        $this->assertSame(now()->addDays(40)->toDateString(), $this->awa->abonnement()->first()->fin->toDateString());
        $rappel = NotificationApp::where('user_id', $this->awa->id)->sole();
        $this->assertStringContainsString('Fatou', $rappel->message);
        $this->assertSame('/parrainage', $rappel->lien);

        // Le renouvellement du filleul ne rapporte plus rien.
        $this->payer($fatou, $boutiqueFatou);
        $this->assertSame(now()->addDays(40)->toDateString(), $this->awa->abonnement()->first()->fin->toDateString());
        $this->assertSame(1, $this->api($this->awa)->getJson('/api/parrainage')->json('data.mois_gagnes'));
    }

    public function test_un_parrain_en_essai_garde_son_mois_pour_son_premier_abonnement(): void
    {
        ['user' => $fatou, 'boutique' => $boutiqueFatou] = $this->inscrire('Fatou', '76008202', $this->codeAwa());
        $essaiAwa = $this->awa->abonnement()->first()->fin->toDateString();

        $this->payer($fatou, $boutiqueFatou);

        $this->assertSame($essaiAwa, $this->awa->abonnement()->first()->fin->toDateString(), 'l’essai n’est pas prolongé');
        $this->assertSame(30, $this->api($this->awa)->getJson('/api/parrainage')->json('data.jours_en_attente'));

        // Awa paie un mois : elle en a deux.
        $this->awa->abonnement()->update(['fin' => now()->subDay()->toDateString()]);
        $this->payer($this->awa, $this->boutique);

        $abonnement = $this->awa->abonnement()->first();
        $this->assertSame(now()->addMonth()->addDays(30)->toDateString(), $abonnement->fin->toDateString());
        $this->assertSame(0, $abonnement->jours_offerts);
    }

    public function test_au_plus_douze_mois_offerts_par_an(): void
    {
        $this->awa->abonnement()->update(['plan' => 'basic', 'fin' => now()->addDays(10)->toDateString()]);
        $code = $this->codeAwa();

        foreach (range(1, Parrainage::MAX_PAR_AN + 1) as $i) {
            ['user' => $filleul, 'boutique' => $boutique] = $this->inscrire("Filleul{$i}", '760090'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $code);
            $this->payer($filleul, $boutique);
        }

        $this->assertSame(
            now()->addDays(10 + 30 * Parrainage::MAX_PAR_AN)->toDateString(),
            Carbon::parse($this->awa->abonnement()->first()->fin)->toDateString(),
        );
    }

    public function test_le_code_d_un_admin_non_proprietaire_profite_a_l_abonnement_de_la_boutique(): void
    {
        $ismo = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Ismo', 'phone' => '+22374988201', 'password' => 'password123']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        $ismo->assignRole('admin');
        $this->awa->abonnement()->update(['plan' => 'basic', 'fin' => now()->addDays(10)->toDateString()]);

        $code = $this->api($ismo)->getJson('/api/parrainage')->assertOk()->json('data.code');
        ['user' => $fatou, 'boutique' => $boutiqueFatou] = $this->inscrire('Fatou', '76008202', $code);
        $this->payer($fatou, $boutiqueFatou);

        $this->assertSame(now()->addDays(40)->toDateString(), $this->awa->abonnement()->first()->fin->toDateString());
        $this->assertSame(1, NotificationApp::where('user_id', $ismo->id)->count());
    }

    public function test_un_abonnement_illimite_recoit_un_merci_sans_jours(): void
    {
        $this->awa->abonnement()->update(['plan' => 'pro', 'fin' => null, 'est_actif' => true]);
        ['user' => $fatou, 'boutique' => $boutiqueFatou] = $this->inscrire('Fatou', '76008202', $this->codeAwa());

        $this->payer($fatou, $boutiqueFatou);

        $abonnement = $this->awa->abonnement()->first();
        $this->assertNull($abonnement->fin);
        $this->assertSame(0, $abonnement->jours_offerts);
        $this->assertStringContainsString('illimité', NotificationApp::where('user_id', $this->awa->id)->sole()->message);
    }

    public function test_seule_la_demande_qui_recompense_est_marquee_dans_la_console(): void
    {
        ['user' => $fatou, 'boutique' => $boutiqueFatou] = $this->inscrire('Fatou', '76008202', $this->codeAwa());
        $service = app(AbonnementService::class);
        $parrainage = app(Parrainage::class);
        $this->exploitant->forceFill(['est_admin_plateforme' => true])->save();

        $premiere = $service->soumettre($boutiqueFatou, $fatou, 'basic', CycleFacturation::Mensuel);
        $this->assertSame(['etat' => 'a_venir', 'parrain' => 'Awa'], $parrainage->pourDemande($premiere->fresh()));
        $this->api($this->exploitant)->getJson('/api/plateforme/demandes')
            ->assertOk()->assertJsonPath('data.0.parrainage.etat', 'a_venir')->assertJsonPath('data.0.parrainage.parrain', 'Awa');

        $service->approuver($premiere, $this->exploitant);
        $this->assertSame(['etat' => 'recompense', 'parrain' => 'Awa'], $parrainage->pourDemande($premiere->fresh()));

        // Le renouvellement du filleul n'est pas marqué : il ne rapporte rien.
        $renouvellement = $service->soumettre($boutiqueFatou, $fatou, 'basic', CycleFacturation::Mensuel);
        $this->assertNull($parrainage->pourDemande($renouvellement->fresh()));
        $service->approuver($renouvellement, $this->exploitant);
        $this->assertNull($parrainage->pourDemande($renouvellement->fresh()));
    }

    public function test_seul_l_admin_voit_le_parrainage(): void
    {
        $caissier = User::create(['boutique_id' => $this->boutique->id, 'name' => 'Caissier', 'phone' => '+22370000001', 'password' => 'password123']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->boutique->id);
        $caissier->assignRole('caissier');

        $this->api($caissier)->getJson('/api/parrainage')->assertForbidden();
    }
}
