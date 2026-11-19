<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LinkedInPostSearchClient
{
    private const LINKEDIN_QUERY = '(site:linkedin.com/posts OR site:linkedin.com/feed/update)';

    /**
     * Combina resultados de Bing RSS, DuckDuckGo, Brave Search e Mojeek.
     * Bing RSS e DuckDuckGo não exigem chave; Brave e Mojeek usam APIs oficiais.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(): array
    {
        $keywords = array_values(array_unique(array_filter(
            config('linkedin.keywords', []),
            fn ($keyword): bool => is_string($keyword) && trim($keyword) !== ''
        )));

        if ($keywords === []) {
            throw new RuntimeException('Nenhuma palavra-chave do LinkedIn foi configurada.');
        }

        $posts = [];

        foreach ($keywords as $keyword) {
            $query = self::LINKEDIN_QUERY . ' "' . $keyword . '"';

            foreach ([
                fn () => $this->searchBingRss($query, $keyword),
                fn () => $this->searchDuckDuckGo($query, $keyword),
                fn () => $this->searchBrave($query, $keyword),
                fn () => $this->searchMojeek($query, $keyword),
            ] as $searchProvider) {
                try {
                    foreach ($searchProvider() as $post) {
                        $url = $post['postUrl'];
                        if (!isset($posts[$url])) {
                            $posts[$url] = $post;
                        } else {
                            $providers = $posts[$url]['searchProviders'] ?? [
                                $posts[$url]['searchProvider'],
                            ];
                            $providers[] = $post['searchProvider'];
                            $posts[$url]['searchProviders'] = array_values(array_unique($providers));
                        }
                    }
                } catch (Throwable) {
                    // Falha de um buscador não interrompe os demais.
                    continue;
                }
            }

            usleep(500000);
        }

        return array_values($posts);
    }

    /** @return array<int, array<string, mixed>> */
    private function searchBingRss(string $query, string $keyword): array
    {
        $response = Http::withOptions($this->tlsOptions())->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (compatible; TIVagas/1.0)',
            'Accept' => 'application/rss+xml, application/xml, text/xml',
            'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
        ])->timeout(20)->get('https://www.bing.com/search', [
            'q' => $query,
            'format' => 'rss',
            'setlang' => 'pt-BR',
        ]);

        if (!$response->successful()) {
            return [];
        }

        if (!class_exists(\DOMDocument::class)) {
            throw new RuntimeException('A extensão PHP DOM/XML é necessária para ler os resultados.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($response->body())) {
                return [];
            }

            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query('//item');
            $posts = [];

            foreach ($nodes ?: [] as $node) {
                $title = trim($xpath->query('./title', $node)?->item(0)?->textContent ?? '');
                $url = trim($xpath->query('./link', $node)?->item(0)?->textContent ?? '');
                $snippet = trim($xpath->query('./description', $node)?->item(0)?->textContent ?? '');
                $pubDate = trim($xpath->query('./pubDate', $node)?->item(0)?->textContent ?? '');

                $post = $this->makePost($url, $title, $snippet, $keyword, 'bing-rss');
                if ($post === null) {
                    continue;
                }

                $publishedAt = $this->parsePublishedAt($pubDate)
                    ?? $this->extractPublishedAt($title . "\n" . $snippet);

                if ($publishedAt === null || !$this->isWithinLookback($publishedAt)) {
                    continue;
                }

                $post['datePosted'] = $publishedAt->toIso8601String();
                $posts[] = $post;
            }

            return $posts;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function parsePublishedAt(string $date): ?Carbon
    {
        if ($date === '') {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function searchDuckDuckGo(string $query, string $keyword): array
    {
        $response = Http::withOptions($this->tlsOptions())->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (compatible; TIVagas/1.0)',
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
        ])->timeout(20)->get('https://html.duckduckgo.com/html/', [
            'q' => $query,
            'kl' => 'br-pt',
        ]);

        if (!$response->successful()) {
            return [];
        }

        return $this->parseDuckDuckGoHtml($response->body(), $keyword);
    }

    /** @return array<int, array<string, mixed>> */
    private function searchBrave(string $query, string $keyword): array
    {
        $apiKey = trim((string) config('linkedin.brave_api_key'));
        if ($apiKey === '') {
            return [];
        }

        $response = Http::withOptions($this->tlsOptions())->withHeaders([
            'X-Subscription-Token' => $apiKey,
            'Accept' => 'application/json',
            'Accept-Encoding' => 'gzip',
        ])->timeout(20)->get('https://api.search.brave.com/res/v1/web/search', [
            'q' => $query,
            'country' => 'BR',
            'search_lang' => 'pt',
            'ui_lang' => 'pt-BR',
            'freshness' => 'pw',
            'count' => 20,
        ]);

        if (!$response->successful()) {
            return [];
        }

        $results = $response->json('web.results', []);

        return $this->normalizeApiResults(
            is_array($results) ? $results : [],
            $keyword,
            'brave-api',
            'url',
            'title',
            'description'
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function searchMojeek(string $query, string $keyword): array
    {
        $apiKey = trim((string) config('linkedin.mojeek_api_key'));
        if ($apiKey === '') {
            return [];
        }

        $response = Http::withOptions($this->tlsOptions())->withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'TIVagas/1.0',
        ])->timeout(20)->get('https://www.mojeek.com/search', [
            'api_key' => $apiKey,
            'q' => $query,
            'fmt' => 'json',
            't' => 20,
            'rb' => 'BR',
            'rbb' => 10,
            'lb' => 'PT',
            'lbb' => 100,
            'dlen' => 511,
        ]);

        if (!$response->successful()) {
            return [];
        }

        $results = $response->json('response.results', []);

        return $this->normalizeApiResults(
            is_array($results) ? $results : [],
            $keyword,
            'mojeek-api',
            'url',
            'title',
            'desc'
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function parseDuckDuckGoHtml(string $html, string $keyword): array
    {
        if (!class_exists(\DOMDocument::class)) {
            throw new RuntimeException('A extensão PHP DOM/XML é necessária para ler os resultados.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument();
            if (!$document->loadHTML($html)) {
                return [];
            }

            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " result__a ")]');
            $results = [];

            foreach ($nodes ?: [] as $node) {
                $url = $this->resolveDuckDuckGoUrl(
                    trim($node->getAttribute('href'))
                );

                $container = $node->parentNode?->parentNode;
                $snippetNode = $container instanceof \DOMNode
                    ? $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " result__snippet ")]', $container)?->item(0)
                    : null;
                $snippet = trim($snippetNode?->textContent ?? '');

                $post = $this->makePost(
                    $url,
                    trim($node->textContent),
                    $snippet,
                    $keyword,
                    'duckduckgo-html'
                );

                if ($post !== null) {
                    $results[] = $post;
                }
            }

            return $results;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function resolveDuckDuckGoUrl(string $url): string
    {
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_ends_with($host, 'duckduckgo.com')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (isset($query['uddg']) && is_string($query['uddg'])) {
                return $query['uddg'];
            }
        }

        return $url;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function normalizeApiResults(
        array $results,
        string $keyword,
        string $provider,
        string $urlKey,
        string $titleKey,
        string $snippetKey,
        bool $usePublishedTimestamp = false
    ): array {
        $posts = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $url = trim((string) ($result[$urlKey] ?? ''));
            $title = trim((string) ($result[$titleKey] ?? ''));
            $snippet = trim((string) ($result[$snippetKey] ?? ''));

            $post = $this->makePost($url, $title, $snippet, $keyword, $provider);

            if ($post !== null && $usePublishedTimestamp && !empty($result['pdate'])) {
                try {
                    $publishedAt = Carbon::createFromTimestamp((int) $result['pdate']);
                    if ($this->isWithinLookback($publishedAt)) {
                        $post['datePosted'] = $publishedAt->toIso8601String();
                    }
                } catch (Throwable) {
                    // Sem data de publicação válida, permanece a data relativa do snippet.
                }
            }

            if ($post !== null) {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    private function makePost(
        string $url,
        string $title,
        string $snippet,
        string $keyword,
        string $provider
    ): ?array {
        if (!$this->isLinkedInPostUrl($url)) {
            return null;
        }

        $publishedAt = $this->extractPublishedAt($title . "\n" . $snippet);
        if ($publishedAt === null || !$this->isWithinLookback($publishedAt)) {
            return null;
        }

        return [
            'postId' => hash('sha256', $url),
            'postUrl' => $url,
            'title' => $title,
            'text' => trim($title . "\n" . $snippet),
            'datePosted' => $publishedAt->toIso8601String(),
            'matchedKeyword' => $keyword,
            'authorName' => null,
            'authorUrl' => null,
            'authorHeadline' => null,
            'authorType' => null,
            'topComments' => [],
            'links' => [],
            'searchProvider' => $provider,
            'searchSnippet' => $snippet,
        ];
    }

    private function isWithinLookback(Carbon $publishedAt): bool
    {
        return !$publishedAt->lt(
            now()->subHours((int) config('linkedin.lookback_hours', 72))
        ) && !$publishedAt->gt(now()->addHours(2));
    }

    private function isLinkedInPostUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return in_array($host, ['linkedin.com', 'www.linkedin.com'], true)
            && (
                str_contains($path, '/posts/')
                || str_contains($path, '/feed/update/')
            );
    }

    /**
     * Usa o CA bundle configurado sem desativar a validação TLS.
     *
     * @return array<string, string|bool>
     */
    private function tlsOptions(): array
    {
        $bundle = trim((string) config('linkedin.ca_bundle', ''));

        return $bundle !== '' && is_file($bundle) && is_readable($bundle)
            ? ['verify' => $bundle]
            : ['verify' => true];
    }

    private function extractPublishedAt(string $text): ?Carbon
    {
        $normalized = Str::ascii(mb_strtolower($text));

        if (preg_match('/\b(?:ha\s+)?(\d{1,2})\s*(min|minuto|minutos|h|hr|hrs|hora|horas|d|day|days|dia|dias|sem|semana|semanas)\s*(?:ago|atras)?\b/', $normalized, $matches)) {
            $amount = (int) $matches[1];
            $unit = $matches[2];

            if (str_starts_with($unit, 'min')) {
                return now()->subMinutes($amount);
            }
            if (in_array($unit, ['h', 'hr', 'hrs', 'hora', 'horas', 'hour', 'hours'], true)) {
                return now()->subHours($amount);
            }
            if (in_array($unit, ['d', 'dia', 'dias', 'day', 'days'], true)) {
                return now()->subDays($amount);
            }

            return now()->subWeeks($amount);
        }

        if (preg_match('/\b(today|hoje)\b/', $normalized)) {
            return now()->startOfDay();
        }

        if (preg_match('/\b(yesterday|ontem)\b/', $normalized)) {
            return now()->subDay();
        }

        return null;
    }
}
