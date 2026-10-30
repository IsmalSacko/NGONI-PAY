<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Plateforme\Tutoriels;
use App\Models\NotificationApp;
use App\Models\Tutoriel;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Aide et tutoriels : gérés depuis la console, listés dans l'application. */
class TutorielsTest extends TestCase
{
    use RefreshDatabase;

    private string $exploitant;

    private string $commercant;

    protected function setUp(): void
    {
        parent::setUp();
        $inscrire = fn (string $tel) => app(BoutiqueRegistrationService::class)->register([
            'nom' => "Boutique {$tel}", 'pays' => 'ML', 'telephone' => $tel, 'password' => 'password123', 'nom_utilisateur' => 'Awa',
        ])['user'];

        $admin = $inscrire('73136789');
        $admin->forceFill(['est_admin_plateforme' => true])->save();
        $this->exploitant = $admin->createToken('app')->plainTextToken;
        $this->commercant = $inscrire('76008201')->createToken('app')->plainTextToken;
    }

    public function test_l_exploitant_ajoute_une_video_que_les_commercants_voient(): void
    {
        $this->withToken($this->exploitant)->postJson('/api/plateforme/tutoriels', [
            'titre' => 'Comment enregistrer une vente ?', 'sous_titre' => 'Guide des ventes', 'categorie' => 'ventes',
            'url' => 'https://youtu.be/dQw4w9WgXcQ?si=abc',
        ])->assertCreated()->assertJsonPath('data.miniature', 'https://img.youtube.com/vi/dQw4w9WgXcQ/mqdefault.jpg');

        $masquee = Tutoriel::create(['titre' => 'Brouillon', 'categorie' => 'stock', 'url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'actif' => false]);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->commercant)->getJson('/api/tutoriels')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titre', 'Comment enregistrer une vente ?')
            ->assertJsonPath('data.0.categorie_libelle', 'Ventes')
            ->assertJsonPath('categories.restaurant', 'Restaurant');

        // La console voit aussi les vidéos masquées, et peut les montrer.
        $this->app['auth']->forgetGuards();
        $this->withToken($this->exploitant)->getJson('/api/plateforme/tutoriels')->assertJsonCount(2, 'data');
        $this->withToken($this->exploitant)->putJson("/api/plateforme/tutoriels/{$masquee->id}", ['actif' => true])->assertOk();
        $this->withToken($this->exploitant)->deleteJson("/api/plateforme/tutoriels/{$masquee->id}")->assertOk();
        $this->assertSame(1, Tutoriel::count());
    }

    public function test_seul_un_lien_youtube_est_accepte_et_seul_l_exploitant_gere(): void
    {
        $this->withToken($this->exploitant)->postJson('/api/plateforme/tutoriels', [
            'titre' => 'Vidéo', 'categorie' => 'ventes', 'url' => 'https://vimeo.com/123456',
        ])->assertUnprocessable()->assertJsonValidationErrors('url');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->commercant)->postJson('/api/plateforme/tutoriels', [
            'titre' => 'Vidéo', 'categorie' => 'ventes', 'url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertForbidden();
    }

    public function test_les_formes_de_liens_youtube_sont_reconnues(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://m.youtube.com/watch?feature=share&v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
        ] as $url) {
            $this->assertSame('dQw4w9WgXcQ', Tutoriel::idYoutube($url), $url);
        }
    }

    public function test_la_console_web_ajoute_masque_et_supprime_une_video(): void
    {
        $admin = User::where('est_admin_plateforme', true)->firstOrFail();
        $this->actingAs($admin)->get('/plateforme/tutoriels')->assertOk()->assertSee('Tutoriels');

        Livewire::actingAs($admin)->test(Tutoriels::class)
            ->call('nouveau')
            ->set('url', 'https://vimeo.com/1')->set('titre', 'Vente')->call('enregistrer')->assertHasErrors('url')
            ->set('url', 'https://youtu.be/dQw4w9WgXcQ')->call('enregistrer')->assertHasNoErrors()
            ->assertSee('Vente');

        $t = Tutoriel::sole();
        Livewire::actingAs($admin)->test(Tutoriels::class)->call('basculer', $t->id);
        $this->assertFalse($t->fresh()->actif);
        Livewire::actingAs($admin)->test(Tutoriels::class)->call('supprimer', $t->id);
        $this->assertSame(0, Tutoriel::count());
    }

    public function test_les_nouvelles_videos_partent_dans_un_seul_resume_le_lundi(): void
    {
        $restaurant = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Maquis Chez Awa', 'pays' => 'ML', 'telephone' => '76008202', 'password' => 'password123', 'nom_utilisateur' => 'Fanta',
        ]);
        $restaurant['boutique']->forceFill(['activite' => 'restaurant'])->save();
        // Les annonces vont aux commerçants vus dans l'application depuis 14 jours.
        User::query()->update(['vu_le' => now()]);

        // Publier ne notifie plus personne : les vidéos attendent le résumé.
        foreach ([['Enregistrer une vente', 'ventes', 'aaaaaaaaaaa'], ['Ajouter un article', 'stock', 'bbbbbbbbbbb'], ['Gérer les tables', 'restaurant', 'ccccccccccc']] as [$titre, $categorie, $id]) {
            $this->withToken($this->exploitant)->postJson('/api/plateforme/tutoriels', [
                'titre' => $titre, 'categorie' => $categorie, 'url' => "https://youtu.be/{$id}",
            ])->assertCreated()->assertJsonPath('message', fn ($m) => str_contains($m, 'lundi'));
        }
        $this->withToken($this->exploitant)->postJson('/api/plateforme/tutoriels', [
            'titre' => 'Correction', 'categorie' => 'ventes', 'url' => 'https://youtu.be/ddddddddddd', 'prevenir' => false,
        ])->assertCreated();
        // (Seules les alertes d'inscription de l'exploitant existent à ce stade.)
        $this->assertSame(0, NotificationApp::whereNotNull('annonce_id')->count());

        // Le lundi : une seule annonce par commerçant. Les autres reçoivent les deux
        // vidéos générales ; le restaurant, ces deux-là et la sienne ensemble.
        $this->artisan('ecaisse:resume-videos')->assertSuccessful();
        $generale = NotificationApp::where('titre', '🎬 2 nouvelles vidéos d’aide')->get();
        $this->assertGreaterThan(0, $generale->count());
        $this->assertNotContains($restaurant['user']->id, $generale->pluck('user_id'));
        $this->assertSame('/aide', $generale->first()->lien);
        $this->assertStringContainsString('Enregistrer une vente · Ajouter un article', $generale->first()->message);
        $resto = NotificationApp::where('user_id', $restaurant['user']->id)->whereNotNull('annonce_id')->get();
        $this->assertCount(1, $resto);
        $this->assertSame('🎬 3 nouvelles vidéos d’aide', $resto->first()->titre);
        $this->assertStringContainsString('Gérer les tables', $resto->first()->message);
        $this->assertSame(0, NotificationApp::where('message', 'like', '%Correction%')->count());

        // La semaine suivante, rien de nouveau : rien ne part.
        $avant = NotificationApp::whereNotNull('annonce_id')->count();
        $this->artisan('ecaisse:resume-videos')->assertSuccessful();
        $this->assertSame($avant, NotificationApp::whereNotNull('annonce_id')->count());
    }

    public function test_annoncer_maintenant_previent_sans_attendre_le_lundi(): void
    {
        $restaurant = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Maquis Chez Awa', 'pays' => 'ML', 'telephone' => '76008202', 'password' => 'password123', 'nom_utilisateur' => 'Fanta',
        ]);
        $restaurant['boutique']->forceFill(['activite' => 'restaurant'])->save();
        User::query()->update(['vu_le' => now()]);

        $id = $this->withToken($this->exploitant)->postJson('/api/plateforme/tutoriels', [
            'titre' => 'Transfert d’argent', 'sous_titre' => 'Activer et noter un dépôt', 'categorie' => 'ventes', 'url' => 'https://youtu.be/aaaaaaaaaaa',
        ])->assertCreated()->assertJsonPath('data.en_attente', true)->json('data.id');

        $reponse = $this->withToken($this->exploitant)->postJson("/api/plateforme/tutoriels/{$id}/annoncer")->assertOk()
            ->assertJsonPath('data.en_attente', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'annoncée à'));
        $notifiees = NotificationApp::where('titre', '🎬 Nouvelle vidéo : Transfert d’argent')->get();
        $this->assertSame($reponse->json('destinataires'), $notifiees->count());
        $this->assertContains($restaurant['user']->id, $notifiees->pluck('user_id'), 'une vidéo générale va aussi au restaurant');
        $this->assertSame('https://youtu.be/aaaaaaaaaaa', $notifiees->first()->lien);

        // Déjà annoncée : le résumé du lundi ne la renvoie pas.
        $avant = NotificationApp::whereNotNull('annonce_id')->count();
        $this->artisan('ecaisse:resume-videos')->assertSuccessful();
        $this->assertSame($avant, NotificationApp::whereNotNull('annonce_id')->count());

        // Une vidéo d'un module ne part qu'aux boutiques de cette activité.
        $tables = Tutoriel::create(['titre' => 'Gérer les tables', 'categorie' => 'restaurant', 'url' => 'https://youtu.be/ccccccccccc', 'actif' => true]);
        $this->travel(21)->hours();
        $this->withToken($this->exploitant)->postJson("/api/plateforme/tutoriels/{$tables->id}/annoncer")->assertOk()->assertJsonPath('destinataires', 1);
        $this->assertSame([$restaurant['user']->id], NotificationApp::where('titre', '🎬 Nouvelle vidéo : Gérer les tables')->pluck('user_id')->all());

        // Masquée : rien ne part ; et seul l'exploitant annonce.
        $tables->update(['actif' => false]);
        $this->withToken($this->exploitant)->postJson("/api/plateforme/tutoriels/{$tables->id}/annoncer")->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->commercant)->postJson("/api/plateforme/tutoriels/{$id}/annoncer")->assertForbidden();
    }

    public function test_la_console_web_annonce_une_video_maintenant(): void
    {
        User::query()->update(['vu_le' => now()]);
        $admin = User::where('est_admin_plateforme', true)->firstOrFail();
        $t = Tutoriel::create(['titre' => 'Transfert d’argent', 'categorie' => 'ventes', 'url' => 'https://youtu.be/aaaaaaaaaaa', 'actif' => true]);

        Livewire::actingAs($admin)->test(Tutoriels::class)
            ->assertSee('Annoncer maintenant')
            ->call('annoncer', $t->id)
            ->assertSee('annoncée à')
            ->assertSee('annoncée le '.now()->format('d/m'));
        $this->assertGreaterThan(0, NotificationApp::where('titre', '🎬 Nouvelle vidéo : Transfert d’argent')->count());
    }
}
