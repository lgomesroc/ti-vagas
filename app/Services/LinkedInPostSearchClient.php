<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LinkedInPostSearchClient
{
    private const LINKEDIN_QUERY = '(site:linkedin.com/posts OR site:linkedin.com/feed/update)';

    /**
     * Combina resultados de Bing RSS e DuckDuckGo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $mode = 'all'): array
    {
        if ($mode === 'bot') {
            return $this->searchSecondaryAccount();
        }

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

            $providers = $mode === 'duckduckgo'
                ? [fn () => $this->searchDuckDuckGo($query, $keyword)]
                : [
                    fn () => $this->searchBingRss($query, $keyword),
                    fn () => $this->searchDuckDuckGo($query, $keyword),
                ];

            foreach ($providers as $searchProvider) {
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

                            if (($post['searchProvider'] ?? null) === 'linkedin-secondary-account') {
                                $posts[$url] = array_replace($posts[$url], $post);
                                $posts[$url]['searchProviders'] = array_values(array_unique($providers));
                                $posts[$url]['secondaryAccountSource'] = true;
                            }
                        }
                    }
                } catch (Throwable) {
                    // Falha de um buscador não interrompe os demais.
                    continue;
                }
            }

            usleep(500000);
        }

        if ($mode === 'duckduckgo') {
            return array_values($posts);
        }

        try {
            foreach ($this->searchSecondaryAccount() as $post) {
                $url = $post['postUrl'];
                if (!isset($posts[$url])) {
                    $posts[$url] = $post;
                } else {
                    $providers = $posts[$url]['searchProviders'] ?? [$posts[$url]['searchProvider']];
                    $providers[] = $post['searchProvider'];
                    $posts[$url]['searchProviders'] = array_values(array_unique($providers));
                    $posts[$url] = array_replace($posts[$url], $post);
                    $posts[$url]['searchProviders'] = array_values(array_unique($providers));
                    $posts[$url]['secondaryAccountSource'] = true;
                }
            }
        } catch (Throwable $exception) {
            Log::warning('Falha na pesquisa pela conta secundária do LinkedIn; os buscadores públicos continuarão.', [
                'error' => $exception->getMessage(),
            ]);
        }

        return array_values($posts);
    }

    /** @return array<int, array<string, mixed>> */
    private function searchSecondaryAccount(): array
    {
        if (!config('linkedin.secondary_account.enabled')) {
            return [];
        }

        $profile = base_path((string) config('linkedin.secondary_account.profile'));
        if (!is_dir($profile)) {
            return [];
        }

        $result = Process::path(base_path())
            ->env([
                'LINKEDIN_SECONDARY_ACCOUNT_PROFILE' => (string) config('linkedin.secondary_account.profile'),
                'LINKEDIN_POSTS_LOOKBACK_HOURS' => (string) config('linkedin.lookback_hours', 72),
            ])
            ->timeout((int) config('linkedin.secondary_account.timeout', 240))
            ->run([
                'node',
                'scripts/linkedin/secondary-account.mjs',
                'search',
            ]);

        if (!$result->successful()) {
            throw new RuntimeException(
                'Falha na busca pela conta secundária do LinkedIn: ' . trim($result->errorOutput())
            );
        }

        $posts = json_decode($result->output(), true);
        if (!is_array($posts)) {
            throw new RuntimeException('A conta secundária retornou uma resposta JSON inválida.');
        }

        return array_values(array_filter($posts, fn ($post): bool =>
            is_array($post)
            && isset($post['postUrl'])
            && ($post['searchProvider'] ?? null) === 'linkedin-secondary-account'
        ));
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

                $publishedAt = $this->parsePublishedAt($pubDate)
                    ?? $this->extractPublishedAt($title . "\n" . $snippet);

                $post = $this->makePost($url, $title, $snippet, $keyword, 'bing-rss', $publishedAt);
                if ($post === null) {
                    continue;
                }

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

    private function makePost(
        string $url,
        string $title,
        string $snippet,
        string $keyword,
        string $provider,
        ?Carbon $publishedAt = null
    ): ?array {
        if (!$this->isLinkedInPostUrl($url)) {
            return null;
        }

        $publishedAt ??= $this->extractPublishedAt($title . "\n" . $snippet);
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
