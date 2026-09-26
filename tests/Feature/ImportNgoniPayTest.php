<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Abonnement;
use App\Models\Boutique;
use App\Models\Client;
use App\Models\DemandeAbonnement;
use App\Models\User;
use App\Models\Vente;
use App\Services\Import\ImportNgoniPay;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ImportNgoniPayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.ngonipay' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $s = DB::connection('ngonipay')->getSchemaBuilder();

        $s->create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->nullable(); $t->string('phone')->nullable(); $t->string('country', 2)->nullable();
            $t->timestamp('email_verified_at')->nullable(); $t->string('password'); $t->string('role')->default('owner');
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        $s->create('businesses', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('owner_id'); $t->string('name'); $t->string('type')->nullable(); $t->string('address')->nullable();
            $t->string('phone')->nullable(); $t->string('currency')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        $s->create('business_users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('business_id'); $t->unsignedBigInteger('user_id'); $t->string('role'); $t->timestamps();
        });
        $s->create('clients', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('business_id'); $t->string('phone')->nullable(); $t->string('name')->nullable();
            $t->string('email')->nullable(); $t->text('notes')->nullable(); $t->timestamps();
        });
        $s->create('payments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('business_id'); $t->unsignedBigInteger('client_id')->nullable(); $t->unsignedBigInteger('user_id')->nullable();
            $t->decimal('amount', 12, 2); $t->string('currency')->nullable(); $t->string('method'); $t->string('transaction_ref')->nullable();
            $t->string('idempotency_key')->nullable(); $t->string('status'); $t->string('purpose')->default('sale'); $t->timestamp('paid_at')->nullable(); $t->timestamps();
        });
        $s->create('subscriptions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('business_id'); $t->string('plan'); $t->date('starts_at'); $t->date('ends_at')->nullable();
            $t->boolean('is_active')->default(true); $t->boolean('is_manual')->default(false); $t->unsignedBigInteger('granted_by')->nullable();
            $t->string('admin_note')->nullable(); $t->timestamps();
        });
        $s->create('subscription_requests', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('business_id'); $t->unsignedBigInteger('requested_by_user_id')->nullable(); $t->string('plan');
            $t->string('method')->nullable(); $t->decimal('amount_due', 12, 2); $t->string('currency')->nullable(); $t->unsignedTinyInteger('months')->nullable();
            $t->string('cycle')->nullable(); $t->string('note')->nullable(); $t->string('contact_phone')->nullable(); $t->string('proof_path')->nullable();
            $t->text('proof_note')->nullable(); $t->string('status'); $t->timestamp('decided_at')->nullable(); $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->string('decision_note')->nullable(); $t->timestamps();
        });

        $src = DB::connection('ngonipay');
        $h = Hash::make('mot-de-passe-awa');
        $src->table('users')->insert([
            ['id' => 1, 'name' => 'Awa', 'phone' => '+22376008201', 'country' => 'ML', 'password' => $h, 'role' => 'owner', 'email' => 'awa@example.com', 'is_active' => 1],
            ['id' => 2, 'name' => 'Moussa', 'phone' => '0758071816', 'country' => 'CI', 'password' => Hash::make('x'), 'role' => 'owner', 'email' => null, 'is_active' => 1],
            ['id' => 3, 'name' => 'Ismael', 'phone' => '+33605758494', 'country' => 'FR', 'password' => Hash::make('x'), 'role' => 'system_admin', 'email' => null, 'is_active' => 1],
        ]);
        $src->table('businesses')->insert([
            ['id' => 10, 'owner_id' => 1, 'name' => 'Pressing Awa', 'currency' => 'XOF', 'is_active' => 1, 'phone' => '76008201', 'address' => 'Bamako'],
            ['id' => 11, 'owner_id' => 1, 'name' => 'Boutique fermée', 'currency' => 'XOF', 'is_active' => 0, 'phone' => null, 'address' => null],
            ['id' => 12, 'owner_id' => 2, 'name' => 'Chez Moussa', 'currency' => 'XOF', 'is_active' => 1, 'phone' => null, 'address' => null],
        ]);
        $src->table('business_users')->insert([
            ['business_id' => 10, 'user_id' => 1, 'role' => 'manager'],
            ['business_id' => 10, 'user_id' => 2, 'role' => 'manager'],
            ['business_id' => 12, 'user_id' => 2, 'role' => 'manager'],
        ]);
        $src->table('clients')->insert([
            ['id' => 100, 'business_id' => 10, 'phone' => '70000001', 'name' => 'Client A', 'email' => 'a@example.com', 'notes' => 'Fidèle'],
        ]);
        $src->table('payments')->insert([
            ['business_id' => 10, 'client_id' => 100, 'user_id' => 1, 'amount' => 2500.00, 'method' => 'cash', 'status' => 'success', 'purpose' => 'sale', 'idempotency_key' => null, 'transaction_ref' => 'a1b2', 'created_at' => '2026-08-01 10:00:00'],
            ['business_id' => 10, 'client_id' => null, 'user_id' => 2, 'amount' => 4000.00, 'method' => 'orange_money', 'status' => 'success', 'purpose' => 'sale', 'idempotency_key' => '0194b2a0-0000-7000-8000-000000000001', 'transaction_ref' => null, 'created_at' => '2026-08-02 10:00:00'],
            ['business_id' => 10, 'client_id' => null, 'user_id' => 1, 'amount' => 900.00, 'method' => 'wave', 'status' => 'cancelled', 'purpose' => 'sale', 'idempotency_key' => null, 'transaction_ref' => null, 'created_at' => '2026-08-03 10:00:00'],
            ['business_id' => 10, 'client_id' => null, 'user_id' => 1, 'amount' => 5000.00, 'method' => 'cash', 'status' => 'success', 'purpose' => 'subscription', 'idempotency_key' => null, 'transaction_ref' => null, 'created_at' => '2026-08-04 10:00:00'],
        ]);
        $src->table('subscriptions')->insert([
            ['business_id' => 10, 'plan' => 'trial', 'starts_at' => '2026-08-14', 'ends_at' => '2026-08-21', 'is_active' => 1],
            ['business_id' => 11, 'plan' => 'basic', 'starts_at' => '2026-09-01', 'ends_at' => now()->addMonth()->toDateString(), 'is_active' => 1],
            ['business_id' => 12, 'plan' => 'trial', 'starts_at' => '2026-08-14', 'ends_at' => '2026-08-21', 'is_active' => 1],
        ]);
        $src->table('subscription_requests')->insert([
            ['business_id' => 12, 'requested_by_user_id' => 2, 'plan' => 'pro', 'method' => 'cash', 'amount_due' => 10000, 'currency' => 'XOF', 'months' => 1, 'cycle' => 'monthly', 'status' => 'pending'],
        ]);
    }

    private function importer(): array
    {
        return app(ImportNgoniPay::class)->importer(DB::connection('ngonipay'));
    }

    private function dans(Boutique $b): void
    {
        app(TenantContext::class)->setBoutique($b->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($b->id);
    }

    public function test_tout_est_repris_et_chacun_garde_son_mot_de_passe(): void
    {
        $stats = $this->importer();

        $this->assertSame(['utilisateurs' => 3, 'boutiques' => 2, 'boutiques_desactivees' => 1, 'membres' => 1, 'clients' => 1,
            'ventes' => 2, 'ventes_annulees' => 1, 'abonnements' => 2, 'demandes' => 1], $stats);

        $awa = User::where('phone', '+22376008201')->firstOrFail();
        $this->assertTrue(Hash::check('mot-de-passe-awa', $awa->password));

        $this->postJson('/api/connexion', ['telephone' => '76008201', 'pays' => 'ML', 'password' => 'mot-de-passe-awa'])->assertOk();
        $this->assertTrue(User::where('phone', '+33605758494')->first()->est_admin_plateforme);
    }

    public function test_boutiques_proprietaires_et_equipes(): void
    {
        $this->importer();
        $awa = User::where('name', 'Awa')->first();
        $moussa = User::where('name', 'Moussa')->first();
        $pressing = Boutique::where('nom', 'Pressing Awa')->first();

        $this->assertSame($awa->id, $pressing->proprietaire_id);
        $this->assertSame('ML', $pressing->pays);
        $this->assertSame('CI', Boutique::where('nom', 'Chez Moussa')->first()->pays);
        $this->assertNotNull(Boutique::withTrashed()->where('nom', 'Boutique fermée')->first()->deleted_at);
        $this->assertSame($pressing->id, $awa->boutique_id);

        // Moussa : gérant chez Awa, admin de sa boutique.
        $this->dans($pressing);
        $this->assertTrue($moussa->fresh()->hasRole('gerant'));
        $this->assertTrue($awa->fresh()->hasRole('admin'));
        $this->assertCount(2, $moussa->boutiqueIds());
    }

    public function test_ventes_et_clients(): void
    {
        $this->importer();
        $pressing = Boutique::where('nom', 'Pressing Awa')->first();
        $this->dans($pressing);

        $ventes = Vente::with('lignes')->orderBy('numero')->get();
        $this->assertSame([1, 2, 3], $ventes->pluck('numero')->all());
        $this->assertSame([2500, 4000, 900], $ventes->pluck('total')->all());
        $this->assertSame('especes', $ventes[0]->moyen_paiement->value);
        $this->assertSame('Client A', $ventes[0]->client->nom);
        $this->assertSame('0194b2a0-0000-7000-8000-000000000001', $ventes[1]->reference_locale);
        $this->assertTrue($ventes[2]->estAnnulee());
        $this->assertSame('Encaissement', $ventes[0]->lignes->first()->nom_produit);
        $this->assertSame('2026-08-01', $ventes[0]->created_at->toDateString());

        // L'annulée ne compte pas ; le paiement d'abonnement n'est pas une vente.
        $this->assertSame(6500, (int) Vente::valides()->sum('total'));
        $this->assertSame('Fidèle', Client::first()->notes);
    }

    public function test_abonnements_ramenes_au_compte_et_demandes(): void
    {
        $this->importer();
        $awa = User::where('name', 'Awa')->first();
        $moussa = User::where('name', 'Moussa')->first();

        // Awa : le Basic encore en cours de sa boutique fermée l'emporte sur l'essai expiré.
        $this->assertSame('basic', $awa->abonnement->plan);
        $this->assertTrue($awa->abonnement->estEnCours());
        $this->assertSame('essai', $moussa->abonnement->plan);
        $this->assertFalse($moussa->abonnement->estEnCours());

        $demande = DemandeAbonnement::firstOrFail();
        $this->assertSame($moussa->id, $demande->user_id);
        $this->assertSame('en_attente', $demande->statut->value);
        $this->assertSame(10000, $demande->montant);
        $this->assertSame(2, Abonnement::count());
    }

    public function test_la_commande_refuse_une_base_deja_remplie(): void
    {
        $this->artisan('ecaisse:importer-ngonipay')->assertSuccessful();
        $this->artisan('ecaisse:importer-ngonipay')->assertFailed();
    }
}
