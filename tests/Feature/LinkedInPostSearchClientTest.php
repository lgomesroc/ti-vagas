<?php

namespace Tests\Feature;

use App\Services\LinkedInPostSearchClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkedInPostSearchClientTest extends TestCase
{
    public function test_it_collects_only_recent_public_linkedin_post_results_without_paid_provider(): void
    {
        config([
            'linkedin.lookback_hours' => 72,
            'linkedin.keywords' => ['vaga desenvolvedor junior'],
        ]);

        $publishedAt = now()->subDays(2)->toRfc2822String();

        $rss = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
            <channel>
                <item>
                    <title>Estamos contratando Desenvolvedor Júnior</title>
                    <link>https://www.linkedin.com/posts/recruiter_vaga-123</link>
                    <description>Vaga remota Brasil.</description>
                    <pubDate>{$publishedAt}</pubDate>
                </item>
                <item>
                    <title>Outra vaga</title>
                    <link>https://example.com/vaga</link>
                    <description>Vaga externa.</description>
                    <pubDate>{$publishedAt}</pubDate>
                </item>
            </channel>
        </rss>
        XML;

        Http::preventStrayRequests();

        Http::fake([
            'www.bing.com/*' => Http::response($rss, 200, [
                'Content-Type' => 'application/rss+xml',
            ]),
        ]);

        $posts = app(LinkedInPostSearchClient::class)->search();

        $this->assertCount(1, $posts);
        $this->assertSame(
            'https://www.linkedin.com/posts/recruiter_vaga-123',
            $posts[0]['postUrl']
        );
        $this->assertSame('bing-rss', $posts[0]['searchProvider']);
        $this->assertSame([], $posts[0]['topComments']);
    }

    public function test_it_does_not_accept_linkedin_profile_or_jobs_urls_as_posts(): void
    {
        config([
            'linkedin.lookback_hours' => 72,
            'linkedin.keywords' => ['vaga desenvolvedor junior'],
        ]);

        $publishedAt = now()->subDays(2)->toRfc2822String();

        $rss = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
            <channel>
                <item>
                    <title>Perfil recrutador</title>
                    <link>https://www.linkedin.com/in/recruiter</link>
                    <description>Recrutador de tecnologia.</description>
                    <pubDate>{$publishedAt}</pubDate>
                </item>
                <item>
                    <title>LinkedIn Jobs</title>
                    <link>https://www.linkedin.com/jobs/view/123</link>
                    <description>Vaga de emprego.</description>
                    <pubDate>{$publishedAt}</pubDate>
                </item>
            </channel>
        </rss>
        XML;

        Http::preventStrayRequests();

        Http::fake([
            'www.bing.com/*' => Http::response($rss, 200, [
                'Content-Type' => 'application/rss+xml',
            ]),
        ]);

        $this->assertSame(
            [],
            app(LinkedInPostSearchClient::class)->search()
        );
    }
}
