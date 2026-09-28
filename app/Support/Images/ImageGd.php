<?php

declare(strict_types=1);

namespace App\Support\Images;

use GdImage;

/**
 * Une image décodée par GD, redressée selon son orientation EXIF.
 *
 * Immuable : `reduite()` rend une nouvelle image. Les encodeurs rendent des
 * octets, jamais un fichier : où les ranger est l'affaire de l'appelant.
 */
final readonly class ImageGd
{
    private function __construct(
        private GdImage $gd,
        /** PNG ou GIF à l'origine : la transparence doit survivre (logos). */
        public bool $transparente,
    ) {}

    /** Null si GD ne sait pas lire ces octets (format absent de cette installation, fichier corrompu). */
    public static function lire(string $octets): ?self
    {
        $infos = @getimagesizefromstring($octets);
        $gd = $infos === false ? false : @imagecreatefromstring($octets);
        if ($gd === false) {
            return null;
        }

        if (! imageistruecolor($gd)) {
            imagepalettetotruecolor($gd);
        }

        $transparente = in_array($infos[2], [IMAGETYPE_PNG, IMAGETYPE_GIF], true);
        if ($transparente) {
            imagealphablending($gd, false);
            imagesavealpha($gd, true);
        }

        return new self(self::redresser($gd, $infos[2] === IMAGETYPE_JPEG ? self::orientation($octets) : 1), $transparente);
    }

    public function largeur(): int
    {
        return imagesx($this->gd);
    }

    public function hauteur(): int
    {
        return imagesy($this->gd);
    }

    /** Ramène le plus grand côté à `$cote` pixels ; une image déjà plus petite est rendue telle quelle. */
    public function reduite(int $cote): self
    {
        $largeur = $this->largeur();
        $hauteur = $this->hauteur();
        if (max($largeur, $hauteur) <= $cote) {
            return $this;
        }

        $ratio = $cote / max($largeur, $hauteur);
        $cible = self::toile(max(1, (int) round($largeur * $ratio)), max(1, (int) round($hauteur * $ratio)), $this->transparente);
        imagecopyresampled($cible, $this->gd, 0, 0, 0, 0, imagesx($cible), imagesy($cible), $largeur, $hauteur);

        return new self($cible, $this->transparente);
    }

    /**
     * JPEG progressif : sur une connexion lente, une première version floue
     * s'affiche dès les premiers octets. La transparence est posée sur du
     * blanc, le fond des tuiles et des tickets.
     */
    public function jpeg(int $qualite): string
    {
        $gd = $this->gd;
        if ($this->transparente) {
            $gd = self::toile($this->largeur(), $this->hauteur(), false);
            imagecopy($gd, $this->gd, 0, 0, 0, 0, $this->largeur(), $this->hauteur());
        }
        imageinterlace($gd, true);

        return self::encoder(fn ($flux) => imagejpeg($gd, $flux, $qualite));
    }

    public function png(): string
    {
        return self::encoder(fn ($flux) => imagepng($this->gd, $flux, 9));
    }

    /** Toile vierge : transparente, ou blanche. */
    private static function toile(int $largeur, int $hauteur, bool $transparente): GdImage
    {
        $gd = imagecreatetruecolor($largeur, $hauteur);
        if ($transparente) {
            imagealphablending($gd, false);
            imagesavealpha($gd, true);
            imagefill($gd, 0, 0, imagecolorallocatealpha($gd, 0, 0, 0, 127));
        } else {
            imagefill($gd, 0, 0, imagecolorallocate($gd, 255, 255, 255));
        }

        return $gd;
    }

    /** @param  callable(resource): bool  $ecrire */
    private static function encoder(callable $ecrire): string
    {
        $flux = fopen('php://temp', 'r+b');
        $ecrire($flux);
        rewind($flux);
        $octets = (string) stream_get_contents($flux);
        fclose($flux);

        return $octets;
    }

    /** Orientation EXIF (1 à 8) d'un JPEG ; 1 si absente ou illisible. */
    private static function orientation(string $octets): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }
        $flux = fopen('php://memory', 'r+b');
        fwrite($flux, $octets);
        rewind($flux);
        $exif = @exif_read_data($flux);
        fclose($flux);

        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Applique l'orientation EXIF : un téléphone tenu en portrait enregistre
     * souvent l'image couchée, avec la consigne de la tourner à l'affichage.
     * Une fois réencodée sans EXIF, elle doit être droite d'elle-même.
     */
    private static function redresser(GdImage $gd, int $orientation): GdImage
    {
        // 2, 5, 7 : miroir horizontal ; 4 : miroir vertical. Puis le quart de
        // tour, en degrés dans le sens inverse des aiguilles (imagerotate).
        match ($orientation) {
            2, 5, 7 => imageflip($gd, IMG_FLIP_HORIZONTAL),
            4 => imageflip($gd, IMG_FLIP_VERTICAL),
            default => null,
        };

        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };

        return $angle === 0 ? $gd : imagerotate($gd, $angle, 0);
    }
}
