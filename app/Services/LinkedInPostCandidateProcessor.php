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

        if (!$this->isRecruitmentPost($post, $text)) {
            $candidate->fill($base + [
                'status' => 'rejected',
                'decision_reason' => 'Não foi possível identificar publicação de recrutamento ou anúncio de oportunidade.',
            ])->save();

            return $this->emptyResult('rejected');
        }

        $links = $this->extractUrls($combinedText, $post);
        $emails = $this->extractEmails($combinedText);

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

        $enrichedText = trim($text . "\n" . $pageContent);
        $title = $this->extractTitle($enrichedText);
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
            'status' => $reason === null ? 'pending_review' : 'rejected',
            'decision_reason' => $reason ?? (
                $applicationUrl === null && $emails === []
                    ? 'Sem link ou e-mail; encaminhado para revisão manual com base na publicação pública.'
                    : 'Passou pelos filtros automáticos; aguarda revisão manual.'
            ),
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
            '/\b(chama|me chama|mande|envio|enviar)\b.{0,80}\b(dm|direct|privado|inbox)\b/',
            '/\b(so|apenas)\b.{0,50}\b(comentando|comentar|digitar|mandar mensagem)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
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
                && ($hasRecruitmentSignal || $isCompanyAuthor)
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
            'pleno', 'senior', 'staff', 'principal', 'lead', 'manager',
            'gerente', 'diretor', 'head', 'especialista', 'coordenador',
            'supervisor',
        ] as $term) {
            if (preg_match('/(^|[^a-z0-9])' . preg_quote($term, '/') . '([^a-z0-9]|$)/', $titleText)) {
                return 'Nível fora do escopo: somente vagas efetivas júnior.';
            }
        }

        if (preg_match('/\b(?:3|[4-9]|\d{2,})\s*(?:\+|plus)?\s*(?:[-–]\s*\d+\s*)?(?:anos?|years?)\b/', $descriptionText)) {
            return 'Descrição exige três anos ou mais de experiência.';
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

        if (!$this->locationFilter->isEligibleForCollection(
            $location,
            $title,
            $description,
            $workModel
        )) {
            return 'Localidade ou modalidade incompatível com as regras de coleta do TI Vagas.';
        }

        if ($applicationUrl === null && $emails === []) {
            // Public posts without direct contact remain reviewable, never auto-published.
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
            ->whereIn('status', ['pending_review', 'approved', 'duplicate']);

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