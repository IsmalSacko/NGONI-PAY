<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_redirects_guests_to_login(): void
    {
        $response = $this->get('/');
        $response->assertRedirect('/tableau-de-bord');

        $response = $this->get('/tableau-de-bord');
        $response->assertRedirect('/connexion');
    }
}
