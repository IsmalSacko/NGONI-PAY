<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Boutique;
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

    public function test_une_photo_envoyee_est_reduite_et_sa_vignette_tiree_a_la_demande(): void
    {
        Storage::fake('local');
        [$jeton, $riz] = $this->boutiqueAvecArticle();

        $produit = $this->withToken($jeton)->post("/api/produits/{$riz->id}/photo", ['photo' => UploadedFile::fake()->image('riz.jpg', 3000, 2000)], ['Accept' => 'application/json'])
            ->assertOk()->json();

        $chemin = $riz->fresh()->photo;
        $this->assertStringEndsWith('.jpg', $chemin);
        $this->assertSame([1000, 667], array_slice(getimagesizefromstring(Storage::disk('local')->get($chemin)), 0, 2));

        $this->app['auth']->forgetGuards();
        $vignette = $this->get(parse_url($produit['vignette_url'], PHP_URL_PATH).'?'.parse_url($produit['vignette_url'], PHP_URL_QUERY))
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('immutable', $vignette->headers->get('Cache-Control'));
        $this->assertSame([360, 240], array_slice(getimagesizefromstring($vignette->streamedContent()), 0, 2));
        Storage::disk('local')->assertExists('vignettes/produits/'.pathinfo($chemin, PATHINFO_FILENAME).'.jpg');
    }

    public function test_un_logo_png_reste_en_png(): void
    {
        Storage::fake('local');
        [$jeton] = $this->boutiqueAvecArticle();

        $this->withToken($jeton)->post('/api/boutique/logo', ['logo' => UploadedFile::fake()->image('logo.png', 1200, 1200)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.logo_vignette_url', fn ($url) => str_contains((string) $url, '/vignette?v='));

        $logo = Boutique::withoutGlobalScopes()->value('logo');
        $this->assertStringEndsWith('.png', $logo);
        $this->assertSame([1000, 1000], array_slice(getimagesizefromstring(Storage::disk('local')->get($logo)), 0, 2));
    }

    public function test_l_adresse_d_une_photo_ne_change_qu_avec_la_photo(): void
    {
        Storage::fake('local');
        [$jeton, $riz] = $this->boutiqueAvecArticle();
        $envoyer = fn () => $this->withToken($jeton)->post("/api/produits/{$riz->id}/photo", ['photo' => UploadedFile::fake()->image('riz.jpg')], ['Accept' => 'application/json'])->assertOk();

        $avant = $envoyer()->json('photo_url');
        $premiere = $riz->fresh()->photo;
        $this->get(parse_url($riz->fresh()->vignette_url, PHP_URL_PATH))->assertOk();

        // Une vente, un ajustement de stock : la photo ne bouge pas, son adresse non plus.
        $this->travel(5)->minutes();
        $riz->fresh()->forceFill(['stock' => 1])->save();
        $this->assertSame($avant, $riz->fresh()->photo_url);

        // Une nouvelle photo : nouvelle adresse, et l'ancienne vignette part avec l'ancienne photo.
        $this->assertNotSame($avant, $envoyer()->json('photo_url'));
        Storage::disk('local')->assertMissing($premiere);
        Storage::disk('local')->assertMissing('vignettes/produits/'.pathinfo($premiere, PATHINFO_FILENAME).'.jpg');
    }

    public function test_une_image_que_gd_ne_decode_pas_est_servie_telle_quelle(): void
    {
        Storage::fake('local');
        [, $riz] = $this->boutiqueAvecArticle();
        Storage::disk('local')->put('produits/illisible.webp', 'RIFF....WEBP');
        $riz->forceFill(['photo' => 'produits/illisible.webp'])->save();

        $this->get(parse_url($riz->fresh()->vignette_url, PHP_URL_PATH))->assertOk();
        $this->assertSame('RIFF....WEBP', Storage::disk('local')->get('produits/illisible.webp'));
        Storage::disk('local')->assertMissing('vignettes/produits/illisible.jpg');
    }

    /** @return array{string, Produit} */
    private function boutiqueAvecArticle(): array
    {
        ['user' => $admin, 'boutique' => $boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pharmacie', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);
        app(TenantContext::class)->setBoutique($boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);
        $riz = Produit::create(['nom' => 'Riz', 'prix_vente' => 5000, 'taux_tva' => 0, 'stock' => 3]);
        app(TenantContext::class)->forget();

        return [$admin->createToken('t')->plainTextToken, $riz];
    }
}
