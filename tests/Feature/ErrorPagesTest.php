<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        Route::get('/__tests/errors/{status}', function (string $status) {
            abort((int) $status);
        });

        Route::post('/__tests/errors/method', fn () => response('ok'));
    }

    public function test_bad_request_from_https_middleware_uses_the_friendly_error_page(): void
    {
        config(['app.force_https' => true]);

        $this->post('http://ti.test/boas-vindas/aceitar', ['terms' => '1'])
            ->assertStatus(400)
            ->assertSee('Não conseguimos entender sua solicitação');
    }

    public function test_not_found_uses_the_custom_page_and_preserves_the_status(): void
    {
        $this->get('/__tests/route-that-does-not-exist')
            ->assertNotFound()
            ->assertSee('Esta página não foi encontrada')
            ->assertSee('Ir para a página inicial');
    }

    public function test_method_not_allowed_uses_the_custom_page(): void
    {
        $this->get('/__tests/errors/method')
            ->assertStatus(405)
            ->assertSee('Esta ação não está disponível');
    }

    public function test_specific_client_error_pages_are_rendered(): void
    {
        $cases = [
            419 => 'Sua sessão expirou',
            429 => 'Recebemos muitas solicitações em pouco tempo',
        ];

        foreach ($cases as $status => $message) {
            $this->get('/__tests/errors/'.$status)
                ->assertStatus($status)
                ->assertSee($message);
        }
    }

    public function test_other_client_errors_use_the_4xx_fallback(): void
    {
        foreach ([401, 403, 408, 410, 422] as $status) {
            $this->get('/__tests/errors/'.$status)
                ->assertStatus($status)
                ->assertSee('Não foi possível concluir sua solicitação');
        }
    }

    public function test_internal_server_error_uses_the_custom_page_without_leaking_exception_details(): void
    {
        $response = $this->get('/__tests/errors/500');

        $response->assertStatus(500)
            ->assertSee('Tivemos um problema inesperado')
            ->assertDontSee('Symfony')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('vendor/laravel');
    }

    public function test_service_unavailable_uses_its_specific_page(): void
    {
        $this->get('/__tests/errors/503')
            ->assertStatus(503)
            ->assertSee('O TI Vagas está temporariamente indisponível');
    }

    public function test_other_server_errors_use_the_5xx_fallback(): void
    {
        foreach ([501, 502, 504] as $status) {
            $this->get('/__tests/errors/'.$status)
                ->assertStatus($status)
                ->assertSee('Estamos com uma instabilidade');
        }
    }
}
