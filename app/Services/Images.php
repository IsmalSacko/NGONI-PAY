<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Images\ImageGd;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Logo de boutique et photos d'articles : rangés sur le disque privé et
 * servis par /images/… (voir ImageController), sans dépendre du lien public
 * de stockage.
 *
 * Pensé pour la 3G : chaque image est réduite et réencodée à l'envoi, et une
 * vignette de la taille d'une tuile de caisse est tirée à la première
 * demande — celles envoyées avant son existence en ont une aussi, sans
 * migration.
 */
class Images
{
    public const REGLES = ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'];

    /** Plus grand côté conservé : une photo de téléphone de 4000 px n'apporte rien à un ticket. */
    private const COTE_ORIGINAL = 1000;

    /** Une tuile de caisse fait ~120 points, soit 360 pixels sur un écran à densité 3. */
    private const COTE_VIGNETTE = 360;

    private const QUALITE_ORIGINAL = 80;

    private const QUALITE_VIGNETTE = 75;

    /** Remplace l'image précédente ; rend le chemin relatif enregistré. */
    public function enregistrer(UploadedFile $fichier, string $dossier, string $nom, ?string $ancien = null): string
    {
        $this->supprimer($ancien);

        // Le suffixe aléatoire garantit un chemin neuf, donc une adresse neuve
        // (voir version()), même pour deux envois dans la même seconde.
        $base = "{$dossier}/{$nom}-".now()->timestamp.Str::lower(Str::random(4));

        $image = ImageGd::lire($fichier->getContent());
        if ($image === null) {
            // Format que GD ne lit pas ici (WebP : GD compilé sans) : gardé tel
            // quel, plutôt que refusé — il reste sous la limite de REGLES.
            return $fichier->storeAs(dirname($base), basename($base).'.'.strtolower($fichier->extension()), 'local');
        }

        $image = $image->reduite(self::COTE_ORIGINAL);
        [$octets, $extension] = $image->transparente
            ? [$image->png(), 'png']
            : [$image->jpeg(self::QUALITE_ORIGINAL), 'jpg'];

        $this->disque()->put("{$base}.{$extension}", $octets);

        return "{$base}.{$extension}";
    }

    public function supprimer(?string $chemin): void
    {
        if ($chemin) {
            $this->disque()->delete([$chemin, $this->cheminVignette($chemin)]);
        }
    }

    /**
     * Chemin de la vignette de `$chemin`, tirée à la première demande. Null
     * si l'image ne se décode pas : l'appelant sert alors l'original.
     */
    public function vignette(string $chemin): ?string
    {
        $vignette = $this->cheminVignette($chemin);
        if ($this->disque()->exists($vignette)) {
            return $vignette;
        }

        $image = ImageGd::lire((string) $this->disque()->get($chemin));
        if ($image === null) {
            return null;
        }

        // Écrite à côté puis renommée : une requête concurrente ne lit jamais
        // une vignette à moitié écrite, au pire elle en calcule une seconde.
        $provisoire = $vignette.'.'.Str::random(8).'.tmp';
        $this->disque()->put($provisoire, $image->reduite(self::COTE_VIGNETTE)->jpeg(self::QUALITE_VIGNETTE));
        $this->disque()->move($provisoire, $vignette);

        return $vignette;
    }

    /**
     * Jeton de version des adresses d'image. Il suit le fichier, et lui seul :
     * vendre un article change sa date de mise à jour, pas sa photo, et ne
     * doit pas faire retélécharger celle-ci à chaque appareil.
     */
    public static function version(string $chemin): string
    {
        return substr(hash('xxh3', $chemin), 0, 12);
    }

    private function cheminVignette(string $chemin): string
    {
        return 'vignettes/'.dirname($chemin).'/'.pathinfo($chemin, PATHINFO_FILENAME).'.jpg';
    }

    private function disque(): Filesystem
    {
        return Storage::disk('local');
    }
}
