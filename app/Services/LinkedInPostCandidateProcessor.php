<?php

namespace App\Services;

use App\Models\LinkedInPostCandidate;
use App\Models\Vacancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class LinkedInPostCandidateProcessor
{
    public function __construct(
        private readonly LinkedInUrlInspector $urlInspector,
        private readonly VacancyLocationFilter $locationFilter
    ) {
    }

    /**
     * @param array<string, mixed> $post
     * @return array{created: int, updated: int, rejected: int, duplicates: int}
     */
    public function process(array $post): array
    {
        $postUrl = $this->stringValue($post, ['postUrl', 'url', 'linkedinUrl']);
        $postId = $this->stringValue($post, ['postId', 'id', 'urn']);

        if ($postUrl === null || !str_contains(strtolower($postUrl), 'linkedin.com')) {
            return $this->emptyResult('rejected');
        }

        $postId ??= hash('sha256', $postUrl);

        $text = $this->stringValue($post, ['text', 'postText', 'content']) ?? '';
        $comments = $this->comments($post['topComments'] ?? $post['comments'] ?? []);
        $commentText = implode("\n", array_column($comments, 'text'));
        $combinedText = trim($text . "\n" . $commentText);

        $publishedAt = $this->publishedAt($post);

        $candidate = LinkedInPostCandidate::query()->firstOrNew([
            'post_id' => $postId,
        ]);

        $isNew = !$candidate->exists;

        $base = [
            'post_url' => $postUrl,
            'author_name' => $this->stringValue($post, ['authorName', 'author', 'author_name']),
            'author_url' => $this->stringValue($post, ['authorUrl', 'authorProfileUrl', 'author_url']),
            'author_headline' => $this->stringValue($post, ['authorHeadline', 'authorDescription', 'author_headline']),
            'author_type' => $this->stringValue($post, ['authorType', 'author_type']),
            'matched_keyword' => $this->stringValue($post, ['matchedKeyword']),
            'published_at' => $publishedAt,
            'post_text' => $text,
            'comments' => $comments,
            'metadata' => $post,
        ];

        // Rejeita quando a fonte informa explicitamente que o post é promovido.
        // Os buscadores atuais não fornecem esse metadado; nesse caso não inventamos
        // um valor e mantemos a limitação documentada.
        if (($post['isPromoted'] ?? false) === true) {
            $candidate->fill($base + [
                'status' => 'rejected',
                'decision_reason' => 'Publicação promovida/patrocinada descartada.',
            ])->save();

            return $this->emptyResult('rejected');
        }

        if ($publishedAt === null || $publishedAt->lt(now()->subHours(
            (int) config('linkedin.lookback_hours', 72)
        ))) {
            $candidate->fill($base + [
                'status' => 'rejected',
                'decision_reason' => 'Publicação fora da janela de coleta de 72 horas ou sem data verificável.',
            ])->save();

            return $this->emptyResult('rejected');
        }

        if ($this->isEngagementBait($text)) {
            $candidate->fill($base + [
                'status' => 'rejected',
                'decision_reason' => 'Publicação condiciona link ou informações a comentários, palavra-chave ou contato por DM.',
            ])->save();

            return $this->emptyResult('rejected');
        }

        $nonVacancyReason = $this->nonVacancyContentReason($text, $post);
        if ($nonVacancyReason !== null) {
            $candidate->fill($base + [
                'status' => 'rejected',
                'decision_reason' => $nonVacancyReason,
            ])->save();

            return $this->emptyResult('rejected');
        }

        if (!$this->isRecruitmentPost($post, $text)) {
            $candidate->fill($base + [
                'status' => 'rejected',
                'decision_reason' => 'Não foi possível identificar publicação de recrutamento ou anúncio de oportunidade.',
            ])->save();

            return $this->emptyResult('rejected');
        }

        $links = $this->extractUrls($combinedText, $post);
        $emails = array_values(array_unique(array_merge(
            $this->extractEmails($combinedText),
            is_array($post['emails'] ?? null) ? array_map('strtolower', $post['emails']) : []
        )));

        $hasApplicationEmail = $emails !== [];

        if (
            (
                ($post['searchProvider'] ?? null) === 'linkedin-secondary-account'
                || ($post['secondaryAccountSource'] ?? false) === true
                || in_array('linkedin-secondary-account', $post['searchProviders'] ?? [], true)
            ) && $links === [] && !$hasApplicationEmail
        ) {
            $candidate->fill($base + [
                'embedded_links' => [],
                'emails' => $emails,
                'status' => 'rejected',
                'decision_reason' => 'Conta secundária: publicação sem link público de candidatura.',
            ])->save();

            return $this->emptyResult('rejected');
        }

        if ($links === [] && !$hasApplicationEmail) {
            $candidate->fill($base + [
                'embedded_links' => [],
                'emails' => $emails,
                'status' => 'rejected',
                'decision_reason' => $emails === []
                    ? 'Publicação sem link de candidatura ou e-mail corporativo.'
                    : 'E-mail de provedor público/gratuito não é aceito como canal de candidatura.',
            ])->save();

            return $this->emptyResult('rejected');
        }

        foreach ($links as $link) {
            $duplicateVacancy = $this->findDuplicate(null, null, null, null, $link, '');
            $duplicateCandidate = $this->findDuplicateCandidate(
                null,
                null,
                null,
                null,
                $link,
                '',
                $postId
            );

            if ($duplicateVacancy !== null || $duplicateCandidate !== null) {
                $candidate->fill($base + [
                    'embedded_links' => [['url' => $link]],
                    'emails' => $emails,
                    'status' => 'duplicate',
                    'decision_reason' => $duplicateVacancy !== null
                        ? 'Link de candidatura já cadastrado na vaga #' . $duplicateVacancy->id . '.'
                        : 'Link de candidatura já encontrado no candidato #' . $duplicateCandidate->id . '.',
                    'duplicate_of_vacancy_id' => $duplicateVacancy?->id,
                    'duplicate_of_candidate_id' => $duplicateCandidate?->id,
                ])->save();

                return $this->emptyResult('duplicates');
            }
        }

        $verifiedLinks = [];
        foreach ($links as $link) {
            $inspection = $this->urlInspector->inspect($link);
            $verifiedLinks[] = ['url' => $link] + $inspection;

            if ($inspection['blocked']) {
                $candidate->fill($base + [
                    'embedded_links' => $verifiedLinks,
                    'emails' => $emails,
                    'status' => 'rejected',
                    'decision_reason' => $inspection['reason'],
                ])->save();

                return $this->emptyResult('rejected');
            }
        }

        $pageContent = implode("\n", array_filter(array_map(
            fn (array $link): string => implode("\n", array_filter([
                $link['page_title'] ?? null,
                $link['page_description'] ?? null,
                $link['page_text'] ?? null,
            ])),
            $verifiedLinks
        )));

        $applicationUrl = null;
        foreach ($verifiedLinks as $link) {
            if (
                $link['verified']
                && $link['valid']
                && !$link['blocked']
                && $this->isPlausibleApplicationLink($link['final_url'])
            ) {
                $applicationUrl = $link['final_url'];
                break;
            }
        }

        // Quando a publicação oferece e-mail, o TI Vagas exibe a própria
        // publicação como origem da oportunidade. O e-mail permanece no texto
        // original para que o candidato veja as instruções da pessoa recrutadora.
        if ($applicationUrl === null && $hasApplicationEmail) {
            $applicationUrl = $postUrl;
        }

        $enrichedText = trim($text . "\n" . $pageContent);
        $postTitle = $this->stringValue($post, ['title']);
        $title = $this->extractTitle(
            $postTitle !== null ? $postTitle . "\n" . $enrichedText : $enrichedText
        );
        $company = $this->extractCompany($enrichedText, $post);
        $location = $this->extractLocation($enrichedText);
        $workModel = $this->classifyWorkModel($combinedText . "\n" . $pageContent);
        $description = trim(
            $enrichedText
            . ($commentText !== '' ? "\n\nComentários públicos:\n" . $commentText : '')
        );

        $reason = $this->validateBusinessRules(
            $title,
            $description,
            $location,
            $workModel,
            $applicationUrl,
            $emails
        );

        $duplicate = $this->findDuplicate(
            $title,
            $company,
            $location,
            $workModel,
            $applicationUrl,
            $description
        );

        $duplicateCandidate = $this->findDuplicateCandidate(
            $title,
            $company,
            $location,
            $workModel,
            $applicationUrl,
            $description,
            $postId
        );

        if ($duplicate !== null || $duplicateCandidate !== null) {
            $candidate->fill($base + [
                'embedded_links' => $verifiedLinks,
                'emails' => $emails,
                'title' => $title,
                'company' => $company,
                'description' => $description,
                'location' => $location,
                'work_model' => $workModel,
                'application_url' => $applicationUrl,
                'status' => 'duplicate',
                'decision_reason' => $duplicate !== null
                    ? 'Possível duplicidade com vaga já cadastrada: #' . $duplicate->id
                    : 'Possível duplicidade com outro post do LinkedIn: #' . $duplicateCandidate->id,
                'duplicate_of_vacancy_id' => $duplicate?->id,
                'duplicate_of_candidate_id' => $duplicateCandidate?->id,
            ])->save();

            return $this->emptyResult('duplicates');
        }

        $candidate->fill($base + [
            'embedded_links' => $verifiedLinks,
            'emails' => $emails,
            'title' => $title,
            'company' => $company,
            'description' => $description,
            'location' => $location,
            'work_model' => $workModel,
            'application_url' => $applicationUrl,
            'status' => $reason === null ? 'pending_publication' : 'rejected',
            'decision_reason' => $reason ?? 'Passou pelos filtros automáticos e aguarda publicação automática.',
        ])->save();

        return [
            'created' => $isNew ? 1 : 0,
            'updated' => $isNew ? 0 : 1,
            'rejected' => $reason === null ? 0 : 1,
            'duplicates' => 0,
        ];
    }

    private function isEngagementBait(string $text): bool
    {
        $normalized = $this->normalize($text);

        $patterns = [
            '/\b(comente|comenta|digite|escreva|coloque|deixe)\b.{0,100}\b(eu quero|quero|interesse|vaga|link|php|sim|me)\b/',
            '/\b(comente|digite|escreva)\b.{0,100}\b(que|para)\b.{0,80}\b(dm|direct|privado|mensagem|inbox)\b/',
            '/\b(comente|digite|escreva)\b.{0,100}\b(para receber|para ter acesso|para obter)\b.{0,60}\b(link|email|e-mail|detalhes|informacoes)\b/',
            '/\b(link|email|e-mail|detalhes|informacoes)\b.{0,100}\b(dm|direct|privado|inbox)\b/',
            '/\b(chama|chame|me chama|me chame|mande|envio|enviar|fale comigo|entre em contato)\b.{0,100}\b(dm|direct|privado|inbox|mensagem privada)\b/',
            '/\b(direct|dm|inbox|privado)\b.{0,100}\b(saber mais|mais informacoes|detalhes|curriculo|perfil)\b/',
            '/\b(so|apenas)\b.{0,50}\b(comentando|comentar|digitar|mandar mensagem)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Descarta publicações que mencionam cargos de TI, mas não anunciam vaga.
     */
    private function nonVacancyContentReason(string $text, array $post = []): ?string
    {
        $normalized = $this->normalize($text);

        // Um sinal de vaga precisa ser explícito. Palavras emocionais isoladas
        // ("feliz", "orgulho", "parabéns") nunca devem decidir a classificação.
        $authorContext = $this->normalize(
            ($post['authorHeadline'] ?? '') . ' ' .
            ($post['authorDescription'] ?? '') . ' ' .
            ($post['authorType'] ?? '')
        );
        $isRecruiterOrCompany = in_array(
            $this->normalize((string) ($post['authorType'] ?? '')),
            ['company', 'organization', 'organisation', 'company page'],
            true
        );
        foreach (config('linkedin.recruiter_terms', []) as $term) {
            if (str_contains($authorContext, $this->normalize((string) $term))) {
                $isRecruiterOrCompany = true;
                break;
            }
        }

        $explicitVacancy = preg_match(
            '/\\b(vaga(?:s)?\\s+(?:aberta|abertas|para|de)|estamos\\s+contratando|estamos\\s+a\\s+procura|oportunidade\\s+(?:para|em)|we\\s+are\\s+hiring|job\\s+opportunity|apply\\s+now|candidate-se|inscreva-se|envie\\s+(?:seu\\s+)?curriculo|send\\s+(?:your\\s+)?resume)\\b/',
            $normalized
        ) === 1;

        // Só preserva conteúdo de mercado/projeto quando há sinal concreto de
        // contratação. Menções genéricas a vagas, tecnologia ou desafios não bastam.
        $explicitVacancy = $explicitVacancy || preg_match(
            '/\\b(?:contratando|hiring|join\\s+our\\s+team|venha\\s+trabalhar|vem\\s+trabalhar|estamos\\s+buscando\\s+(?:um|uma|profissional|desenvolvedor|analista|tecnico)|buscamos\\s+(?:um|uma|profissional|desenvolvedor|analista|tecnico))\\b/',
            $normalized
        ) === 1;

        $marketOpinion = preg_match(
            '/\\b(?:minha\\s+opiniao|na\\s+minha\\s+visao|o\\s+mercado\\s+de\\s+trabalho|mercado\\s+de\\s+ti|mercado\\s+tech|mercado\\s+de\\s+tecnologia|recrutamento|processos?\\s+seletivos?|contratacao|empregabilidade|falta\\s+de\\s+oportunidades|escassez\\s+de\\s+vagas|dificuldade\\s+para\\s+conseguir\\s+emprego|carreira\\s+em\\s+ti)\\b/',
            $normalized
        ) === 1;

        $opinionLanguage = preg_match(
            '/\\b(?:nao\\s+acha[m]?|acredito\\s+que|penso\\s+que|considero\\s+que|o\\s+que\\s+voc[eê]s\\s+acham|qual\\s+e\\s+a\\s+opiniao|vamos\\s+debater|vale\\s+refletir|precisamos\\s+discutir|concorda[m]?\\s+comigo|e\\s+impressao\\s+minha)\\b/',
            $normalized
        ) === 1;

        if (($marketOpinion || $opinionLanguage) && !$explicitVacancy) {
            return 'Opinião, reflexão ou debate sobre mercado de trabalho, recrutamento ou carreira; não anuncia contratação.';
        }

        $personalProject = preg_match(
            '/\\b(?:meu\\s+projeto|projeto\\s+pessoal|projeto\\s+proprio|desenvolvi\\s+(?:um|uma|o|a)|criei\\s+(?:um|uma|o|a)|constru[ií]\\s+(?:um|uma|o|a)|aplicativo\\s+que\\s+desenvolvi|sistema\\s+que\\s+criei|portfolio|portf[oó]lio|reposit[oó]rio\\s+(?:no\\s+)?github|github\\s+repo|projeto\\s+de\\s+estudo|projeto\\s+da\\s+faculdade|estudo\\s+de\\s+caso|demo\\s+do\\s+projeto|demonstra[cç][aã]o\\s+do\\s+projeto|apresento\\s+meu\\s+projeto|compartilho\\s+meu\\s+projeto)\\b/',
            $normalized
        ) === 1;

        if ($personalProject && !$explicitVacancy) {
            return 'Projeto pessoal, portfólio, demonstração ou aplicação de estudo; não é anúncio de vaga.';
        }

        // Publicações de recolocação: a pessoa fala de si, procura emprego
        // ou informa disponibilidade. "Open to work" sozinho não basta.
        $jobSeeking = preg_match(
            '/\\b(?:estou\\s+)?(?:open\\s+to\\s+work|open\\s+for\\s+work|looking\\s+for\\s+(?:a\\s+)?new\\s+(?:job|role|opportunity)|seeking\\s+(?:a\\s+)?new\\s+(?:job|role|opportunity)|buscando\\s+(?:uma\\s+)?(?:recolocacao|oportunidade|emprego)|em\\s+busca\\s+de\\s+(?:recolocacao|oportunidade|emprego)|procuro\\s+(?:uma\\s+)?(?:vaga|oportunidade|emprego)|disponivel\\s+para\\s+(?:novas\\s+)?oportunidades|disponivel\\s+no\\s+mercado|em\\s+transicao\\s+de\\s+carreira|fui\\s+desligad[oa]|fui\\s+demitid[oa]|perdi\\s+(?:meu\\s+)?emprego|fiquei\\s+desempregad[oa]|estou\\s+desempregad[oa]|desempregad[oa]\\s+em\\s+busca|desemprego|unemployed|laid\\s+off|between\\s+jobs|looking\\s+for\\s+work|actively\\s+seeking)\\b/',
            $normalized
        ) === 1;

        if ($jobSeeking && !($explicitVacancy && $isRecruiterOrCompany)) {
            return 'Publicação de pessoa buscando recolocação, emprego ou divulgando disponibilidade profissional; não é anúncio de vaga.';
        }

        // Notícias e relatos sobre cortes de pessoal. "Layoff(s)" isolado não
        // deve derrubar uma vaga real que apenas mencione cortes no mercado.
        $layoffContext = preg_match(
            '/\\b(?:layoffs?|laid\\s+off|mass\\s+layoffs?|workforce\\s+(?:reduction|cuts)|job\\s+cuts|cortes?\\s+(?:de\\s+)?pessoal|demissoes?\\s+em\\s+massa|redu[cç][aã]o\\s+de\\s+quadro|funcionarios\\s+(?:demitidos|desligados)|colaboradores\\s+(?:demitidos|desligados)|fui\\s+afetad[oa]\\s+pel[oa]s?\\s+layoffs?)\\b/',
            $normalized
        ) === 1;

        if ($layoffContext && !$explicitVacancy) {
            return 'Publicação sobre layoff, layoffs, cortes de pessoal ou demissões; não anuncia uma vaga.';
        }

        // Relatos sobre candidatura sem retorno, demora ou eliminação em
        // processo seletivo. Exigimos contexto de recrutamento para não
        // confundir "demora" ou "eliminado" com outros assuntos.
        $recruitmentProcessContext = preg_match(
            '/\\b(?:processo\\s+seletivo|processos\\s+seletivos|processo\\s+de\\s+selecao|candidatura|candidaturas|candidato|candidata|entrevista|entrevistas|recrutador|recrutadora|recrutamento|selecao|rh|vaga|vagas|application|hiring\\s+process|recruitment\\s+process|interview|job\\s+application)\\b/',
            $normalized
        ) === 1;

        $applicationRejection = preg_match(
            '/\\b(?:eliminad[oa]s?|reprovad[oa]s?|nao\\s+aprovad[oa]s?|rejeitad[oa]s?|rejected|not\\s+selected|unsuccessful)\\b/',
            $normalized
        ) === 1;

        $noRecruiterResponse = preg_match(
            '/\\b(?:sem\\s+(?:nenhum\\s+)?retorno|nao\\s+(?:tive|recebi|obtive)\\s+(?:nenhum\\s+)?retorno|nunca\\s+recebi\\s+retorno|no\\s+response|no\\s+feedback|ghosting)\\b/',
            $normalized
        ) === 1;

        $slowRecruitmentProcess = preg_match(
            '/\\b(?:demora|demorado|demorada|demorando|muito\\s+tempo|tempo\\s+de\\s+espera|processo\\s+lento|demorado\\s+demais|taking\\s+too\\s+long|long\\s+waiting\\s+time)\\b/',
            $normalized
        ) === 1;

        if (
            $recruitmentProcessContext
            && ($applicationRejection || $noRecruiterResponse || $slowRecruitmentProcess)
            && !$explicitVacancy
        ) {
            return 'Relato ou reclamação sobre candidatura, eliminação, falta de retorno ou demora em processo seletivo; não é anúncio de vaga.';
        }

        // Relatos pessoais de contratação, promoção ou início em novo emprego.
        // "Orgulho" e "feliz" só contam quando ligados a um evento pessoal.
        $personalCareerUpdate = preg_match(
            '/\\b(?:estou\\s+)?(?:muito\\s+)?(?:feliz|orgulhos[oa]|orgulho|honrad[oa]|grateful|excited|thrilled)\\s+(?:(?:em|de|por)\\s+)?(?:anunciar|compartilhar|contar|dizer|celebrar)\\b.{0,180}\\b(?:novo\\s+(?:emprego|cargo|desafio|papel)|nova\\s+(?:posicao|funcao|oportunidade)|fui\\s+contratad[oa]|fui\\s+promovid[oa]|promovida|promovido|comecei\\s+(?:a\\s+trabalhar|na|no|em)|joined|new\\s+(?:job|role|position)|promoted\\s+to|starting\\s+my)\\b/',
            $normalized
        ) === 1;

        $firstPersonCareerChange = preg_match(
            '/\\b(?:comecei\\s+(?:a\\s+trabalhar|na|no|em)|assumi\\s+(?:o\\s+)?novo\\s+cargo|fui\\s+contratad[oa]|fui\\s+promovid[oa]|promovid[oa]\\s+a\\s+(?:um\\s+)?(?:cargo|posicao|gerente|coordenador)|promoted\\s+to|recebi\\s+uma\\s+promocao|minha\\s+promocao|consegui\\s+(?:um\\s+)?novo\\s+(?:emprego|trabalho|cargo)|aceitei\\s+(?:uma\\s+)?(?:proposta|oferta)\\s+de\\s+emprego|acabei\\s+de\\s+ser\\s+contratad[oa]|me\\s+juntei\\s+(?:a|ao|aos)\\s+(?:equipe|time|team)|meu\\s+novo\\s+(?:emprego|trabalho|cargo|desafio)|minha\\s+nova\\s+(?:posicao|funcao)|new\\s+role\\s+at|started\\s+(?:a\\s+)?new\\s+(?:job|role|position)|joined\\s+(?:the\\s+)?(?:team|company)|promoted\\s+to|celebrating\\s+my\\s+promotion)\\b/',
            $normalized
        ) === 1;

        if (($personalCareerUpdate || $firstPersonCareerChange) && !($explicitVacancy && $isRecruiterOrCompany)) {
            return 'Relato pessoal de novo emprego, contratação ou promoção; não é anúncio de oportunidade.';
        }

        // Propaganda de serviços e produtos. Termos comerciais genéricos
        // precisam aparecer junto de uma oferta explícita, não isoladamente.
        $serviceAdvertisement = preg_match(
            '/\\b(?:oferecemos|prestamos|contrate|conheca|conheca nossos|fale com|solicite|agende)\\b.{0,100}\\b(?:servicos|solucoes|consultoria|outsourcing|terceirizacao|alocacao|equipe|profissionais|software|sistema|plataforma|treinamento|curso|mentoria)\\b|\\b(?:outsourcing de ti|body shop|alocacao de profissionais|terceirizacao de equipes|fabrica de software|consultoria especializada|inscricoes abertas para o curso|matriculas abertas|garanta sua vaga no curso|participe do webinar|inscreva-se no treinamento)\\b/',
            $normalized
        ) === 1;

        if ($serviceAdvertisement && !$explicitVacancy) {
            return 'Publicidade de serviços, consultoria, cursos ou fornecimento de profissionais; não é anúncio de contratação.';
        }

        $congratulationsOnly = preg_match(
            '/\\b(?:parabens|congratulations|felicidades)\\b/',
            $normalized
        ) === 1 && preg_match(
            '/\\b(?:vaga|oportunidade|contratando|hiring|curriculo|candidate-se|apply)\\b/',
            $normalized
        ) !== 1;

        if ($congratulationsOnly) {
            return 'Publicação de parabéns ou interação social sem anúncio de oportunidade.';
        }

        return null;
    }

    private function isRecruitmentPost(array $post, string $text): bool
    {
        $normalized = $this->normalize($text);
        $author = $this->normalize(
            ($post['authorHeadline'] ?? '') . ' ' .
            ($post['authorDescription'] ?? '') . ' ' .
            ($post['authorType'] ?? '')
        );

        $hasRecruitmentSignal = false;
        foreach (config('linkedin.recruiter_terms', []) as $term) {
            if (str_contains($author, $this->normalize((string) $term))) {
                $hasRecruitmentSignal = true;
                break;
            }
        }

        $postSignals = [
            'estamos contratando', 'estamos a procura', 'estamos à procura',
            'vaga aberta', 'vaga para', 'oportunidade para', 'oportunidade em',
            'contratando', 'vem trabalhar', 'venha trabalhar', 'curriculos',
            'currículos', 'envie seu cv', 'envie seu curriculo',
            'hiring', 'we are hiring', 'job opportunity', 'join our team',
            'apply now', 'looking for',
        ];

        $isCompanyAuthor = in_array(
            $this->normalize((string) ($post['authorType'] ?? '')),
            ['company', 'organization', 'organisation', 'company page'],
            true
        );

        foreach ($postSignals as $signal) {
            if (
                str_contains($normalized, $this->normalize($signal))
                && (
                    $hasRecruitmentSignal
                    || $isCompanyAuthor
                    || in_array(
                        $post['searchProvider'] ?? null,
                        ['duckduckgo-html', 'bing-rss', 'linkedin-secondary-account'],
                        true
                    )
                )
            ) {
                return true;
            }
        }

        return $hasRecruitmentSignal && preg_match(
            '/\b(vaga|oportunidade|contratando|hiring|developer|desenvolvedor|analista|tecnico|engineer|programador)\b/',
            $normalized
        ) === 1;
    }

    private function validateBusinessRules(
        ?string $title,
        string $description,
        ?string $location,
        ?string $workModel,
        ?string $applicationUrl,
        array $emails
    ): ?string {
        $titleText = $this->normalize($title);
        $descriptionText = $this->normalize($description);

        if ($title === null) {
            return 'Não foi possível identificar o cargo da oportunidade.';
        }

        foreach ([
            'estagio', 'estagiario', 'trainee', 'internship', 'intern',
            'pleno', 'senior', 'sr', 'mid-level', 'mid level',
            'staff', 'principal', 'lead', 'manager', 'gerente', 'diretor',
            'head', 'especialista', 'coordenador', 'supervisor',
        ] as $term) {
            if (preg_match('/(^|[^a-z0-9])' . preg_quote($term, '/') . '([^a-z0-9]|$)/', $titleText)) {
                return 'Nível fora do escopo: somente vagas efetivas júnior.';
            }
        }

        if (preg_match('/\b(?:3|[4-9]|\d{2,})\s*(?:\+|plus)?\s*(?:[-–]\s*\d+\s*)?(?:anos?|years?)\b/', $descriptionText)) {
            return 'Descrição exige três anos ou mais de experiência.';
        }

        if (preg_match(
            '/\\b(?:nivel|posicao|vaga|cargo|perfil)\\s+(?:de\\s+|para\\s+)?(?:pleno|senior|sr|mid[- ]level|staff|principal|lead|especialista|coordenador|supervisor)\\b|\\b(?:pleno|senior|sr|mid[- ]level|staff|principal|lead)\\s+(?:developer|engineer|analyst|analista|desenvolvedor|programador|tecnico)\\b|\\b(?:experienced|experiente)\\s+(?:developer|engineer|analyst|analista|desenvolvedor|programador|tecnico)\\b/',
            $descriptionText
        )) {
            return 'A descrição identifica explicitamente a oportunidade como nível pleno, sênior ou equivalente.';
        }

        $excluded = [
            'crm', 'erp', 'sap', 'abap', 'business intelligence',
            'analista de dados', 'data analyst', 'data engineer',
            'data scientist', 'machine learning', 'deep learning',
            'cientista de dados', 'engenheiro de dados', 'bi analyst',
        ];

        foreach ($excluded as $term) {
            if (str_contains($titleText, $this->normalize($term))) {
                return 'Área fora do escopo editorial do TI Vagas.';
            }
        }

        $allowed = [
            'desenvolvedor', 'developer', 'programador', 'software engineer',
            'engenheiro de software', 'backend', 'back-end', 'frontend',
            'front-end', 'full stack', 'fullstack', 'php', '.net', 'java',
            'python', 'javascript', 'typescript', 'qa', 'quality assurance',
            'analista de testes', 'infraestrutura', 'infrastructure',
            'devops', 'cloud engineer', 'sysadmin', 'redes', 'network',
            'suporte', 'help desk', 'helpdesk', 'service desk', 'it support',
            'technical support', 'noc', 'security analyst', 'ciberseguranca',
        ];

        $isAllowed = false;
        foreach ($allowed as $term) {
            if (str_contains($titleText, $this->normalize($term))) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            return 'Cargo não pertence às áreas de desenvolvimento, QA, infraestrutura, DevOps, redes ou suporte.';
        }

        if ($applicationUrl === null) {
            return 'Sem link de candidatura verificado ou e-mail corporativo de candidatura.';
        }

        if (!$this->locationFilter->isEligibleForCollection(
            $location,
            $title,
            $description,
            $workModel
        )) {
            return 'Localidade ou modalidade incompatível com as regras de coleta do TI Vagas.';
        }

        return null;
    }

    private function findDuplicate(
        ?string $title,
        ?string $company,
        ?string $location,
        ?string $workModel,
        ?string $applicationUrl,
        string $description
    ): ?Vacancy {
        $query = Vacancy::query();

        if ($applicationUrl !== null) {
            $normalizedUrl = $this->normalizeUrl($applicationUrl);
            $match = $query->whereNotNull('application_url')->get()
                ->first(fn (Vacancy $vacancy): bool =>
                    $this->normalizeUrl($vacancy->application_url) === $normalizedUrl
                );

            if ($match !== null) {
                return $match;
            }

            $externalId = $this->extractExternalId($applicationUrl);
            if ($externalId !== null) {
                $externalMatch = Vacancy::query()
                    ->where('external_id', $externalId)
                    ->where('original_published_at', '>=', now()->subDays(60))
                    ->when($company !== null, fn ($builder) => $builder->whereRaw(
                        'LOWER(company) LIKE ?',
                        ['%' . mb_strtolower(mb_substr($company, 0, 80)) . '%']
                    ))
                    ->first();

                if ($externalMatch !== null) {
                    return $externalMatch;
                }
            }
        }

        if ($company === null || $title === null) {
            return null;
        }

        $companyNorm = $this->normalize($company);
        $titleNorm = $this->normalize($title);

        $possible = Vacancy::query()
            ->where('original_published_at', '>=', now()->subDays(60))
            ->whereRaw('LOWER(company) LIKE ?', [
                '%' . mb_strtolower(mb_substr($company, 0, 80)) . '%',
            ])
            ->get();

        foreach ($possible as $vacancy) {
            $existingTitle = $this->normalize($vacancy->title);
            similar_text($titleNorm, $existingTitle, $titleSimilarity);

            $sameLocation = $this->normalize($vacancy->location) === $this->normalize($location);
            $sameModel = $this->normalize($vacancy->work_model) === $this->normalize($workModel);

            similar_text(
                $this->normalize($vacancy->description),
                $this->normalize($description),
                $descriptionSimilarity
            );

            if (
                ($titleSimilarity >= 85 && ($sameLocation || $sameModel))
                || ($titleSimilarity >= 75 && $descriptionSimilarity >= 65)
            ) {
                return $vacancy;
            }
        }

        return null;
    }

    private function extractExternalId(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        foreach (['jobid', 'job_id', 'gh_jid', 'postingid', 'posting_id', 'requisitionid', 'requisition_id', 'id'] as $key) {
            $value = $query[$key] ?? null;
            if (is_scalar($value) && preg_match('/^[a-zA-Z0-9_-]{3,80}$/', (string) $value)) {
                return (string) $value;
            }
        }

        $path = $parts['path'] ?? '';
        if (preg_match('~/(?:jobs?|positions?|vacancies?)/([a-zA-Z0-9_-]{3,80})(?:/|$)~i', $path, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function findDuplicateCandidate(
        ?string $title,
        ?string $company,
        ?string $location,
        ?string $workModel,
        ?string $applicationUrl,
        string $description,
        string $currentPostId
    ): ?LinkedInPostCandidate {
        $query = LinkedInPostCandidate::query()
            ->where('post_id', '!=', $currentPostId)
            ->whereIn('status', ['pending_publication', 'pending_review', 'published', 'approved', 'duplicate']);

        if ($applicationUrl !== null) {
            $normalizedUrl = $this->normalizeUrl($applicationUrl);
            $match = $query->whereNotNull('application_url')->get()
                ->first(fn (LinkedInPostCandidate $candidate): bool =>
                    $this->normalizeUrl($candidate->application_url) === $normalizedUrl
                );

            if ($match !== null) {
                return $match;
            }
        }

        if ($company === null || $title === null) {
            return null;
        }

        $possible = LinkedInPostCandidate::query()
            ->where('post_id', '!=', $currentPostId)
            ->whereIn('status', ['pending_review', 'approved', 'duplicate'])
            ->whereRaw('LOWER(company) LIKE ?', [
                '%' . mb_strtolower(mb_substr($company, 0, 80)) . '%',
            ])
            ->get();

        foreach ($possible as $candidate) {
            similar_text(
                $this->normalize($title),
                $this->normalize($candidate->title),
                $titleSimilarity
            );

            $sameLocation = $this->normalize($candidate->location)
                === $this->normalize($location);
            $sameModel = $this->normalize($candidate->work_model)
                === $this->normalize($workModel);

            similar_text(
                $this->normalize($description),
                $this->normalize($candidate->description),
                $descriptionSimilarity
            );

            if (
                ($titleSimilarity >= 85 && ($sameLocation || $sameModel))
                || ($titleSimilarity >= 75 && $descriptionSimilarity >= 65)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    private function extractTitle(string $text): ?string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: [])));

        foreach ($lines as $line) {
            if (preg_match('/\b(j[uú]nior|jr\.?|desenvolvedor|developer|programador|analista|t[eé]cnico|engineer|qa|devops|suporte|infraestrutura)\b/i', $line)) {
                return mb_substr(trim(preg_replace('/^[#\-\s]+/', '', $line)), 0, 255);
            }
        }

        if (preg_match('/(?:vaga|oportunidade)\s+(?:para|de|em)?\s*([^\n.!]{5,100})/iu', $text, $matches)) {
            return mb_substr(trim($matches[1]), 0, 255);
        }

        return null;
    }

    private function extractCompany(string $text, array $post): ?string
    {
        $company = $this->stringValue($post, ['companyName', 'company', 'organizationName']);
        if ($company !== null) {
            return $company;
        }

        $taggedCompanies = $post['taggedCompanies'] ?? [];
        if (is_array($taggedCompanies)) {
            foreach ($taggedCompanies as $taggedCompany) {
                $name = is_array($taggedCompany)
                    ? ($taggedCompany['name'] ?? null)
                    : null;

                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }
        }

        if (preg_match('/(?:empresa\s*:\s*|(?:na|no|para a|para o|at|for)\s+)([A-Z][A-Za-z0-9&. -]{2,60})/u', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractLocation(string $text): ?string
    {
        $patterns = [
            '/\b(remoto|remote)\b.{0,35}\b(brasil|brazil|br)\b/i',
            '/\b(remoto|remote|h[ií]brido|hybrid|presencial|on-site)\b/i',
            '/\b([A-Z][a-zÀ-ÿ]+(?:\s+[A-Z][a-zÀ-ÿ]+)*\s*[-\/]\s*[A-Z]{2})\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim($matches[0]);
            }
        }

        return null;
    }

    private function classifyWorkModel(string $text): ?string
    {
        $normalized = $this->normalize($text);

        if (preg_match('/\b(h[ií]brido|hybrid)\b/', $normalized)) {
            return 'Híbrido';
        }

        if (preg_match('/\b(presencial|on-site|onsite)\b/', $normalized)) {
            return 'Presencial';
        }

        if (preg_match('/\b(remoto|remote|home office|fully remote)\b/', $normalized)) {
            return 'Remoto';
        }

        return null;
    }

    private function extractUrls(string $text, array $post): array
    {
        $urls = [];

        foreach (['links', 'embeddedLinks', 'externalLinks'] as $key) {
            $values = $post[$key] ?? [];
            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                if (is_string($value)) {
                    $urls[] = $value;
                } elseif (is_array($value)) {
                    $url = $value['url'] ?? $value['link'] ?? null;
                    if (is_string($url)) {
                        $urls[] = $url;
                    }
                }
            }
        }

        preg_match_all('~https?://[^\s<>"\']+~iu', $text, $matches);
        $urls = array_merge($urls, $matches[0] ?? []);

        return array_values(array_unique(array_map(
            fn ($url) => rtrim($url, ".,);]}>"),
            array_filter($urls, fn ($url) => filter_var($url, FILTER_VALIDATE_URL))
        )));
    }

    /**
     * Exige endereço em domínio corporativo conhecido; provedores públicos
     * de e-mail e domínios descartáveis não são aceitos como contato da empresa.
     */
    private function hasCorporateEmail(array $emails): bool
    {
        $personalDomains = [
            'gmail.com', 'googlemail.com', 'hotmail.com', 'outlook.com',
            'outlook.com.br', 'live.com', 'live.com.br', 'msn.com',
            'yahoo.com', 'yahoo.com.br', 'icloud.com', 'me.com',
            'mac.com', 'aol.com', 'proton.me', 'protonmail.com',
            'gmx.com', 'mail.com', 'uol.com.br', 'bol.com.br',
            'terra.com.br', 'ig.com.br', 'yandex.com', 'yandex.ru',
        ];

        foreach ($emails as $email) {
            if ($this->isCorporateEmail($email)) {
                return true;
            }
        }

        return false;
    }

    private function isCorporateEmail(string $email): bool
    {
        $personalDomains = [
            'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.com.br',
            'outlook.com', 'outlook.com.br', 'live.com', 'live.com.br', 'msn.com',
            'yahoo.com', 'yahoo.com.br', 'yahoo.co.uk', 'icloud.com', 'me.com',
            'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'tutanota.com',
            'tuta.io', 'gmx.com', 'gmx.net', 'mail.com', 'yandex.com', 'yandex.ru',
            'uol.com.br', 'bol.com.br', 'terra.com.br', 'ig.com.br',
            'r7.com', 'zipmail.com.br', 'fastmail.com', 'hey.com',
        ];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $domain = strtolower(substr(strrchr($email, '@'), 1));

        return $domain !== ''
            && !in_array($domain, $personalDomains, true)
            && !str_contains($domain, 'linkedin.');
    }

    private function extractEmails(string $text): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[0] ?? [])));
    }

    private function comments(mixed $comments): array
    {
        if (!is_array($comments)) {
            return [];
        }

        $normalized = [];
        foreach ($comments as $comment) {
            if (is_string($comment)) {
                $normalized[] = ['text' => $comment];
            } elseif (is_array($comment)) {
                $text = $comment['text'] ?? $comment['commentText'] ?? $comment['comment'] ?? null;
                if (is_string($text) && trim($text) !== '') {
                    $normalized[] = [
                        'text' => trim($text),
                        'author' => $comment['authorName'] ?? $comment['author'] ?? null,
                        'date' => $comment['date'] ?? $comment['createdAt'] ?? null,
                    ];
                }
            }
        }

        return $normalized;
    }

    private function publishedAt(array $post): ?Carbon
    {
        foreach (['datePosted', 'publishedAt', 'postedAt', 'date'] as $key) {
            if (empty($post[$key])) {
                continue;
            }

            try {
                if (is_numeric($post[$key])) {
                    $value = (int) $post[$key];

                    return $value > 1000000000000
                        ? Carbon::createFromTimestampMs($value)
                        : Carbon::createFromTimestamp($value);
                }

                return Carbon::parse($post[$key]);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function isPlausibleApplicationLink(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== ''
            && !str_contains($host, 'linkedin.com')
            && !str_contains($host, 'lnkd.in');
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return trim($url);
        }

        $host = strtolower($parts['host'] ?? '');
        $path = rtrim($parts['path'] ?? '', '/');

        return $host . $path;
    }

    private function normalize(?string $value): string
    {
        return Str::ascii(mb_strtolower($value ?? ''));
    }

    private function stringValue(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function emptyResult(string $key): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'rejected' => $key === 'rejected' ? 1 : 0,
            'duplicates' => $key === 'duplicates' ? 1 : 0,
        ];
    }
}