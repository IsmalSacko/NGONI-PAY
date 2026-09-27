<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CategorieProduit;
use App\Models\Produit;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogueDeDepartTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_boutique_neuve_a_deux_articles_avec_photo(): void
    {
        Storage::fake('local');
        ['user' => $awa, 'boutique' => $boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pressing Awa', 'pays' => 'ML', 'telephone' => '76008201',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ]);

        $articles = Produit::withoutBoutiqueScope()->where('boutique_id', $boutique->id)->orderBy('nom')->get();
        $this->assertSame(['Huile', 'Riz'], $articles->pluck('nom')->all());
        $this->assertSame([1500, 3500], $articles->pluck('prix_vente')->all(), 'prix en franc CFA');
        $this->assertSame('Alimentation', CategorieProduit::withoutGlobalScopes()->where('boutique_id', $boutique->id)->value('nom'));
        foreach ($articles as $article) {
            Storage::disk('local')->assertExists($article->photo);
        }

        // Visibles dans l'application, photo comprise.
        $produits = $this->withToken($awa->createToken('app')->plainTextToken)->getJson('/api/produits')->assertOk()->json();
        $this->assertCount(2, $produits);
        $this->assertNotNull($produits[0]['photo_url']);
        $this->get(parse_url($produits[0]['photo_url'], PHP_URL_PATH))->assertOk();
    }

    public function test_une_devise_a_centimes_a_des_prix_a_centimes(): void
    {
        Storage::fake('local');
        ['boutique' => $boutique] = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Épicerie Paris', 'pays' => 'FR', 'telephone' => '0612345678',
            'email' => null, 'password' => 'password123', 'nom_utilisateur' => 'Léa',
        ]);

        $this->assertSame('EUR', $boutique->devise);
        $this->assertSame([250, 450], Produit::withoutBoutiqueScope()->where('boutique_id', $boutique->id)->orderBy('nom')->pluck('prix_vente')->all());
    }
}
