<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class LinkedInUrlInspector
{
    /**
     * Resolve redirecionamentos, bloqueia agregadores e extrai conteúdo
     * público da página de candidatura para aplicar os filtros do produto.
     *
     * @return array{
     *   valid: bool,
     *   verified: bool,
     *   blocked: bool,
     *   final_url: string,
     *   reason: ?string,
     *   page_title: ?string,
     *   page_description: ?string,
     *   page_text: ?string
     * }
     */
    public function inspect(string $url): array
    {
        $currentUrl = trim($url);

        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $parts = parse_url($currentUrl);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));

            if (
                !filter_var($currentUrl, FILTER_VALIDATE_URL)
                || !in_array($scheme, ['http', 'https'], true)
                || $host === ''
            ) {
                return $this->result(
                    false,
                    false,
                    false,
                    $currentUrl,
                    'URL inválida ou destino não público.'
                );
            }

            if ($this->isAggregator($host)) {
                return $this->result(
                    true,
                    true,
                    true,
                    $currentUrl,
                    'O link direciona para um agregador de vagas.'
                );
            }


            if (!$this->isPublicHost($host)) {
                return $this->result(
                    false,
                    false,
                    false,
                    $currentUrl,
                    'O destino não é um host público verificável.'
                );
            }
            try {
                $response = Http::withoutRedirecting()
                    ->timeout(15)
                    ->withHeaders([
                        'Range' => 'bytes=0-1048575',
                        'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                        'User-Agent' => 'TIVagas-Link-Checker/1.0',
                    ])
                    ->get($currentUrl);
            } catch (Throwable $exception) {
                return $this->result(
                    true,
                    false,
                    false,
                    $currentUrl,
                    'Não foi possível consultar o link: ' . $exception->getMessage()
                );
            }

            if ($response->redirect()) {
                $location = $response->header('Location');

                if (!is_string($location) || trim($location) === '') {
                    return $this->result(
                        true,
                        false,
                        false,
                        $currentUrl,
                        'Redirecionamento sem destino verificável.'
                    );
                }

                $currentUrl = $this->resolveUrl($currentUrl, trim($location));
                continue;
            }

            $finalHost = strtolower(
                (string) (parse_url($currentUrl, PHP_URL_HOST) ?? '')
            );

            if ($this->isAggregator($finalHost)) {
                return $this->result(
                    true,
                    true,
                    true,
                    $currentUrl,
                    'O redirecionamento termina em um agregador de vagas.'
                );
            }

            if (!$response->successful()) {
                return $this->result(
                    false,
                    true,
                    false,
                    $currentUrl,
                    'O destino respondeu com HTTP ' . $response->status() . '.'
                );
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            $page = str_contains($contentType, 'html')
                ? $this->extractHtml($response->body())
                : [
                    'page_title' => null,
                    'page_description' => null,
                    'page_text' => mb_substr(strip_tags($response->body()), 0, 30000),
                ];

            return $this->result(
                true,
                true,
                false,
                $currentUrl,
                null,
                $page
            );
        }

        return $this->result(
            true,
            false,
            false,
            $currentUrl,
            'O link excedeu o limite de cinco redirecionamentos.'
        );
    }

    /**
     * @return array{page_title: ?string, page_description: ?string, page_text: ?string}
     */
    private function extractHtml(string $html): array
    {
        $html = mb_substr($html, 0, 1_500_000);

        $title = null;
        $description = null;
        $text = null;

        if (class_exists(\DOMDocument::class)) {
            $previous = libxml_use_internal_errors(true);

            try {
                $document = new \DOMDocument();
                $document->loadHTML('<?xml encoding="UTF-8">' . $html);

                $titleNode = $document->getElementsByTagName('title')->item(0);
                $title = $titleNode?->textContent;

                foreach ($document->getElementsByTagName('meta') as $meta) {
                    $name = strtolower(
                        (string) ($meta->getAttribute('name') ?: $meta->getAttribute('property'))
                    );

                    if (in_array($name, [
                        'description',
                        'og:description',
                        'twitter:description',
                    ], true)) {
                        $description = $meta->getAttribute('content');
                        break;
                    }
                }

                foreach (['script', 'style', 'noscript', 'svg', 'iframe'] as $tag) {
                    $nodes = $document->getElementsByTagName($tag);
                    for ($index = $nodes->length - 1; $index >= 0; $index--) {
                        $node = $nodes->item($index);
                        $node?->parentNode?->removeChild($node);
                    }
                }

                $body = $document->getElementsByTagName('body')->item(0);
                $text = $body?->textContent;
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }

        $title = is_string($title) ? trim(preg_replace('/\s+/u', ' ', $title)) : null;
        $description = is_string($description)
            ? trim(preg_replace('/\s+/u', ' ', $description))
            : null;
        $text = is_string($text) ? trim(preg_replace('/\s+/u', ' ', $text)) : null;

        return [
            'page_title' => $title !== null && $title !== '' ? mb_substr($title, 0, 500) : null,
            'page_description' => $description !== null && $description !== '' ? mb_substr($description, 0, 3000) : null,
            'page_text' => $text !== null && $text !== '' ? mb_substr($text, 0, 30000) : null,
        ];
    }

    private function resolveUrl(string $base, string $location): string
    {
        if (str_starts_with($location, 'https://') || str_starts_with($location, 'http://')) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https') . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $directory = rtrim(substr($path, 0, (int) strrpos($path, '/') + 1), '/');

        return $origin . ($directory !== '' ? $directory : '') . '/' . $location;
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

        if ($resolvedIp === $host) {
            return false;
        }

        return filter_var(
            $resolvedIp,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * @param array{page_title: ?string, page_description: ?string, page_text: ?string}|null $page
     * @return array<string, mixed>
     */
    private function result(
        bool $valid,
        bool $verified,
        bool $blocked,
        string $finalUrl,
        ?string $reason,
        ?array $page = null
    ): array {
        return [
            'valid' => $valid,
            'verified' => $verified,
            'blocked' => $blocked,
            'final_url' => $finalUrl,
            'reason' => $reason,
            'page_title' => $page['page_title'] ?? null,
            'page_description' => $page['page_description'] ?? null,
            'page_text' => $page['page_text'] ?? null,
        ];
    }
}