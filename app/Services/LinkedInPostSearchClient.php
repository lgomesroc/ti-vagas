<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class LinkedInPostSearchClient
{
    /**
     * Pesquisa resultados públicos indexados pelo DuckDuckGo.
     *
     * Não usa API paga, login, senha ou cookies do LinkedIn.
     * A cobertura depende da indexação pública do mecanismo de busca.
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
            $query = 'site:linkedin.com/posts/ OR site:linkedin.com/feed/update/ '
                . '"' . $keyword . '"';

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
                ])->timeout(20)->get('https://html.duckduckgo.com/html/', [
                    'q' => $query,
                ]);

                if (!$response->successful()) {
                    continue;
                }

                foreach ($this->parseResults($response->body(), $keyword) as $post) {
                    $posts[$post['postUrl']] = $post;
                }
            } catch (\Throwable) {
                // Uma consulta indisponível não impede as demais palavras-chave.
                continue;
            }

            usleep(700000);
        }

        return array_values($posts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseResults(string $html, string $keyword): array
    {
        if (!class_exists(\DOMDocument::class)) {
            throw new RuntimeException('A extensão PHP DOM/XML é necessária para ler resultados de pesquisa.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument();
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new \DOMXPath($document);
            $nodes = $xpath->query(
                "//*[contains(concat(' ', normalize-space(@class), ' '), ' result ')]"
            );

            $results = [];

            foreach ($nodes ?: [] as $node) {
                $linkNode = $xpath->query(
                    ".//*[contains(concat(' ', normalize-space(@class), ' '), ' result__a ')]",
                    $node
                )?->item(0);

                $snippetNode = $xpath->query(
                    ".//*[contains(concat(' ', normalize-space(@class), ' '), ' result__snippet ')]",
                    $node
                )?->item(0);

                if (!$linkNode instanceof \DOMElement) {
                    continue;
                }

                $url = $this->resolveSearchUrl($linkNode->getAttribute('href'));
                if (!$this->isLinkedInPostUrl($url)) {
                    continue;
                }

                $title = trim($linkNode->textContent);
                $snippet = trim($snippetNode?->textContent ?? '');
                $text = trim($title . "\n" . $snippet);

                $publishedAt = $this->extractPublishedAt($text);
                if ($publishedAt === null || $publishedAt->lt(
                    now()->subHours((int) config('linkedin.lookback_hours', 72))
                ) || $publishedAt->gt(now()->addHours(2))) {
                    continue;
                }

                $results[] = [
                    'postId' => hash('sha256', $url),
                    'postUrl' => $url,
                    'title' => $title,
                    'text' => $text,
                    'datePosted' => $publishedAt->toIso8601String(),
                    'matchedKeyword' => $keyword,
                    'authorName' => null,
                    'authorUrl' => null,
                    'authorHeadline' => null,
                    'authorType' => null,
                    'topComments' => [],
                    'links' => [],
                    'searchProvider' => 'duckduckgo-html',
                    'searchSnippet' => $snippet,
                ];
            }

            return $results;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function resolveSearchUrl(string $href): string
    {
        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }

        $query = [];
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

        if (isset($query['uddg']) && is_string($query['uddg'])) {
            return rawurldecode($query['uddg']);
        }

        return $href;
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

    private function extractPublishedAt(string $text): ?\Illuminate\Support\Carbon
    {
        $normalized = Str::ascii(mb_strtolower($text));

        if (preg_match('/\b(\d{1,2})\s*(min|mins|minute|minutes|h|hr|hrs|hour|hours|d|day|days|sem|week|weeks)\s+ago\b/', $normalized, $matches)) {
            $amount = (int) $matches[1];
            $unit = $matches[2];

            if (str_starts_with($unit, 'min')) {
                return now()->subMinutes($amount);
            }

            if (in_array($unit, ['h', 'hr', 'hrs', 'hour', 'hours'], true)) {
                return now()->subHours($amount);
            }

            if (in_array($unit, ['d', 'day', 'days'], true)) {
                return now()->subDays($amount);
            }

            return now()->subWeeks($amount);
        }

        if (preg_match('/\b(\d{1,2})\s*(m|h|d)\b/', $normalized, $matches)) {
            return match ($matches[2]) {
                'm' => now()->subMinutes((int) $matches[1]),
                'h' => now()->subHours((int) $matches[1]),
                'd' => now()->subDays((int) $matches[1]),
            };
        }

        if (preg_match('/\b(today|hoje)\b/', $normalized)) {
            return now()->startOfDay();
        }

        if (preg_match('/\b(yesterday|ontem)\b/', $normalized)) {
            return now()->subDay();
        }

        if (preg_match('/\b(\d{1,2})\s+(jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez)[a-z]*\.?\b/u', $normalized, $matches)) {
            $months = [
                'jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4,
                'mai' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8,
                'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12,
            ];

            $month = $months[$matches[2]] ?? null;
            if ($month !== null) {
                $year = now()->year;
                $date = now()->setDate($year, $month, (int) $matches[1])->startOfDay();

                if ($date->gt(now()->addDay())) {
                    $date->subYear();
                }

                return $date;
            }
        }

        return null;
    }
}