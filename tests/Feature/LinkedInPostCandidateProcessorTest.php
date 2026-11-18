<?php

namespace Tests\Feature;

use App\Models\LinkedInPostCandidate;
use App\Services\LinkedInPostCandidateProcessor;
use App\Services\LinkedInUrlInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkedInPostCandidateProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_without_corporate_email_or_verified_application_link_is_rejected(): void
    {
        $post = $this->makePost([
            'isPromoted' => false,
            'text' => "Estamos contratando!\nDesenvolvedor PHP Júnior\nEmpresa: Acme Tecnologia\nRemoto Brasil\nCandidatura: https://acme.example/jobs/123\ncontato@gmail.com",
        ]);

        $result = app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertSame(1, $result['rejected']);
        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
            'decision_reason' => 'Sem link de candidatura verificado ou e-mail corporativo de candidatura.',
        ]);
    }

    public function test_engagement_bait_asking_people_to_comment_for_a_private_message_is_rejected(): void
    {
        $post = $this->makePost([
            'text' => 'Estamos contratando Desenvolvedor PHP Júnior. Comente EU QUERO que envio o link na DM.',
        ]);

        $result = app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertSame(1, $result['rejected']);
        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
        ]);
    }

    public function test_known_job_aggregator_link_is_blocked(): void
    {
        config(['linkedin.aggregator_domains' => ['indeed.com']]);

        $result = app(LinkedInUrlInspector::class)
            ->inspect('https://www.indeed.com/viewjob?jk=abc123');

        $this->assertTrue($result['blocked']);
        $this->assertTrue($result['verified']);
    }

    private function makePost(array $overrides = []): array
    {
        return array_replace([
            'postId' => 'urn:li:activity:1001',
            'postUrl' => 'https://www.linkedin.com/feed/update/urn:li:activity:1001/',
            'authorName' => 'Recrutadora de Tecnologia',
            'authorHeadline' => 'Tech Recruiter',
            'datePosted' => now()->toIso8601String(),
            'isPromoted' => false,
            'text' => 'Estamos contratando Desenvolvedor PHP Júnior.',
            'topComments' => [],
        ], $overrides);
    }
}
