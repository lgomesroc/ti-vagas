<?php

namespace Tests\\Feature;

use App\\Models\\LinkedInPostCandidate;
use App\\Services\\LinkedInPostCandidateProcessor;
use App\\Services\\LinkedInUrlInspector;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class LinkedInPostEmailApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_post_with_corporate_email_is_parsed_into_separate_application_fields(): void
    {
        $this->mock(LinkedInUrlInspector::class)->shouldNotReceive('inspect');

        $post = [
            'postId' => 'urn:li:activity:7530395139467337728',
            'postUrl' => 'https://www.linkedin.com/feed/update/urn:li:activity:7530395139467337728/',
            'authorName' => 'Lara Assessoria',
            'authorHeadline' => 'Especialista em Recolocação | Job Hunter | Especialista em Recrutamento & Seleção | Mentora de Carreira | Criadora de Conteúdo',
            'datePosted' => now()->subDays(6)->toIso8601String(),
            'isPromoted' => false,
            'text' => "# 🚀 Vaga aberta | Desenvolvedor(a) Backend Júnior — PHP\n\n📍 Curitiba/PR | [Modelo de trabalho] | [CLT/PJ]\n\nA B4X está em busca de um(a) Desenvolvedor(a) Backend Júnior para integrar nosso time de tecnologia.\n\nNão exigimos anos de experiência profissional.\n\nEnvie seu currículo para **junior@b4x.com.br**, com o assunto **Vaga Backend Júnior — PHP**.",
            'topComments' => [],
        ];

        $result = app(LinkedInPostCandidateProcessor::class)->process($post);
        $candidate = LinkedInPostCandidate::query()
            ->where('post_id', 'urn:li:activity:7530395139467337728')
            ->firstOrFail();

        $this->assertSame(0, $result['rejected']);
        $this->assertSame('pending_publication', $candidate->status);
        $this->assertSame('Desenvolvedor(a) Backend Júnior — PHP', $candidate->title);
        $this->assertSame('B4X', $candidate->company);
        $this->assertSame('Curitiba/PR', $candidate->location);
        $this->assertNull($candidate->work_model);
        $this->assertNull($candidate->employment_type);
        $this->assertNull($candidate->application_url);
        $this->assertSame('junior@b4x.com.br', $candidate->application_email);
        $this->assertSame('Vaga Backend Júnior — PHP', $candidate->application_subject);
    }
}
