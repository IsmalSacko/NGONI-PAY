<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Logo de boutique et photos d'articles : rangés sur le disque privé et
 * servis par /images/… (voir ImageController), sans dépendre du lien public
 * de stockage.
 */
class Images
{
    public const REGLES = ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'];

    /** Remplace l'image précédente ; rend le chemin relatif enregistré. */
    public function enregistrer(UploadedFile $fichier, string $dossier, string $nom, ?string $ancien = null): string
    {
        if ($ancien) {
            Storage::disk('local')->delete($ancien);
        }

        return $fichier->storeAs($dossier, $nom.'-'.now()->timestamp.'.'.strtolower($fichier->extension()), 'local');
    }

    public function supprimer(?string $chemin): void
    {
        if ($chemin) {
            Storage::disk('local')->delete($chemin);
        }
    }
}
