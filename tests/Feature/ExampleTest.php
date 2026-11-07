<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_redirects_to_welcome_without_terms_acceptance(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('welcome'));
    }

    public function test_the_application_returns_a_successful_response_after_terms_acceptance(): void
    {
        $response = $this
            ->withCookie('ti_vagas_terms_accepted', '1.0')
            ->get('/');

        $response->assertOk();
    }
}
