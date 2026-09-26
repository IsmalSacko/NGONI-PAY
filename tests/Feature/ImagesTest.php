<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Produit;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_logo_et_photo_s_envoient_et_se_servent_sans_session(): void
    {
        Storage::fake('local');
        ['user' => $admin, 'boutique' => $boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pharmacie', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);
        $riz = Produit::create(['nom' => 'Riz', 'prix_vente' => 5000, 'taux_tva' => 0, 'stock' => 3]);
        app(TenantContext::class)->forget();
        $jeton = $admin->createToken('t')->plainTextToken;

        $logo = $this->withToken($jeton)->post('/api/boutique/logo', ['logo' => UploadedFile::fake()->image('logo.png', 300, 300)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.logo_url');
        $this->assertNotNull($logo);

        $this->withToken($jeton)->post('/api/boutique/logo', ['logo' => UploadedFile::fake()->create('virus.exe', 10)], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $photo = $this->withToken($jeton)->post("/api/produits/{$riz->id}/photo", ['photo' => UploadedFile::fake()->image('riz.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->json('photo_url');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->get(parse_url($logo, PHP_URL_PATH))->assertOk()->assertHeader('Cache-Control');
        $this->get(parse_url($photo, PHP_URL_PATH))->assertOk();

        $this->withToken($jeton)->deleteJson('/api/boutique/logo')->assertOk()->assertJsonPath('data.logo_url', null);
        $this->get(parse_url($logo, PHP_URL_PATH))->assertNotFound();
    }

    public function test_back_office_photo_article_et_logo(): void
    {
        Storage::fake('local');
        ['user' => $admin, 'boutique' => $boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pharmacie', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        $this->actingAs($admin);
        app(TenantContext::class)->setBoutique($boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);

        \Livewire\Livewire::test(\App\Livewire\Produits\Index::class)
            ->call('nouveauProduit')
            ->set('nom', 'Savon')->set('prix_vente', '500')->set('taux_tva', '0')->set('stock', '5')->set('seuil_alerte', '1')
            ->set('photo', UploadedFile::fake()->image('savon.jpg'))
            ->call('enregistrer')->assertHasNoErrors();
        $this->assertNotNull(Produit::where('nom', 'Savon')->value('photo'));

        \Livewire\Livewire::test(\App\Livewire\Produits\Index::class)
            ->call('nouveauProduit')
            ->set('nom', 'Mauvais')->set('prix_vente', '500')->set('taux_tva', '0')->set('stock', '5')->set('seuil_alerte', '1')
            ->set('photo', UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))
            ->call('enregistrer')->assertHasErrors('photo');
        $this->assertNull(Produit::where('nom', 'Mauvais')->first());

        \Livewire\Livewire::test(\App\Livewire\Boutiques\Index::class)
            ->set('logo', UploadedFile::fake()->image('logo.png'))->call('envoyerLogo')->assertHasNoErrors();
        $this->assertNotNull($boutique->fresh()->logo);
    }
}
