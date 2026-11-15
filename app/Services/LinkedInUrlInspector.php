<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class LinkedInUrlInspector
{
    /**
     * Resolve redirecionamentos e identifica destinos de agregadores.
     *
     * @return array{valid: bool, verified: bool, blocked: bool, final_url: string, reason: ?string}
     */
    public function inspect(string $url): array
    {
        $url = trim($url);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->result(false, false, false, $url, 'URL inválida.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (
            !in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || !$this->isPublicHost($host)
        ) {
            return $this->result(
                false,
                false,
                false,
                $url,
                'Destino não permitido para verificação.'
            );
        }

        if ($this->isAggregator($host)) {
            return $this->result(
                true,
                true,
                true,
                $url,
                'O link direciona para um agregador de vagas.'
            );
        }

        try {
            $response = Http::withOptions([
                'allow_redirects' => [
                    'max' => 5,
                    'track_redirects' => true,
                ],
            ])
                ->timeout(15)
                ->withHeaders(['Range' => 'bytes=0-0'])
                ->get($url);

            $finalUrl = $this->finalUrl($response, $url);
            $finalHost = strtolower(
                (string) (parse_url($finalUrl, PHP_URL_HOST) ?? '')
            );

            if ($finalHost !== '' && $this->isAggregator($finalHost)) {
                return $this->result(
                    true,
                    true,
                    true,
                    $finalUrl,
                    'O redirecionamento termina em um agregador de vagas.'
                );
            }

            if ($finalHost !== '' && !$this->isPublicHost($finalHost)) {
                return $this->result(
                    false,
                    true,
                    false,
                    $finalUrl,
                    'O redirecionamento aponta para um destino não permitido.'
                );
            }

            return $this->result(
                true,
                $response->successful(),
                false,
                $finalUrl,
                $response->successful()
                    ? null
                    : 'O destino respondeu com HTTP ' . $response->status() . '.'
            );
        } catch (Throwable $exception) {
            return $this->result(
                true,
                false,
                false,
                $url,
                'Não foi possível confirmar o destino do link: '
                    . $exception->getMessage()
            );
        }
    }

    private function finalUrl($response, string $originalUrl): string
    {
        $history = $response->header('X-Guzzle-Redirect-History');

        if (is_array($history) && $history !== []) {
            return (string) end($history);
        }

        if (is_string($history) && trim($history) !== '') {
            $urls = array_map('trim', explode(',', $history));

            return (string) end($urls);
        }

        return $originalUrl;
    }

    private function isAggregator(string $host): bool
    {
        foreach (config('linkedin.aggregator_domains', []) as $domain) {
            $domain = strtolower((string) $domain);

            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private function isPublicHost(string $host): bool
    {
        if (
            $host === 'localhost'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
        ) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        $resolvedIp = gethostbyname($host);

        if ($resolvedIp !== $host && filter_var($resolvedIp, FILTER_VALIDATE_IP)) {
            return filter_var(
                $resolvedIp,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        return true;
    }

    /**
     * @return array{valid: bool, verified: bool, blocked: bool, final_url: string, reason: ?string}
     */
    private function result(
        bool $valid,
        bool $verified,
        bool $blocked,
        string $finalUrl,
        ?string $reason
    ): array {
        return [
            'valid' => $valid,
            'verified' => $verified,
            'blocked' => $blocked,
            'final_url' => $finalUrl,
            'reason' => $reason,
        ];
    }
}