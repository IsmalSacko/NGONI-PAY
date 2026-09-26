<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Country;
use App\Support\Phone\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le numéro est l'identifiant de connexion : sa mise en forme décide qui peut
 * entrer. Cas repris de Ngoni Pay, dont les comptes migrent ici.
 */
class PaysEtTelephoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_numero_local_prend_l_indicatif_du_pays(): void
    {
        $this->assertSame('+22376008201', PhoneNumber::normalize('76008201', Country::Mali));
        $this->assertSame('+221771234567', PhoneNumber::normalize('771234567', Country::Senegal));
    }

    public function test_toutes_les_ecritures_d_un_numero_se_rejoignent(): void
    {
        foreach (['76008201', '76 00 82 01', '22376008201', '+223 76 00 82 01', '0022376008201'] as $saisie) {
            $this->assertSame('+22376008201', PhoneNumber::normalize($saisie, Country::Mali), $saisie);
        }
    }

    public function test_le_zero_qui_appartient_au_numero_est_garde(): void
    {
        $this->assertSame('+2250708123456', PhoneNumber::normalize('0708123456', Country::IvoryCoast));
        $this->assertSame('+2250708123456', PhoneNumber::normalize('07 08 12 34 56', Country::IvoryCoast));
    }

    public function test_un_numero_international_n_est_pas_reecrit(): void
    {
        $this->assertSame('+33605758494', PhoneNumber::normalize('+33 6 05 75 84 94', Country::Mali));
        $this->assertSame('+233533222270', PhoneNumber::normalize('+233533222270', Country::Mali));
    }

    public function test_les_pays_des_comptes_ngoni_pay_sont_connus(): void
    {
        foreach (['ML', 'CI', 'SN', 'BF', 'GA', 'GH', 'CM', 'FR', 'IT', 'BJ', 'TG'] as $code) {
            $this->assertNotNull(Country::tryFrom($code), $code);
        }
    }

    public function test_la_liste_des_pays_est_publique(): void
    {
        $this->getJson('/api/pays')
            ->assertOk()
            ->assertJsonPath('defaut', 'ML')
            ->assertJsonFragment(['code' => 'CI', 'indicatif' => '225', 'devise' => 'XOF'])
            ->assertJsonFragment(['code' => 'GN', 'devise' => 'GNF']);
    }

    public function test_la_version_d_application_garde_le_format_de_ngoni_pay(): void
    {
        // Les applications Ngoni Pay 1.x lisent ces trois champs pour proposer,
        // puis imposer, la mise à jour.
        $this->getJson('/api/app-version')
            ->assertOk()
            ->assertExactJson([
                'latest_version' => '2.0.0',
                'store_url' => 'https://play.google.com/store/apps/details?id=com.ismaeldev.ngonipay',
                'minimum_version' => '2.0.0',
            ]);
    }
}
