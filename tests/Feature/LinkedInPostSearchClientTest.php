<?php

namespace Tests\Feature;

use App\Services\LinkedInPostSearchClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkedInPostSearchClientTest extends TestCase
{
    public function test_it_combines_duckduckgo_brave_and_mojeek_results_and_deduplicates_posts(): void
    {
        config([
            'linkedin.lookback_hours' => 72,
            'linkedin.keywords' => ['vaga desenvolvedor junior'],
            'linkedin.brave_api_key' => 'brave-test-key',
            'linkedin.mojeek_api_key' => 'mojeek-test-key',
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'html.duckduckgo.com/*' => Http::response($this->duckDuckGoHtml([
                [
                    'url' => 'https://www.linkedin.com/posts/author_post-ddg',
                    'title' => 'Estamos contratando Desenvolvedor Júnior',
                    'snippet' => 'Vaga remota Brasil. 2 days ago',
                ],
                [
                    'url' => 'https://www.linkedin.com/in/recruiter',
                    'title' => 'Perfil recrutador',
                    'snippet' => 'Recrutador de tecnologia. 2 days ago',
                ],
            ]), 200),
            'api.search.brave.com/*' => Http::response([
                'web' => [
                    'results' => [
                        [
                            'url' => 'https://www.linkedin.com/posts/author_post-brave',
                            'title' => 'Vaga Desenvolvedor PHP Júnior',
                            'description' => 'Oportunidade remota. 2 days ago',
                        ],
                        [
                            'url' => 'https://www.linkedin.com/posts/author_post-ddg',
                            'title' => 'Estamos contratando Desenvolvedor Júnior',
                            'description' => 'Vaga remota Brasil. 2 days ago',
                        ],
                    ],
                ],
            ], 200),
            'www.mojeek.com/*' => Http::response([
                'response' => [
                    'status' => 'OK',
                    'results' => [
                        [
                            'url' => 'https://www.linkedin.com/posts/author_post-mojeek',
                            'title' => 'Vaga Analista de Suporte Júnior',
                            'desc' => 'Oportunidade presencial. 2 days ago',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $posts = app(LinkedInPostSearchClient::class)->search();

        $this->assertCount(3, $posts);

        $byUrl = collect($posts)->keyBy('postUrl');

        $this->assertSame(
            'duckduckgo-html',
            $byUrl['https://www.linkedin.com/posts/author_post-ddg']['searchProvider']
        );
        $this->assertSame(
            ['duckduckgo-html', 'brave-api'],
            $byUrl['https://www.linkedin.com/posts/author_post-ddg']['searchProviders']
        );
        $this->assertSame(
            'brave-api',
            $byUrl['https://www.linkedin.com/posts/author_post-brave']['searchProvider']
        );
        $this->assertSame(
            'mojeek-api',
            $byUrl['https://www.linkedin.com/posts/author_post-mojeek']['searchProvider']
        );
    }

    public function test_it_uses_duckduckgo_without_api_keys_and_rejects_non_post_urls(): void
    {
        config([
            'linkedin.lookback_hours' => 72,
            'linkedin.keywords' => ['vaga desenvolvedor junior'],
            'linkedin.brave_api_key' => null,
            'linkedin.mojeek_api_key' => null,
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'html.duckduckgo.com/*' => Http::response($this->duckDuckGoHtml([
                [
                    'url' => 'https://www.linkedin.com/jobs/view/123',
                    'title' => 'LinkedIn Jobs',
                    'snippet' => 'Vaga de emprego. 2 days ago',
                ],
                [
                    'url' => 'https://example.com/vaga',
                    'title' => 'Vaga externa',
                    'snippet' => 'Oportunidade. 2 days ago',
                ],
            ]), 200),
        ]);

        $this->assertSame([], app(LinkedInPostSearchClient::class)->search());

        Http::assertSent(fn ($request): bool =>
            str_contains($request->url(), 'html.duckduckgo.com')
        );
        Http::assertNotSent(fn ($request): bool =>
            str_contains($request->url(), 'api.search.brave.com')
            || str_contains($request->url(), 'www.mojeek.com')
        );
    }

    private function duckDuckGoHtml(array $results): string
    {
        $items = '';

        foreach ($results as $result) {
            $url = htmlspecialchars($result['url'], ENT_QUOTES);
            $title = htmlspecialchars($result['title'], ENT_QUOTES);
            $snippet = htmlspecialchars($result['snippet'], ENT_QUOTES);

            $items .= <<<HTML
            <div class="result">
                <div class="result__title">
                    <a class="result__a" href="{$url}">{$title}</a>
                </div>
                <a class="result__snippet">{$snippet}</a>
            </div>
            HTML;
        }

        return '<html><body>' . $items . '</body></html>';
    }
}
