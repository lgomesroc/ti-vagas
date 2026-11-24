<?php

namespace Tests\Feature;

use App\Models\LinkedInPostCandidate;
use App\Services\LinkedInPostCandidateProcessor;
use App\Services\LinkedInUrlInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkedInPostEmailApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_email_only_post_without_company_and_job_details_is_rejected(): void
    {
        $this->mock(LinkedInUrlInspector::class)->shouldNotReceive('inspect');

        $post = [
            'postId' => 'urn:li:activity:manaus-generic-001',
            'postUrl' => 'https://www.linkedin.com/feed/update/urn:li:activity:manaus-generic-001/',
            'authorName' => 'Antonelly',
            'authorType' => 'company',
            'datePosted' => now()->subDay()->toIso8601String(),
            'isPromoted' => false,
            'text' => 'Nova oportunidade | Analista de T.I. JR. Se você tem interesse em fazer parte do nosso time, confira os requisitos e candidate-se! Envie seu currículo para selecaoantonellymanaus@gmail.com. #VagasManaus #AnalistaDeTI #Antonelly',
            'topComments' => [],
        ];

        $result = app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertSame(1, $result['rejected']);
        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:manaus-generic-001',
            'status' => 'rejected',
            'decision_reason' => 'Publicação com candidatura somente por e-mail não apresenta detalhes suficientes da vaga (empresa, localidade, responsabilidades e requisitos).',
        ]);
    }

    public function test_external_application_link_found_in_public_comment_is_used_as_destination(): void
    {
        $this->mock(LinkedInUrlInspector::class)
            ->shouldReceive('inspect')
            ->once()
            ->with('https://acme.example/jobs/456')
            ->andReturn([
                'valid' => true,
                'verified' => true,
                'blocked' => false,
                'final_url' => 'https://acme.example/jobs/456',
                'reason' => null,
                'page_title' => 'Desenvolvedor PHP Júnior',
                'page_description' => 'Vaga para Desenvolvedor PHP Júnior na Acme Tecnologia.',
                'page_text' => 'Candidate-se pela página oficial.',
            ]);

        $post = [
            'postId' => 'urn:li:activity:comment-link-001',
            'postUrl' => 'https://www.linkedin.com/feed/update/urn:li:activity:comment-link-001/',
            'authorName' => 'Acme Tecnologia',
            'authorType' => 'company',
            'datePosted' => now()->subDay()->toIso8601String(),
            'isPromoted' => false,
            'text' => 'Estamos contratando Desenvolvedor PHP Júnior. Empresa: Acme Tecnologia. Remoto Brasil. Responsabilidades: desenvolver APIs. Requisitos: PHP, SQL e Git. Envie currículo para talentos@acme.com.',
            'topComments' => [
                ['text' => 'Acesse a página oficial da vaga: https://acme.example/jobs/456'],
            ],
        ];

        app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:comment-link-001',
            'status' => 'pending_publication',
            'application_url' => 'https://acme.example/jobs/456',
            'application_email' => 'talentos@acme.com',
        ]);
    }

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
            'text' => "# 🚀 Vaga aberta | Desenvolvedor(a) Backend Júnior — PHP\n\n📍 Curitiba/PR | [Modelo de trabalho] | [CLT/PJ]\n\nA B4X está em busca de um(a) Desenvolvedor(a) Backend Júnior para integrar nosso time de tecnologia.\n\nA oportunidade é para atuar no desenvolvimento da plataforma própria de e-commerce da empresa, com PHP/Laravel, MySQL e APIs REST.\n\n### O que você vai fazer\n\nDesenvolver e manter funcionalidades backend da plataforma, trabalhar com integrações REST e consultas SQL, investigar e corrigir problemas no código existente.\n\n### Requisitos\n\nConhecimento em PHP, orientação a objetos, SQL, APIs REST, HTTP, JSON e Git.\n\n### Diferenciais\n\nConhecimento em Laravel e Docker.\n\nNão exigimos anos de experiência profissional. Projetos pessoais, acadêmicos ou de cursos também são bem-vindos.\n\nEnvie seu currículo para **junior@b4x.com.br**, com o assunto **Vaga Backend Júnior — PHP**.",
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
        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:activity:7530395139467337728/', $candidate->application_url);
        $this->assertSame('junior@b4x.com.br', $candidate->application_email);
        $this->assertSame('Vaga Backend Júnior — PHP', $candidate->application_subject);
    }
}
