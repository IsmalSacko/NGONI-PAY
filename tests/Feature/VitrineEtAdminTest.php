<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Produits\Index as ProduitsIndex;
use App\Mail\InscriptionMail;
use App\Models\Produit;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VitrineEtAdminTest extends TestCase
{
    use RefreshDatabase;

    private function inscrire(): array
    {
        return app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Pharmacie Les Castors', 'pays' => 'ML', 'telephone' => '+223 76 00 82 01',
            'email' => 'awa@example.com', 'password' => 'password123', 'nom_utilisateur' => 'Awa Traoré',
        ]);
    }

    private function exploitant(): User
    {
        $exploitant = User::create(['name' => 'Ismael', 'phone' => '+33605758494', 'password' => 'password123']);
        $exploitant->forceFill(['est_admin_plateforme' => true])->save();

        return $exploitant;
    }

    public function test_une_inscription_previent_l_exploitant_par_mail(): void
    {
        Mail::fake();

        ['user' => $awa, 'boutique' => $boutique] = $this->inscrire();

        Mail::assertSent(InscriptionMail::class, function (InscriptionMail $mail) use ($awa, $boutique) {
            $html = $mail->render();

            return $mail->hasTo(config('ecaisse.notification_email'))
                && $mail->nouveauCompte
                && $mail->user->is($awa)
                && str_contains($mail->envelope()->subject, 'Nouvelle inscription — Pharmacie Les Castors')
                && str_contains($html, 'Awa Traoré')
                && str_contains($html, 'https://wa.me/22376008201')
                && str_contains($html, '/plateforme/comptes')
                && $mail->boutique->is($boutique);
        });
    }

    public function test_un_mail_qui_echoue_n_empeche_pas_l_inscription(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP indisponible'));

        ['user' => $awa] = $this->inscrire();

        $this->assertNotNull($awa->fresh());
    }

    public function test_sitemap_et_robots_se_construisent_seuls(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=utf-8')
            ->assertSee('<loc>'.route('vitrine').'</loc>', false)
            ->assertSee('<loc>'.route('telecharger').'</loc>', false)
            ->assertSee('<loc>'.route('confidentialite').'</loc>', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /plateforme')
            ->assertSee('Disallow: /tableau-de-bord')
            ->assertSee('Sitemap: '.route('sitemap'));
    }

    public function test_la_vitrine_a_son_seo_ses_icones_le_partage_et_whatsapp(): void
    {
        $page = $this->get('/')->assertOk();

        $page->assertSee('rel="icon" href="/favicon.svg"', false)
            ->assertSee('rel="manifest" href="/site.webmanifest"', false)
            ->assertSee('og:image', false)
            ->assertSee('twitter:image', false)
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee('"@type":"Organization"', false)
            ->assertDontSee('noindex', false)
            // Partage : Facebook, WhatsApp, copier le lien.
            ->assertSee('https://www.facebook.com/sharer/sharer.php?u='.rawurlencode(route('vitrine')), false)
            ->assertSee('https://wa.me/?text=', false)
            ->assertSee('data-copier="'.route('vitrine').'"', false)
            // Contact WhatsApp avec la vraie icône, et le bouton flottant.
            ->assertSee('Nous écrire sur WhatsApp', false)
            ->assertSee('M17.472 14.382', false);

        foreach (['favicon.ico', 'favicon.svg', 'apple-touch-icon.png', 'icone-192.png', 'icone-512.png', 'site.webmanifest', 'images/og-ngoni-caisse.jpg'] as $fichier) {
            $this->assertGreaterThan(0, filesize(public_path($fichier)), "$fichier ne doit pas être vide");
        }
    }

    public function test_la_vitrine_annonce_le_parrainage_ses_conditions_et_la_fin_d_essai(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('id="parrainage"', false)
            ->assertSee('Les conditions pour en bénéficier')
            ->assertSee('14 jours d’essai au lieu de 7', false)
            ->assertSee('Au plus 12 mois offerts', false)
            ->assertSee('Statistiques avancées')
            ->assertSee('Que se passe-t-il à la fin de l’essai ?', false)
            ->assertSee('Abonnement à vie')
            ->assertSee('250 000', false);
    }

    public function test_la_console_ouvre_la_vitrine_en_apercu(): void
    {
        $exploitant = $this->exploitant();

        $this->actingAs($exploitant)->get('/plateforme')
            ->assertOk()
            ->assertSee('Voir le site')
            ->assertSee(route('vitrine', ['apercu' => 1]), false)
            ->assertSee('noindex', false);

        // Connecté, la vitrine renvoie à la console… sauf en aperçu.
        $this->actingAs($exploitant)->get('/')->assertRedirect('/plateforme');
        $this->actingAs($exploitant)->get('/?apercu=1')->assertOk()->assertSee('Ngoni Caisse');
    }

    public function test_le_back_office_ajoute_change_et_retire_la_photo_d_un_article(): void
    {
        Storage::fake('local');
        ['user' => $awa, 'boutique' => $boutique] = $this->inscrire();
        app(TenantContext::class)->setBoutique($boutique->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($boutique->id);
        $this->actingAs($awa);

        $this->get('/produits')->assertOk()->assertSee('noindex', false);

        Livewire::test(ProduitsIndex::class)
            ->call('nouveauProduit')
            ->assertSee('Ajouter une photo')
            ->set('nom', 'Riz 5 kg')
            ->set('prix_vente', '3500')
            ->set('photo', UploadedFile::fake()->image('riz.jpg', 400, 400))
            ->call('enregistrer')
            ->assertHasNoErrors()
            ->assertSet('modaleOuverte', false);

        $riz = Produit::where('nom', 'Riz 5 kg')->firstOrFail();
        $this->assertNotNull($riz->photo);
        Storage::disk('local')->assertExists($riz->photo);
        $ancienne = $riz->photo;

        Livewire::test(ProduitsIndex::class)
            ->call('modifier', $riz->id)
            ->assertSee('Changer la photo')
            ->call('retirerLaPhoto')
            ->assertSet('retirerPhoto', true)
            ->assertSee('Ajouter une photo')
            ->call('enregistrer');

        $this->assertNull($riz->fresh()->photo);
        Storage::disk('local')->assertMissing($ancienne);
    }
}
