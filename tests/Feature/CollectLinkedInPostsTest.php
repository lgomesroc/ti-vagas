<?php

namespace Tests\Feature;

use App\Models\LinkedInPostCandidate;
use App\Services\LinkedInPostAutoPublisher;
use App\Services\LinkedInPostCandidateProcessor;
use App\Services\LinkedInPostSearchClient;
use App\Services\LinkedInPublicPostFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CollectLinkedInPostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['linkedin.enabled' => true]);
    }

    public function test_previously_seen_post_is_not_fetched_or_processed_again(): void
    {
        LinkedInPostCandidate::query()->create([
            'post_id' => 'urn:li:activity:already-seen',
            'post_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:already-seen/',
            'post_text' => 'Publicação já consultada anteriormente.',
            'status' => 'rejected',
            'decision_reason' => 'Não atende aos critérios.',
        ]);

        $post = [
            'postId' => 'urn:li:activity:already-seen',
            'postUrl' => 'https://www.linkedin.com/feed/update/urn:li:activity:already-seen/',
            'text' => 'Publicação repetida.',
        ];

        $this->mock(LinkedInPostSearchClient::class)
            ->shouldReceive('search')->once()->with('all')->andReturn([$post]);

        $this->mock(LinkedInPublicPostFetcher::class)->shouldNotReceive('fetch');
        $this->mock(LinkedInPostCandidateProcessor::class)->shouldNotReceive('process');

        $this->mock(LinkedInPostAutoPublisher::class)
            ->shouldReceive('publishPending')->once()
            ->andReturn(['approved' => 0, 'rejected' => 0, 'failed' => 0]);

        $this->artisan('linkedin:collect-posts')->assertExitCode(0);
    }

    public function test_post_is_reserved_before_public_detail_fetch(): void
    {
        $post = [
            'postId' => 'urn:li:activity:new-post',
            'postUrl' => 'https://www.linkedin.com/feed/update/urn:li:activity:new-post/',
            'text' => 'Estamos contratando Desenvolvedor PHP Júnior.',
        ];

        $this->mock(LinkedInPostSearchClient::class)
            ->shouldReceive('search')->once()->with('all')->andReturn([$post]);

        $this->mock(LinkedInPublicPostFetcher::class)
            ->shouldReceive('fetch')->once()
            ->with(Mockery::on(function (array $received): bool {
                $this->assertDatabaseHas('linkedin_post_candidates', [
                    'post_id' => 'urn:li:activity:new-post',
                    'status' => 'processing',
                ]);

                return $received['postId'] === 'urn:li:activity:new-post';
            }))
            ->andReturn($post);

        $this->mock(LinkedInPostCandidateProcessor::class)
            ->shouldReceive('process')->once()
            ->andReturn(['created' => 1, 'updated' => 0, 'rejected' => 0, 'duplicates' => 0]);

        $this->mock(LinkedInPostAutoPublisher::class)
            ->shouldReceive('publishPending')->once()
            ->andReturn(['approved' => 0, 'rejected' => 0, 'failed' => 0]);

        $this->artisan('linkedin:collect-posts')->assertExitCode(0);
    }

    public function test_failed_post_fetch_is_marked_rejected_and_not_retried(): void
    {
        $post = [
            'postId' => 'urn:li:activity:failed-post',
            'postUrl' => 'https://www.linkedin.com/feed/update/urn:li:activity:failed-post/',
            'text' => 'Publicação com falha na consulta.',
        ];

        $search = $this->mock(LinkedInPostSearchClient::class);
        $search->shouldReceive('search')->twice()->with('all')->andReturn([$post]);

        $fetcher = $this->mock(LinkedInPublicPostFetcher::class);
        $fetcher->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('Falha simulada'));

        $this->mock(LinkedInPostCandidateProcessor::class)->shouldNotReceive('process');

        $this->mock(LinkedInPostAutoPublisher::class)
            ->shouldReceive('publishPending')->twice()
            ->andReturn(['approved' => 0, 'rejected' => 0, 'failed' => 0]);

        $this->artisan('linkedin:collect-posts')->assertExitCode(1);
        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:failed-post',
            'status' => 'rejected',
        ]);

        $this->artisan('linkedin:collect-posts')->assertExitCode(0);
        $fetcher->shouldHaveReceived('fetch')->once();
    }
}
