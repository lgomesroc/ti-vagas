<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Enriches only LinkedIn post pages that are publicly reachable without a session.
 * It does not authenticate, use cookies, solve challenges, or retry access denials.
 */
class LinkedInPublicPostFetcher
{
    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function fetch(array $post): array
    {
        $url = $post['postUrl'] ?? null;
        if (!is_string($url) || !$this->isPublicPostUrl($url)) {
            return $post;
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'TIVagasBot/1.0 (+https://github.com/lgomesroc/ti-vagas)',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'pt-BR,pt;q=0.9',
            ])->connectTimeout(5)->timeout(12)->get($url);
        } catch (Throwable) {
            return $post + ['publicFetchStatus' => 'network_error'];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return $post + ['publicFetchStatus' => 'access_restricted'];
        }

        if ($response->status() === 429) {
            return $post + ['publicFetchStatus' => 'rate_limited'];
        }

        if (!$response->successful()) {
            return $post + ['publicFetchStatus' => 'http_' . $response->status()];
        }

        $html = $response->body();
        if (
            preg_match('/(?:sign in|join linkedin|entrar no linkedin|cadastre-se no linkedin)/i', $html)
            && !preg_match('/<article\b/i', $html)
        ) {
            return $post + ['publicFetchStatus' => 'login_wall'];
        }

        if (!class_exists(\DOMDocument::class)) {
            return $post + ['publicFetchStatus' => 'dom_extension_missing'];
        }

        $data = $this->parsePublicHtml($html);
        if ($data === []) {
            return $post + ['publicFetchStatus' => 'no_public_content'];
        }

        return array_replace($post, $data, [
            'publicFetchStatus' => 'fetched',
            'publicFetchMethod' => 'unauthenticated_public_html',
        ]);
    }

    private function isPublicPostUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return in_array($host, ['linkedin.com', 'www.linkedin.com'], true)
            && (str_contains($path, '/posts/') || str_contains($path, '/feed/update/'));
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePublicHtml(string $html): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument();
            if (!$document->loadHTML($html)) {
                return [];
            }

            $xpath = new \DOMXPath($document);
            $text = $this->firstText($xpath, [
                '//article',
                '//*[@data-test-id="main-feed-activity-card"]',
                '//main',
            ]);

            $result = [];
            if ($text !== '') {
                $result['text'] = $text;
            }

            $title = $this->meta($xpath, 'og:title');
            if ($title !== '') {
                $result['title'] = $title;
            }

            $description = $this->meta($xpath, 'og:description');
            if ($description !== '') {
                $result['publicDescription'] = $description;
                if ($text === '') {
                    $result['text'] = $description;
                }
            }

            $publishedAt = $this->meta($xpath, 'article:published_time');
            if ($publishedAt === '') {
                $publishedAt = trim((string) ($xpath->evaluate('string(//time[@datetime][1]/@datetime)') ?: ''));
            }
            if ($publishedAt !== '' && strtotime($publishedAt) !== false) {
                $result['datePosted'] = date(DATE_ATOM, strtotime($publishedAt));
            }

            $comments = [];
            $nodes = $xpath->query(
                '//*[contains(translate(@class, "COMMENT", "comment"), "comment") and not(self::script)]'
            );
            foreach ($nodes ?: [] as $node) {
                $comment = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
                if ($comment !== '' && mb_strlen($comment) >= 8 && mb_strlen($comment) <= 3000) {
                    $comments[] = ['text' => $comment];
                }
            }
            $comments = array_slice(array_values(array_unique(array_column($comments, 'text'))), 0, 30);
            if ($comments !== []) {
                $result['topComments'] = array_map(fn (string $comment): array => ['text' => $comment], $comments);
            }

            $promoted = $xpath->query(
                '//*[contains(translate(@class, "PROMOTED SPONSORED PATROCINADO", "promoted sponsored patrocinado"), "promoted") or contains(translate(@class, "PROMOTED SPONSORED PATROCINADO", "promoted sponsored patrocinado"), "sponsored") or contains(translate(@class, "PROMOTED SPONSORED PATROCINADO", "promoted sponsored patrocinado"), "patrocinado")]'
            );
            if ($promoted !== false && $promoted->length > 0) {
                $result['isPromoted'] = true;
            }

            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function meta(\DOMXPath $xpath, string $property): string
    {
        $value = $xpath->evaluate(
            'string(//meta[@property="' . $property . '"]/@content)'
        );

        return trim((string) $value);
    }

    /**
     * @param array<int, string> $queries
     */
    private function firstText(\DOMXPath $xpath, array $queries): string
    {
        foreach ($queries as $query) {
            $node = $xpath->query($query)?->item(0);
            if ($node !== null) {
                $value = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
                if ($value !== '') {
                    return mb_substr($value, 0, 12000);
                }
            }
        }

        return '';
    }
}
