<?php

namespace Tests\Feature;

use App\Services\LinkedInPostSearchClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkedInPostSearchClientTest extends TestCase
{
    public function test_it_combines_bing_rss_and_duckduckgo_results_and_deduplicates_posts(): void
    {
        config([
            'linkedin.lookback_hours' => 72,
            'linkedin.keywords' => ['vaga desenvolvedor junior'],
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'www.bing.com/*' => Http::response($this->bingRss([
                [
                    'url' => 'https://www.linkedin.com/posts/author_post-bing',
                    'title' => 'Vaga Desenvolvedor PHP Júnior',
                    'description' => 'Oportunidade remota.',
                ],
                [
                    'url' => 'https://www.linkedin.com/posts/author_post-shared',
                    'title' => 'Estamos contratando Desenvolvedor Júnior',
                    'description' => 'Vaga remota Brasil.',
                ],
            ]), 200),
            'html.duckduckgo.com/*' => Http::response($this->duckDuckGoHtml([
                [
                    'url' => 'https://www.linkedin.com/posts/author_post-ddg',
                    'title' => 'Vaga Analista de Suporte Júnior',
                    'snippet' => 'Oportunidade presencial. 2 days ago',
                ],
                [
                    'url' => 'https://www.linkedin.com/posts/author_post-shared',
                    'title' => 'Estamos contratando Desenvolvedor Júnior',
                    'snippet' => 'Vaga remota Brasil. 2 days ago',
                ],
            ]), 200),
        ]);

        $posts = app(LinkedInPostSearchClient::class)->search();

        $this->assertCount(3, $posts);
        $byUrl = collect($posts)->keyBy('postUrl');

        $this->assertSame('bing-rss', $byUrl['https://www.linkedin.com/posts/author_post-bing']['searchProvider']);
        $this->assertSame('duckduckgo-html', $byUrl['https://www.linkedin.com/posts/author_post-ddg']['searchProvider']);
        $this->assertSame('bing-rss', $byUrl['https://www.linkedin.com/posts/author_post-shared']['searchProvider']);
        $this->assertSame(
            ['bing-rss', 'duckduckgo-html'],
            $byUrl['https://www.linkedin.com/posts/author_post-shared']['searchProviders']
        );

        Http::assertNotSent(fn ($request): bool =>
            str_contains($request->url(), 'api.search.brave.com')
            || str_contains($request->url(), 'mojeek.com')
        );
    }

    public function test_it_uses_bing_and_duckduckgo_and_rejects_non_post_urls(): void
    {
        config([
            'linkedin.lookback_hours' => 72,
            'linkedin.keywords' => ['vaga desenvolvedor junior'],
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'www.bing.com/*' => Http::response($this->bingRss([
                [
                    'url' => 'https://www.linkedin.com/jobs/view/123',
                    'title' => 'LinkedIn Jobs',
                    'description' => 'Vaga de emprego.',
                ],
            ]), 200),
            'html.duckduckgo.com/*' => Http::response($this->duckDuckGoHtml([
                [
                    'url' => 'https://example.com/vaga',
                    'title' => 'Vaga externa',
                    'snippet' => 'Oportunidade. 2 days ago',
                ],
            ]), 200),
        ]);

        $this->assertSame([], app(LinkedInPostSearchClient::class)->search());

        Http::assertSent(fn ($request): bool =>
            str_contains($request->url(), 'www.bing.com')
        );
        Http::assertSent(fn ($request): bool =>
            str_contains($request->url(), 'html.duckduckgo.com')
        );
        Http::assertNotSent(fn ($request): bool =>
            str_contains($request->url(), 'api.search.brave.com')
            || str_contains($request->url(), 'mojeek.com')
        );
    }

    public function test_it_normalizes_tracking_parameters_before_deduplicating_posts(): void
    {
        config([
            'linkedin.lookback_hours' => 72,
            'linkedin.keywords' => ['vaga desenvolvedor junior'],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'www.bing.com/*' => Http::response($this->bingRss([
                [
                    'url' => 'https://www.linkedin.com/posts/author_post-shared?trk=public_jobs&originalSubdomain=br',
                    'title' => 'Estamos contratando Desenvolvedor Júnior',
                    'description' => 'Vaga remota Brasil.',
                ],
            ]), 200),
            'html.duckduckgo.com/*' => Http::response($this->duckDuckGoHtml([
                [
                    'url' => 'https://www.linkedin.com/posts/author_post-shared/',
                    'title' => 'Estamos contratando Desenvolvedor Júnior',
                    'snippet' => 'Vaga remota Brasil. 2 days ago',
                ],
            ]), 200),
        ]);

        $posts = app(LinkedInPostSearchClient::class)->search();

        $this->assertCount(1, $posts);
        $this->assertSame(
            'https://www.linkedin.com/posts/author_post-shared/',
            $posts[0]['postUrl']
        );
        $this->assertSame(
            ['bing-rss', 'duckduckgo-html'],
            $posts[0]['searchProviders']
        );
    }

    private function bingRss(array $results): string
    {
        $items = '';

        foreach ($results as $result) {
            $url = htmlspecialchars($result['url'], ENT_XML1);
            $title = htmlspecialchars($result['title'], ENT_XML1);
            $description = htmlspecialchars($result['description'], ENT_XML1);

            $items .= "<item><title>{$title}</title><link>{$url}</link><description>{$description}</description><pubDate>" .
                now()->subDays(2)->toRssString() .
                "</pubDate></item>";
        }

        return '<?xml version="1.0"?><rss version="2.0"><channel>' . $items . '</channel></rss>';
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
