<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tutoriel;
use App\Models\User;
use App\Services\BoutiqueRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Plateforme\Tutoriels::class)
            ->call('nouveau')
            ->set('url', 'https://vimeo.com/1')->set('titre', 'Vente')->call('enregistrer')->assertHasErrors('url')
            ->set('url', 'https://youtu.be/dQw4w9WgXcQ')->call('enregistrer')->assertHasNoErrors()
            ->assertSee('Vente');

        $t = Tutoriel::sole();
        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Plateforme\Tutoriels::class)->call('basculer', $t->id);
        $this->assertFalse($t->fresh()->actif);
        \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\Plateforme\Tutoriels::class)->call('supprimer', $t->id);
        $this->assertSame(0, Tutoriel::count());
    }

    public function test_une_nouvelle_video_previent_les_commercants_concernes(): void
    {
        $restaurant = app(BoutiqueRegistrationService::class)->register([
            'nom' => 'Maquis Chez Awa', 'pays' => 'ML', 'telephone' => '76008202', 'password' => 'password123', 'nom_utilisateur' => 'Fanta',
        ]);
        $restaurant['boutique']->forceFill(['activite' => 'restaurant'])->save();

        // Une vidéo générale : tout le monde, et le toucher ouvre YouTube.
        $this->withToken($this->exploitant)->postJson('/api/plateforme/tutoriels', [
            'titre' => 'Enregistrer une vente', 'categorie' => 'ventes', 'url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertCreated()->assertJsonPath('message', fn ($m) => str_contains($m, 'prévenus'));
        $generale = \App\Models\NotificationApp::where('titre', '🎬 Nouvelle vidéo : Enregistrer une vente')->get();
        $this->assertCount(3, $generale);
        $this->assertSame('https://youtu.be/dQw4w9WgXcQ', $generale->first()->lien);

        // Une vidéo du module restaurant : les restaurants seulement.
        $this->withToken($this->exploitant)->postJson('/api/plateforme/tutoriels', [
            'titre' => 'Gérer les tables', 'categorie' => 'restaurant', 'url' => 'https://youtu.be/abcdefghijk',
        ])->assertCreated();
        $this->assertSame([$restaurant['user']->id], \App\Models\NotificationApp::where('titre', '🎬 Nouvelle vidéo : Gérer les tables')->pluck('user_id')->all());

        // Case décochée : rien n'est envoyé.
        $this->withToken($this->exploitant)->postJson('/api/plateforme/tutoriels', [
            'titre' => 'Correction', 'categorie' => 'ventes', 'url' => 'https://youtu.be/abcdefghijk', 'prevenir' => false,
        ])->assertCreated();
        $this->assertSame(0, \App\Models\NotificationApp::where('titre', 'like', '%Correction%')->count());
    }
}
