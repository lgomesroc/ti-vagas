<?php

namespace Tests\Unit;

use App\Services\Geo\Security\LocationProof;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

class LocationProofTest extends TestCase
{
    private LocationProof $proof;

    /** @var array<string, mixed> */
    private array $municipality = ['ibge' => '3303302', 'uf' => 'RJ'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->proof = new LocationProof();
    }

    private function makeSession(array $overrides = []): Store
    {
        $session = new Store('test', new ArraySessionHandler(120));

        $session->put(array_merge([
            'visitor_municipality_ibge' => '3303302',
            'visitor_state_code' => 'RJ',
            'location_latitude' => -22.8791,
            'location_longitude' => -43.1042,
            LocationProof::SESSION_KEY => $this->proof->issue(
                $this->municipality,
                -22.8791,
                -43.1042
            ),
        ], $overrides));

        return $session;
    }

    public function test_untouched_session_data_verifies(): void
    {
        $this->assertTrue($this->proof->verify($this->makeSession()));
    }

    public function test_changing_the_municipality_invalidates_the_proof(): void
    {
        $this->assertFalse($this->proof->verify(
            $this->makeSession(['visitor_municipality_ibge' => '5208707'])
        ));
    }

    public function test_changing_the_state_or_coordinates_invalidates_the_proof(): void
    {
        $this->assertFalse($this->proof->verify(
            $this->makeSession(['visitor_state_code' => 'GO'])
        ));

        $this->assertFalse($this->proof->verify(
            $this->makeSession(['location_latitude' => -16.6869])
        ));

        $this->assertFalse($this->proof->verify(
            $this->makeSession(['location_longitude' => -49.2648])
        ));
    }

    public function test_missing_or_malformed_proof_never_verifies(): void
    {
        $this->assertFalse($this->proof->verify(
            $this->makeSession([LocationProof::SESSION_KEY => null])
        ));

        $this->assertFalse($this->proof->verify(
            $this->makeSession([LocationProof::SESSION_KEY => 'forjado'])
        ));

        $this->assertFalse($this->proof->verify(
            $this->makeSession([LocationProof::SESSION_KEY => ['x']])
        ));

        $this->assertFalse($this->proof->verify(
            new Store('vazia', new ArraySessionHandler(120))
        ));
    }

    public function test_a_proof_issued_with_another_application_key_is_rejected(): void
    {
        $session = $this->makeSession();

        config(['app.key' => 'base64:' . base64_encode(str_repeat('x', 32))]);

        $this->assertFalse($this->proof->verify($session));
    }
}
