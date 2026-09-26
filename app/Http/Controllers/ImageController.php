<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Boutique;
use App\Models\Produit;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Logo et photos d'articles (publics : ils figurent sur les tickets). */
class ImageController extends Controller
{
    public function logo(string $boutique): Response
    {
        return $this->servir(Boutique::withoutGlobalScopes()->whereKey($boutique)->value('logo'));
    }

    public function photo(string $produit): Response
    {
        return $this->servir(Produit::withoutGlobalScopes()->whereKey($produit)->value('photo'));
    }

    private function servir(?string $chemin): Response
    {
        abort_if(! $chemin || ! Storage::disk('local')->exists($chemin), 404);

        // L'adresse change à chaque nouvelle image (?v=…) : cache long.
        return Storage::disk('local')->response($chemin, null, ['Cache-Control' => 'public, max-age=2592000']);
    }
}
