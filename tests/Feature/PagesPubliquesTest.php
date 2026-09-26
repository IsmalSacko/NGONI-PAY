<?php

namespace Tests\Feature;

use Tests\TestCase;

class PagesPubliquesTest extends TestCase
{
    public function test_la_politique_de_confidentialite_citee_par_le_store_repond(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Politique de confidentialité');
    }

    public function test_la_page_de_telechargement_mene_au_store(): void
    {
        $this->get('/telecharger')
            ->assertOk()
            ->assertSee(config('mobile.store_url'), false)
            ->assertSee('og:image', false);
    }

    public function test_les_anciennes_adresses_du_panneau_redirigent(): void
    {
        $this->get('/login')->assertRedirect('/connexion');
        $this->get('/admin')->assertRedirect('/plateforme');
        $this->get('/admin/users')->assertRedirect('/plateforme');
    }
}
