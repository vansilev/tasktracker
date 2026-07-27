<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationUnavailableTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_route_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
