<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class VisitorLocationResolver
{
    public function resolve(Request $request): ?array
    {
        $ip = $request->ip();

        if (
            !$ip ||
            !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )
        ) {
            return null;
        }

        $cacheKey = 'visitor-location:' . hash('sha256', $ip);

        $location = Cache::remember(
            $cacheKey,
            now()->addDay(),
            function () use ($ip): array {
                try {
                    $response = Http::acceptJson()
                        ->timeout(5)
                        ->get('https://ipwho.is/' . $ip);

                    if (!$response->successful()) {
                        return ['unavailable' => true];
                    }

                    $data = $response->json();

                    if (
                        !($data['success'] ?? false) ||
                        strtoupper($data['country_code'] ?? '') !== 'BR' ||
                        empty($data['city']) ||
                        empty($data['region_code'])
                    ) {
                        return ['unavailable' => true];
                    }

                    return [
                        'city' => $data['city'],
                        'state_code' => strtoupper($data['region_code']),
                        'country_code' => 'BR',
                    ];
                } catch (\Throwable $exception) {
                    report($exception);

                    return ['unavailable' => true];
                }
            }
        );

        if ($location['unavailable'] ?? false) {
            return null;
        }

        return $location;
    }
}
