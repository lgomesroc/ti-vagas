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

    public function test_personal_email_is_ignored_when_a_valid_application_link_is_available(): void
    {
        $this->mock(LinkedInUrlInspector::class)
            ->shouldReceive('inspect')
            ->once()
            ->with('https://acme.example/jobs/123')
            ->andReturn([
                'valid' => true,
                'verified' => true,
                'blocked' => false,
                'final_url' => 'https://acme.example/jobs/123',
                'reason' => null,
                'page_title' => 'Desenvolvedor PHP Júnior',
                'page_description' => 'Vaga para Desenvolvedor PHP Júnior.',
                'page_text' => 'Candidate-se pelo canal oficial.',
            ]);

        $post = $this->makePost([
            'text' => "Estamos contratando!\nDesenvolvedor PHP Júnior\nEmpresa: Acme Tecnologia\nRemoto Brasil\nCandidatura: https://acme.example/jobs/123\ncontato@gmail.com",
        ]);

        $result = app(LinkedInPostCandidateProcessor::class)->process($post);

        $candidateReason = LinkedInPostCandidate::query()
            ->where('post_id', 'urn:li:activity:1001')
            ->value('decision_reason');
        $this->assertSame(0, $result['rejected'], 'Motivo de rejeição: ' . ($candidateReason ?? 'não informado'));
        $candidate = LinkedInPostCandidate::query()->where('post_id', 'urn:li:activity:1001')->firstOrFail();
        $this->assertSame('pending_publication', $candidate->status);
        $this->assertSame('https://acme.example/jobs/123', $candidate->application_url);
        $this->assertSame([], $candidate->emails);
    }

    public function test_personal_email_without_an_application_link_is_rejected(): void
    {
        $post = $this->makePost([
            'text' => 'Estamos contratando Desenvolvedor PHP Júnior. Empresa: Acme Tecnologia. Remoto Brasil. contato@gmail.com',
        ]);

        $result = app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertSame(1, $result['rejected']);
        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
            'decision_reason' => 'E-mail de provedor pessoal/gratuito não é aceito como canal de candidatura.',
        ]);

        $candidate = LinkedInPostCandidate::query()->where('post_id', 'urn:li:activity:1001')->firstOrFail();
        $this->assertSame([], $candidate->emails);
    }

    public function test_seniority_mismatch_is_preserved_for_later_editorial_review(): void
    {
        $post = $this->makePost([
            'text' => 'Estamos contratando Analista de Suporte Sênior. Empresa: Acme Tecnologia. Remoto Brasil. Responsabilidades: atender usuários, investigar incidentes técnicos, documentar soluções, acompanhar chamados e apoiar a operação dos serviços internos. Requisitos: experiência com Windows, Active Directory, redes TCP/IP, ferramentas de service desk e atendimento ao usuário. Envie seu currículo para recrutamento@acme.com.',
        ]);

        $result = app(LinkedInPostCandidateProcessor::class)->process($post);

        $candidate = LinkedInPostCandidate::query()
            ->where('post_id', 'urn:li:activity:1001')
            ->firstOrFail();
        $details = json_encode([
            'reason' => $candidate->decision_reason,
            'title' => $candidate->title,
            'company' => $candidate->company,
            'location' => $candidate->location,
            'work_model' => $candidate->work_model,
            'description_length' => mb_strlen((string) $candidate->description),
        ], JSON_UNESCAPED_UNICODE);
        $this->assertSame(0, $result['rejected'], 'Diagnóstico: ' . $details);
        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'pending_publication',
            'title' => 'Analista de Suporte Sênior',
            'application_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:1001/',
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

    public function test_engagement_bait_in_a_public_comment_is_rejected(): void
    {
        $post = $this->makePost([
            'text' => 'Estamos contratando Desenvolvedor PHP Júnior. Envie currículo para talentos@acme.com.',
            'topComments' => [
                ['text' => 'Comenta aqui que mando o link na DM.'],
            ],
        ]);

        app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
            'decision_reason' => 'Publicação ou comentário condiciona link ou informações a comentários, palavra-chave ou contato por DM.',
        ]);
    }

    public function test_ordinary_public_comment_does_not_trigger_engagement_bait_rejection(): void
    {
        $post = $this->makePost([
            'text' => 'Estamos contratando Desenvolvedor PHP Júnior. Empresa: Acme Tecnologia. Remoto Brasil. Responsabilidades: desenvolver e manter APIs, corrigir falhas, escrever testes automatizados, revisar código e colaborar com o time de produto. Requisitos: conhecimento em PHP, orientação a objetos, SQL, HTTP, APIs REST e Git. Envie currículo para talentos@acme.com.',
            'topComments' => [
                ['text' => 'Tenho interesse e vou me candidatar pelo canal informado.'],
            ],
        ]);

        app(LinkedInPostCandidateProcessor::class)->process($post);

        $candidate = LinkedInPostCandidate::query()
            ->where('post_id', 'urn:li:activity:1001')
            ->firstOrFail();
        $details = json_encode([
            'reason' => $candidate->decision_reason,
            'title' => $candidate->title,
            'company' => $candidate->company,
            'location' => $candidate->location,
            'work_model' => $candidate->work_model,
            'description_length' => mb_strlen((string) $candidate->description),
        ], JSON_UNESCAPED_UNICODE);
        $this->assertSame('pending_publication', $candidate->status, 'Diagnóstico: ' . $details);
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


    public function test_market_opinion_without_a_concrete_hiring_announcement_is_rejected(): void
    {
        $post = $this->makePost([
            'text' => 'Minha opinião sobre o mercado de TI: o recrutamento está cada vez mais difícil. O que vocês acham dos processos seletivos atuais?',
        ]);

        app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
            'decision_reason' => 'Opinião, reflexão ou debate sobre mercado de trabalho, recrutamento ou carreira; não anuncia contratação.',
        ]);
    }

    public function test_personal_project_and_portfolio_posts_are_rejected(): void
    {
        $post = $this->makePost([
            'text' => 'Compartilho meu projeto pessoal: desenvolvi uma aplicação em Laravel e publiquei o repositório no GitHub para demonstrar meu portfólio.',
        ]);

        app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
            'decision_reason' => 'Projeto pessoal, portfólio, demonstração ou aplicação de estudo; não é anúncio de vaga.',
        ]);
    }

    public function test_study_project_demonstration_is_rejected(): void
    {
        $post = $this->makePost([
            'text' => 'Projeto de estudo: criei um sistema de controle de tarefas para praticar .NET. Veja a demo do projeto.',
        ]);

        app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'rejected',
            'decision_reason' => 'Projeto pessoal, portfólio, demonstração ou aplicação de estudo; não é anúncio de vaga.',
        ]);
    }

    public function test_real_recruitment_post_is_preserved_when_it_mentions_projects_and_technical_challenges(): void
    {
        // acme.example é um domínio reservado para documentação e não resolve
        // em DNS. Simulamos a verificação externa para manter o teste determinístico.
        $this->mock(LinkedInUrlInspector::class)
            ->shouldReceive('inspect')
            ->once()
            ->with('https://acme.example/jobs/123')
            ->andReturn([
                'valid' => true,
                'verified' => true,
                'blocked' => false,
                'final_url' => 'https://acme.example/jobs/123',
                'reason' => null,
                'page_title' => 'Desenvolvedor .NET Júnior',
                'page_description' => 'Vaga para Desenvolvedor .NET Júnior.',
                'page_text' => 'Candidate-se para trabalhar com C# e SQL Server.',
            ]);

        $post = $this->makePost([
            'authorHeadline' => 'Tech Recruiter',
            'text' => 'Estamos contratando Desenvolvedor .NET Júnior. Remoto Brasil. Você vai trabalhar em projetos reais e resolver desafios técnicos com C# e SQL Server. Candidate-se: https://acme.example/jobs/123',
        ]);

        app(LinkedInPostCandidateProcessor::class)->process($post);

        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'pending_publication',
            'application_url' => 'https://acme.example/jobs/123',
        ]);
    }

    public function test_post_with_corporate_email_only_uses_original_post_as_application_source(): void
    {
        $post = $this->makePost([
            'text' => 'Estamos contratando Desenvolvedor PHP Júnior. Empresa: Acme Tecnologia. Remoto Brasil. Responsabilidades: desenvolver e manter APIs, corrigir falhas, escrever testes automatizados, revisar código e colaborar com o time de produto. Requisitos: conhecimento em PHP, orientação a objetos, SQL, HTTP, APIs REST e Git. Envie seu currículo para talentos@acme.com.',
        ]);

        $result = app(LinkedInPostCandidateProcessor::class)->process($post);

        $candidate = LinkedInPostCandidate::query()
            ->where('post_id', 'urn:li:activity:1001')
            ->firstOrFail();
        $details = json_encode([
            'reason' => $candidate->decision_reason,
            'title' => $candidate->title,
            'company' => $candidate->company,
            'location' => $candidate->location,
            'work_model' => $candidate->work_model,
            'description_length' => mb_strlen((string) $candidate->description),
        ], JSON_UNESCAPED_UNICODE);
        $this->assertSame(0, $result['rejected'], 'Diagnóstico: ' . $details);
        $this->assertDatabaseHas('linkedin_post_candidates', [
            'post_id' => 'urn:li:activity:1001',
            'status' => 'pending_publication',
            'application_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:1001/',
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
