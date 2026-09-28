<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Boutique;
use App\Models\Produit;
use App\Services\Images;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logo et photos d'articles (publics : ils figurent sur les tickets), en
 * taille réelle ou en vignette.
 */
class ImageController extends Controller
{
    public function __construct(private readonly Images $images) {}

    public function logo(Request $request, string $boutique): Response
    {
        return $this->servir($request, Boutique::withoutGlobalScopes()->whereKey($boutique)->value('logo'), vignette: false);
    }

    public function logoVignette(Request $request, string $boutique): Response
    {
        return $this->servir($request, Boutique::withoutGlobalScopes()->whereKey($boutique)->value('logo'), vignette: true);
    }

    public function photo(Request $request, string $produit): Response
    {
        return $this->servir($request, Produit::withoutGlobalScopes()->whereKey($produit)->value('photo'), vignette: false);
    }

    public function photoVignette(Request $request, string $produit): Response
    {
        return $this->servir($request, Produit::withoutGlobalScopes()->whereKey($produit)->value('photo'), vignette: true);
    }

    private function servir(Request $request, ?string $chemin, bool $vignette): Response
    {
        abort_if(! $chemin || ! Storage::disk('local')->exists($chemin), 404);

        if ($vignette) {
            $chemin = $this->images->vignette($chemin) ?? $chemin;
        }

        // Avec ?v=… (les adresses rendues par l'API), le contenu ne change
        // jamais : une nouvelle image a une nouvelle adresse. Sans, un jour.
        $cache = $request->filled('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=86400';

        return Storage::disk('local')->response($chemin, null, ['Cache-Control' => $cache]);
    }
}
