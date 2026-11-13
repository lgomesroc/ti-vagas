<?php

namespace App\Services\Geo\Security;

use Illuminate\Contracts\Session\Session;

/**
 * Selo de integridade da localização guardada na sessão.
 *
 * A região só é usada para filtrar vagas se a sessão trouxer um selo (HMAC
 * com a chave da aplicação) que confirme que o município e as coordenadas
 * foram gravados por este servidor depois de validados. Dados de sessão
 * escritos por outro caminho (bug, sessão restaurada de backup, driver de
 * sessão comprometido) não têm selo válido e são tratados como ausentes.
 *
 * Limite: o selo prova a origem dos dados, não que o GPS era verdadeiro.
 */
class LocationProof
{
    public const SESSION_KEY = 'location_proof';

    private const VERSION = 'v1';

    /**
     * @param array<string, mixed> $municipality
     */
    public function issue(array $municipality, float $latitude, float $longitude): string
    {
        return $this->sign(
            (string) $municipality['ibge'],
            (string) $municipality['uf'],
            $latitude,
            $longitude
        );
    }

    /**
     * Confere os dados de localização da sessão contra o selo gravado.
     */
    public function verify(Session $session): bool
    {
        $proof = $session->get(self::SESSION_KEY);
        $ibge = $session->get('visitor_municipality_ibge');
        $uf = $session->get('visitor_state_code');
        $latitude = $session->get('location_latitude');
        $longitude = $session->get('location_longitude');

        if (
            !is_string($proof)
            || !is_string($ibge)
            || !is_string($uf)
            || !is_numeric($latitude)
            || !is_numeric($longitude)
        ) {
            return false;
        }

        return hash_equals(
            $this->sign($ibge, $uf, (float) $latitude, (float) $longitude),
            $proof
        );
    }

    private function sign(string $ibge, string $uf, float $latitude, float $longitude): string
    {
        $payload = implode('|', [
            self::VERSION,
            $ibge,
            strtoupper($uf),
            sprintf('%.7F', $latitude),
            sprintf('%.7F', $longitude),
        ]);

        return hash_hmac('sha256', $payload, $this->key());
    }

    private function key(): string
    {
        return 'location-proof|' . (string) config('app.key');
    }
}
