<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

test('l\'app-version est accessible sans authentification', function () {
    $response = $this->getJson('/api/app-version');
    $response->assertSuccessful()
        ->assertJsonStructure(['latest_version']);
});
