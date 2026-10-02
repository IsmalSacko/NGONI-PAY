<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Les écrans se testent sans passer par l'acceptation des conditions ;
        // ConditionsUtilisationTest la remet pour vérifier le blocage.
        config(['conditions.exiger' => false]);
    }
}
