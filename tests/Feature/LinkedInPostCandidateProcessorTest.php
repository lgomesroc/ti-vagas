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

    public function test_public_email_provider_without_application_link_is_rejected(): void
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
        ]);

        $candidate = LinkedInPostCandidate::query()
            ->where('post_id', 'urn:li:activity:1001')
            ->firstOrFail();

        $this->assertContains('contato@gmail.com', $candidate->emails);
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

    public function test_open_to_work_and_job_search_posts_are_rejected_as_not_vacancies(): void
    {
        $post = $this->makePost([
            'text' => 'Open to Work. Estou em busca de recolocação e disponível para novas oportunidades em suporte técnico.',
        ]);

        app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
            'decision_reason' => 'Publicação de pessoa buscando recolocação, emprego ou divulgando disponibilidade profissional; não é anúncio de vaga.',
        ]);
    }

    public function test_personal_new_job_announcement_is_rejected(): void
    {
        $post = $this->makePost([
            'text' => 'Estou muito feliz em compartilhar que comecei em um novo cargo na empresa Acme. Obrigado a todos!',
        ]);

        app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
            'decision_reason' => 'Relato pessoal de novo emprego, contratação ou promoção; não é anúncio de oportunidade.',
        ]);
    }

    public function test_personal_promotion_announcement_is_rejected(): void
    {
        $post = $this->makePost([
            'text' => 'Fui promovida a coordenadora de tecnologia. Muito orgulho dessa conquista!',
        ]);

        app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
            'decision_reason' => 'Relato pessoal de novo emprego, contratação ou promoção; não é anúncio de oportunidade.',
        ]);
    }

    public function test_service_advertisement_is_rejected(): void
    {
        $post = $this->makePost([
            'text' => 'Oferecemos soluções de outsourcing de TI e alocação de profissionais para empresas. Conheça nossos serviços.',
        ]);

        app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
            'decision_reason' => 'Publicidade de serviços, consultoria, cursos ou fornecimento de profissionais; não é anúncio de contratação.',
        ]);
    }

    public function test_happy_recruiter_announcing_a_real_vacancy_is_not_misclassified_as_a_personal_update(): void
    {
        $post = $this->makePost([
            'authorHeadline' => 'Senior Tech Recruiter',
            'text' => 'Estou muito feliz e orgulhosa em anunciar que estamos contratando Desenvolvedor PHP Júnior para nosso time!',
        ]);

        app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
            'decision_reason' => 'Publicação sem link de candidatura ou e-mail corporativo.',
        ]);
    }


    public function test_post_with_corporate_email_only_is_queued_for_automatic_publication(): void
    {
        $post = $this->makePost([
            'text' => 'Estamos contratando Desenvolvedor PHP Júnior. Empresa: Acme Tecnologia. Remoto Brasil. Envie seu currículo para talentos@acme.com.',
        ]);

        $result = app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertSame(0, $result['rejected']);
        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'pending_publication',
            'application_url' => 'mailto:talentos@acme.com',
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
