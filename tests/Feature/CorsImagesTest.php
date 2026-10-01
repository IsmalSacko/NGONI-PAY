<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class CorsImagesTest extends TestCase
{
    public function test_les_images_se_lisent_depuis_l_application_web(): void
    {
        $this->get('/images/logos/inconnue/vignette', ['Origin' => 'http://localhost:8090'])
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
