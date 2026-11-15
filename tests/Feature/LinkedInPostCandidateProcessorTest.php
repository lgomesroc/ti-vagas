<?php

namespace Tests\Feature;

use App\Models\LinkedInPostCandidate;
use App\Services\LinkedInPostCandidateProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkedInPostCandidateProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_without_email_or_application_link_is_kept_for_manual_review(): void
    {
        $post = $this->post([
            'text' => "Estamos contratando!\nDesenvolvedor PHP Júnior\nEmpresa: Acme Tecnologia\nRemoto Brasil\nAtuação com PHP e Laravel.",
        ]);

        $result = app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'pending_review',
            'application_url' => null,
        ]);

        $candidate = LinkedInPostCandidate::query()->firstOrFail();

        $this->assertSame([], $candidate->emails);
        $this->assertSame(
            'Sem link ou e-mail; encaminhado para revisão manual com base na publicação pública.',
            $candidate->decision_reason
        );
    }

    public function test_engagement_bait_asking_people_to_comment_for_a_private_message_is_rejected(): void
    {
        $post = $this->post([
            'text' => "Estamos contratando Desenvolvedor PHP Júnior. Comente EU QUERO que envio o link na DM.",
        ]);

        $result = app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertSame(1, $result['rejected']);
        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
        ]);
    }

    private function post(array $overrides = []): array
    {
        return array_replace([
            'postId' => 'urn:li:activity:1001',
            'postUrl' => 'https://www.linkedin.com/feed/update/urn:li:activity:1001/',
            'authorName' => 'Recrutadora de Tecnologia',
            'authorHeadline' => 'Tech Recruiter',
            'datePosted' => now()->toIso8601String(),
            'text' => 'Estamos contratando Desenvolvedor PHP Júnior.',
            'topComments' => [],
        ], $overrides);
    }
}