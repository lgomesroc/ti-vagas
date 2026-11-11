<?php

namespace App\Services\Geo;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class IpLocationResolver
{
    private const LOOKUP_LIMITER = 'geo.ip-lookup';

    private const LOOKUPS_PER_MINUTE = 60;

    public function __construct(
        private readonly VisitorLocationResolver $visitorLocationResolver
    ) {
    }

    /**
     * Estimativa aproximada por IP. Nunca deve ser usada para liberar vagas locais.
     *
     * @return array{city: string, state_code: string, label: string}|null
     */
    public function resolve(Request $request): ?array
    {
        $ip = $request->ip();

        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP) || !$this->isPublicIp($ip)) {
            return null;
        }

        $cacheKey = 'geo.ip-location.' . hash('sha256', $ip);

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($ip): ?array {
            // Trava global, independente do IP informado pelo visitante: mesmo
            // com X-Forwarded-For forjado, o serviço externo recebe poucas
            // consultas por minuto. Acima disso, o aviso por IP é omitido.
            if (RateLimiter::tooManyAttempts(self::LOOKUP_LIMITER, self::LOOKUPS_PER_MINUTE)) {
                return null;
            }

            RateLimiter::hit(self::LOOKUP_LIMITER, 60);

            try {
                $response = Http::timeout(4)
                    ->connectTimeout(2)
                    ->acceptJson()
                    ->get('https://ipwho.is/' . rawurlencode($ip));

                if (!$response->successful()) {
                    return null;
                }

                $data = $response->json();

                if (
                    !is_array($data)
                    || ($data['success'] ?? false) !== true
                    || strtoupper((string) ($data['country_code'] ?? '')) !== 'BR'
                ) {
                    return null;
                }

                $stateCode = strtoupper((string) ($data['region_code'] ?? ''));
                $city = trim((string) ($data['city'] ?? ''));

                if (
                    !preg_match('/^[A-Z]{2}$/', $stateCode)
                    || $city === ''
                ) {
                    return null;
                }

                $municipality = $this->visitorLocationResolver->resolve(
                    (float) ($data['latitude'] ?? 0),
                    (float) ($data['longitude'] ?? 0),
                    $city,
                    $stateCode
                );

                if ($municipality === null) {
                    return [
                        'city' => $city,
                        'state_code' => $stateCode,
                        'label' => $city . ' - ' . $stateCode,
                    ];
                }

                return [
                    'city' => $municipality['name'],
                    'state_code' => $municipality['uf'],
                    'label' => $municipality['name'] . ' - ' . $municipality['uf'],
                ];
            } catch (Throwable) {
                return null;
            }
        });
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
