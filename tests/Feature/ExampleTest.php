<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_redirects_guests_to_login(): void
    {
        // La racine est le site vitrine pour un visiteur.
        $this->get('/')->assertOk()->assertSee('Ngoni Caisse')->assertSee('4 000')->assertSee('10 000');

        $response = $this->get('/tableau-de-bord');
        $response->assertRedirect('/connexion');
    }
}
