<?php

use App\Livewire\Admin\Announcements\Index as AnnouncementsIndex;
use App\Mail\AppUpdateMail;
use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Annoncer une mise à jour demandait un déploiement : le contenu du courriel
 * vivait dans une classe. Il se rédige désormais depuis la console, et part par
 * deux canaux — le courriel pour ceux qui ont une adresse, la notification dans
 * l'application pour tout le monde. Beaucoup de commerçants s'inscrivent sans
 * adresse : le second canal n'est pas un supplément.
 */
beforeEach(function () {
    Mail::fake();

    $this->admin = User::factory()->create([
        'phone' => '+22370000009',
        'role' => User::ROLE_SYSTEM_ADMIN,
    ]);

    $this->avecEmail = User::factory()->create([
        'name' => 'Awa Koné',
        'phone' => '+2250708123456',
        'email' => 'awa@example.test',
        'role' => 'owner',
    ]);

    $this->sansEmail = User::factory()->create([
        'name' => 'Moussa Diarra',
        'phone' => '+22376008201',
        'email' => null,
        'role' => 'owner',
    ]);

    $this->desactive = User::factory()->create([
        'name' => 'Compte fermé',
        'phone' => '+22376009999',
        'email' => 'ferme@example.test',
        'role' => 'owner',
        'is_active' => false,
    ]);
});

it('notifie tout le monde et n’envoie de courriel qu’à qui en a une', function () {
    Livewire::actingAs($this->admin)
        ->test(AnnouncementsIndex::class)
        ->set('version', '1.0.7')
        ->set('subject', 'NGONI PAY 1.0.7')
        ->set('message', 'Le choix du pays et des devises arrive.')
        ->set('audience', 'all')
        ->set('timing', 'now')
        ->call('send');

    // Notification pour les deux comptes actifs, adresse ou pas.
    expect(AppNotification::where('type', 'app.update')->count())->toBe(2)
        ->and(AppNotification::where('user_id', $this->sansEmail->id)->exists())->toBeTrue();

    // Courriel pour le seul qui a une adresse.
    Mail::assertSent(AppUpdateMail::class, 1);
    Mail::assertSent(
        AppUpdateMail::class,
        fn (AppUpdateMail $mail) => $mail->hasTo('awa@example.test'),
    );

    // Le compte désactivé est écarté : le relancer pour une mise à jour qu'il ne
    // pourra pas utiliser n'a pas de sens.
    expect(AppNotification::where('user_id', $this->desactive->id)->exists())->toBeFalse();
});

it('n’adresse l’annonce qu’aux destinataires cochés', function () {
    Livewire::actingAs($this->admin)
        ->test(AnnouncementsIndex::class)
        ->set('message', 'Mise à jour ciblée.')
        ->set('audience', 'selected')
        ->set('selected', [$this->sansEmail->id])
        ->set('timing', 'now')
        ->call('send');

    expect(AppNotification::where('user_id', $this->sansEmail->id)->exists())->toBeTrue()
        ->and(AppNotification::where('user_id', $this->avecEmail->id)->exists())->toBeFalse();

    Mail::assertNothingSent();
});

it('refuse une sélection vide', function () {
    Livewire::actingAs($this->admin)
        ->test(AnnouncementsIndex::class)
        ->set('audience', 'selected')
        ->set('selected', [])
        ->call('send')
        ->assertHasErrors('selected');

    expect(AppNotification::count())->toBe(0);
});

it('coche et décoche tout le monde d’un geste', function () {
    // Cocher page par page ferait manquer des utilisateurs sans qu'on le
    // remarque : le geste porte sur toute la base filtrée.
    $composant = Livewire::actingAs($this->admin)
        ->test(AnnouncementsIndex::class)
        ->set('audience', 'selected')
        ->call('toggleAll');

    // Les deux commerçants actifs : ni le compte désactivé, ni l'administrateur
    // qui envoie l'annonce.
    expect($composant->get('selected'))->toHaveCount(2);

    $composant->call('toggleAll');

    expect($composant->get('selected'))->toHaveCount(0);
});

it('programme une annonce sans l’envoyer', function () {
    Livewire::actingAs($this->admin)
        ->test(AnnouncementsIndex::class)
        ->set('message', 'Annonce de demain.')
        ->set('timing', 'scheduled')
        ->set('scheduledAt', now()->addDay()->format('Y-m-d\TH:i'))
        ->call('send')
        ->assertHasNoErrors();

    $campagne = Campaign::where('type', 'app_update')->firstOrFail();

    expect($campagne->status)->toBe('scheduled')
        ->and($campagne->scheduled_at)->not->toBeNull();

    // Rien n'est parti : c'est le planificateur qui s'en chargera.
    Mail::assertNothingSent();
    expect(AppNotification::count())->toBe(0);
});

it('refuse une programmation dans le passé', function () {
    // Elle partirait à la minute suivante sans qu'on l'ait voulu.
    Livewire::actingAs($this->admin)
        ->test(AnnouncementsIndex::class)
        ->set('message', 'Trop tard.')
        ->set('timing', 'scheduled')
        ->set('scheduledAt', now()->subHour()->format('Y-m-d\TH:i'))
        ->call('send')
        ->assertHasErrors('scheduledAt');
});

it('envoie les annonces programmées dont l’heure est venue', function () {
    $campagne = Campaign::create([
        'key' => 'app-update-test',
        'name' => 'Mise à jour test',
        'type' => Campaign::TYPE_APP_UPDATE,
        'subject' => 'Nouvelle version',
        'message' => 'Mettez à jour.',
        'version' => '1.0.8',
        'store_url' => 'https://ngonipay.ismael-dev.com/telecharger',
        'audience' => Campaign::AUDIENCE_ALL,
        'status' => Campaign::STATUS_SCHEDULED,
        'scheduled_at' => now()->subMinute(),
    ]);

    $this->artisan('announcements:dispatch-due')->assertSuccessful();

    expect($campagne->fresh()->status)->toBe('sent');
    Mail::assertSent(AppUpdateMail::class, 1);
    expect(AppNotification::where('type', 'app.update')->count())->toBe(2);
});

it('n’envoie pas une annonce dont l’heure n’est pas arrivée', function () {
    Campaign::create([
        'key' => 'app-update-plus-tard',
        'name' => 'Plus tard',
        'type' => Campaign::TYPE_APP_UPDATE,
        'subject' => 'Plus tard',
        'message' => 'Plus tard.',
        'audience' => Campaign::AUDIENCE_ALL,
        'status' => Campaign::STATUS_SCHEDULED,
        'scheduled_at' => now()->addHours(3),
    ]);

    $this->artisan('announcements:dispatch-due')->assertSuccessful();

    Mail::assertNothingSent();
    expect(AppNotification::count())->toBe(0);
});

it('n’empile pas deux fois la même annonce dans la cloche', function () {
    $campagne = Campaign::create([
        'key' => 'app-update-rejeu',
        'name' => 'Rejeu',
        'type' => Campaign::TYPE_APP_UPDATE,
        'subject' => 'Version 1.0.9',
        'message' => 'Mettez à jour.',
        'version' => '1.0.9',
        'audience' => Campaign::AUDIENCE_ALL,
        'status' => Campaign::STATUS_DRAFT,
    ]);

    $dispatcher = app(\App\Services\AnnouncementDispatcher::class);

    $dispatcher->dispatch($campagne);
    $dispatcher->dispatch($campagne);

    expect(AppNotification::where('type', 'app.update')->count())->toBe(2);
});

it('publie une page de téléchargement munie de balises Open Graph', function () {
    // Sans elles, l'annonce partagée par un commerçant à ses collègues ne
    // ressemble à rien : une adresse nue.
    $this->get('/telecharger')
        ->assertOk()
        ->assertSee('og:title', false)
        ->assertSee('og:image', false)
        ->assertSee('og:description', false)
        ->assertSee('twitter:card', false)
        ->assertSee('images/og-ngonipay.png', false);
});

it('sert le lien de téléchargement avec la version courante', function () {
    $this->getJson('/api/app-version')
        ->assertOk()
        ->assertJsonStructure(['latest_version', 'store_url', 'minimum_version'])
        ->assertJsonPath('store_url', url('/telecharger'));
});
