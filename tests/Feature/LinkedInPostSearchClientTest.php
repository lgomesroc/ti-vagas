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

        Http::preventStrayRequests();
        Http::fake([
            'html.duckduckgo.com/*' => Http::response(
                '<div class="result">
                    <a class="result__a" href="https://www.linkedin.com/posts/recruiter_vaga-123">Estamos contratando Desenvolvedor Júnior</a>
                    <a class="result__snippet">Publicada 2 days ago. Vaga remota Brasil.</a>
                </div>
                <div class="result">
                    <a class="result__a" href="https://example.com/vaga">Outra vaga</a>
                    <a class="result__snippet">2 days ago</a>
                </div>',
                200
            ),
        ]);

        $posts = app(LinkedInPostSearchClient::class)->search();

        $this->assertCount(1, $posts);
        $this->assertSame(
            'https://www.linkedin.com/posts/recruiter_vaga-123',
            $posts[0]['postUrl']
        );
        $this->assertSame('duckduckgo-html', $posts[0]['searchProvider']);
        $this->assertSame([], $posts[0]['topComments']);
    }

    public function test_it_does_not_accept_linkedin_profile_or_jobs_urls_as_posts(): void
    {
        config([
            'linkedin.lookback_hours' => 72,
            'linkedin.keywords' => ['vaga desenvolvedor junior'],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'html.duckduckgo.com/*' => Http::response(
                '<div class="result">
                    <a class="result__a" href="https://www.linkedin.com/in/recruiter">Perfil recrutador</a>
                    <a class="result__snippet">2 days ago</a>
                </div>
                <div class="result">
                    <a class="result__a" href="https://www.linkedin.com/jobs/view/123">LinkedIn Jobs</a>
                    <a class="result__snippet">2 days ago</a>
                </div>',
                200
            ),
        ]);

        $this->assertSame([], app(LinkedInPostSearchClient::class)->search());
    }
}