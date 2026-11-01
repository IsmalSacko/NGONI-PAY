<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Apple\JwsApple;
use App\Services\Apple\JwsInvalide;
use PHPUnit\Framework\TestCase;
use Tests\Support\FabriqueApple;

/**
 * Vérification des JWS de l'App Store, sur une fausse autorité « Apple »
 * fabriquée avec openssl (FabriqueApple).
 */
class JwsAppleTest extends TestCase
{
    private static ?FabriqueApple $apple = null;

    private static function apple(): FabriqueApple
    {
        return self::$apple ??= new FabriqueApple;
    }

    private function verificateur(): JwsApple
    {
        return new JwsApple(self::apple()->racine);
    }

    private function refuse(string $jws, string $message, ?JwsApple $verificateur = null): void
    {
        try {
            ($verificateur ?? $this->verificateur())->verifier($jws);
            $this->fail('JWS accepté : '.$message);
        } catch (JwsInvalide $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_un_jws_signe_par_la_chaine_d_apple_rend_son_contenu(): void
    {
        $contenu = ['transactionId' => '2000000000000001', 'signedDate' => time() * 1000];

        // Des centaines de signatures : R et S courts ou à bit fort, la conversion en DER tient.
        for ($i = 0; $i < 200; $i++) {
            $this->assertSame([...$contenu, 'i' => $i], $this->verificateur()->verifier(self::apple()->jws([...$contenu, 'i' => $i])));
        }
    }

    public function test_un_contenu_ou_une_signature_retouches_sont_refuses(): void
    {
        [$entete, , $signature] = explode('.', self::apple()->jws(['productId' => 'ngonicaisse.basic.mensuel', 'signedDate' => time() * 1000]));
        $autre = FabriqueApple::base64url((string) json_encode(['productId' => 'ngonicaisse.pro.annuel', 'signedDate' => time() * 1000]));
        $this->refuse($entete.'.'.$autre.'.'.$signature, 'Signature invalide.');

        [$entete, $contenu] = explode('.', self::apple()->jws(['signedDate' => time() * 1000]));
        $this->refuse($entete.'.'.$contenu.'.'.FabriqueApple::base64url(random_bytes(64)), 'Signature invalide.');
        $this->refuse($entete.'.'.$contenu.'.'.FabriqueApple::base64url(random_bytes(70)), 'Signature invalide.');
    }

    public function test_une_chaine_qui_ne_mene_pas_a_la_racine_d_apple_est_refusee(): void
    {
        $intrus = new FabriqueApple;

        // Sa propre chaîne : racine inconnue.
        $this->refuse($intrus->jws(['signedDate' => time() * 1000]), 'Racine inconnue.');

        // La vraie racine annoncée en bout de chaîne, sans en descendre.
        $chaine = [$intrus->x5c[0], $intrus->x5c[1], self::apple()->x5c[2]];
        $this->refuse($intrus->jws(['signedDate' => time() * 1000], $chaine), 'Chaîne de certificats non reconnue.');

        // La vraie chaîne annoncée, mais signé avec une autre clé.
        $this->refuse($intrus->jws(['signedDate' => time() * 1000], self::apple()->x5c), 'Signature invalide.');
    }

    public function test_sous_la_racine_d_apple_un_certificat_sans_ses_marqueurs_ne_signe_pas_un_achat(): void
    {
        // Ce que serait un certificat de développeur délivré sous la même racine.
        $developpeur = new FabriqueApple(marqueurs: false);

        $this->refuse($developpeur->jws(['signedDate' => time() * 1000]), 'Certificats sans les marqueurs d’Apple.', new JwsApple($developpeur->racine));
    }

    public function test_hors_de_la_periode_des_certificats_le_jws_est_refuse(): void
    {
        $this->refuse(self::apple()->jws(['signedDate' => (time() + 400 * 86400) * 1000]), 'Certificat hors de sa période de validité.');
        $this->refuse(self::apple()->jws(['signedDate' => (time() - 86400) * 1000]), 'Certificat hors de sa période de validité.');
    }

    public function test_un_jws_mal_forme_ou_d_un_autre_algorithme_est_refuse(): void
    {
        $this->refuse('pas un jws', 'JWS mal formé.');
        $this->refuse('a.b.c', 'Encodage illisible.');
        $this->refuse(FabriqueApple::base64url('pas du json').'.b.c', 'Contenu illisible.');
        $this->refuse(self::apple()->jws(['signedDate' => time() * 1000], alg: 'RS256'), 'Algorithme non accepté.');
        $this->refuse(self::apple()->jws(['signedDate' => time() * 1000], [self::apple()->x5c[0]]), 'Chaîne de certificats absente.');
        $this->refuse(self::apple()->jws(['signedDate' => time() * 1000], ['pas du base64 !', 'b', 'c']), 'Certificat illisible.');
    }

    public function test_sans_le_certificat_racine_sur_le_serveur_rien_n_est_accepte(): void
    {
        $this->refuse(self::apple()->jws(['signedDate' => time() * 1000]), 'Certificat racine d’Apple introuvable sur le serveur.', new JwsApple('/inexistant/AppleRootCA-G3.cer'));
    }

    public function test_la_racine_configuree_peut_etre_en_pem(): void
    {
        $pem = (string) tempnam(sys_get_temp_dir(), 'racine-pem-');
        file_put_contents($pem, "-----BEGIN CERTIFICATE-----\n".chunk_split(self::apple()->x5c[2], 64, "\n")."-----END CERTIFICATE-----\n");

        $contenu = ['signedDate' => time() * 1000];
        try {
            $this->assertSame($contenu, (new JwsApple($pem))->verifier(self::apple()->jws($contenu)));
        } finally {
            unlink($pem);
        }
    }
}
