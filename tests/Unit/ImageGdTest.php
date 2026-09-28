<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Images\ImageGd;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImageGdTest extends TestCase
{
    public function test_des_octets_qui_ne_sont_pas_une_image_ne_se_lisent_pas(): void
    {
        $this->assertNull(ImageGd::lire('pas une image'));
        $this->assertNull(ImageGd::lire(''));
    }

    public function test_reduite_garde_les_proportions_et_n_agrandit_jamais(): void
    {
        $image = ImageGd::lire(self::jpeg(3000, 2000));

        $reduite = $image->reduite(1000);
        $this->assertSame([1000, 667], [$reduite->largeur(), $reduite->hauteur()]);
        $this->assertSame([3000, 2000], [$image->largeur(), $image->hauteur()], 'immuable');
        $this->assertSame($reduite, $reduite->reduite(1200), 'déjà plus petite : rendue telle quelle');
    }

    public function test_le_png_garde_sa_transparence_et_le_jpeg_la_pose_sur_du_blanc(): void
    {
        $gd = imagecreatetruecolor(10, 10);
        imagesavealpha($gd, true);
        imagealphablending($gd, false);
        imagefill($gd, 0, 0, imagecolorallocatealpha($gd, 0, 0, 0, 127));
        ob_start();
        imagepng($gd);
        $image = ImageGd::lire((string) ob_get_clean());

        $this->assertTrue($image->transparente);
        $png = imagecreatefromstring($image->png());
        $this->assertSame(127, imagecolorsforindex($png, imagecolorat($png, 5, 5))['alpha']);

        $jpeg = imagecreatefromstring($image->jpeg(80));
        $this->assertSame(['red' => 255, 'green' => 255, 'blue' => 255], array_intersect_key(imagecolorsforindex($jpeg, imagecolorat($jpeg, 5, 5)), array_flip(['red', 'green', 'blue'])));
    }

    public function test_le_jpeg_est_progressif(): void
    {
        // Marqueur SOF2 (FFC2) : balayage progressif, affiché flou puis net.
        $this->assertStringContainsString("\xFF\xC2", ImageGd::lire(self::jpeg(40, 20))->jpeg(75));
    }

    /** @return iterable<string, array{int, array{int, int}}> */
    public static function orientations(): iterable
    {
        yield 'droite' => [1, [40, 20]];
        yield 'retournée' => [3, [40, 20]];
        yield 'quart de tour horaire' => [6, [20, 40]];
        yield 'quart de tour anti-horaire' => [8, [20, 40]];
        yield 'transposée' => [5, [20, 40]];
    }

    /** @param  array{int, int}  $attendu */
    #[DataProvider('orientations')]
    public function test_l_orientation_exif_est_appliquee(int $orientation, array $attendu): void
    {
        $image = ImageGd::lire(self::avecOrientation(self::jpeg(40, 20), $orientation));

        $this->assertSame($attendu, [$image->largeur(), $image->hauteur()]);
    }

    public function test_le_coin_marque_arrive_au_bon_endroit_apres_un_quart_de_tour(): void
    {
        // Coin haut-gauche rouge, couché avec « tourner de 90° dans le sens horaire » :
        // une fois redressée, le rouge est en haut à droite.
        $gd = imagecreatetruecolor(40, 20);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 255, 255, 255));
        imagefilledrectangle($gd, 0, 0, 9, 9, imagecolorallocate($gd, 255, 0, 0));
        ob_start();
        imagejpeg($gd, null, 95);

        $image = ImageGd::lire(self::avecOrientation((string) ob_get_clean(), 6));
        $gd = imagecreatefromstring($image->jpeg(95));

        $this->assertGreaterThan(200, imagecolorsforindex($gd, imagecolorat($gd, 15, 4))['red'] - imagecolorsforindex($gd, imagecolorat($gd, 15, 4))['green']);
        $this->assertLessThan(50, imagecolorsforindex($gd, imagecolorat($gd, 4, 4))['red'] - imagecolorsforindex($gd, imagecolorat($gd, 4, 4))['green']);
    }

    private static function jpeg(int $largeur, int $hauteur): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor($largeur, $hauteur));

        return (string) ob_get_clean();
    }

    /** Insère un segment APP1 Exif ne portant que l'orientation, juste après SOI. */
    private static function avecOrientation(string $jpeg, int $orientation): string
    {
        $tiff = 'II*'."\x00".pack('V', 8)            // en-tête TIFF little-endian, IFD0 à l'octet 8
            .pack('v', 1)                              // une entrée
            .pack('vvVvv', 0x0112, 3, 1, $orientation, 0) // Orientation, SHORT, 1 valeur
            .pack('V', 0);                             // pas d'IFD suivant
        $app1 = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }
}
