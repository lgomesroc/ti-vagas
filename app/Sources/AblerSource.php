<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class AblerSource implements VacancySource
{
    private readonly VacancyLocationFilter $locationFilter;
    private array $diagnostics = [
        'terms' => 0, 'links_found' => 0, 'details_opened' => 0,
        'records_received' => 0, 'vacancies_importable' => 0,
        'ignored_by_filter' => 0, 'errors' => 0,
    ];

    public function __construct(
        private readonly ?string $caBundle = null,
        private readonly int $maxPages = 3,
        private readonly int $pageSize = 30,
        private readonly int $maxJobs = 600,
        private readonly int $initialLookbackDays = 60,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string { return 'abler-global'; }

    public function diagnostics(): array { return $this->diagnostics; }

    public function fetch(): iterable
    {
        $process = new Process(['node', 'scripts/abler/collect.mjs'], base_path());
        $process->setTimeout(1800);
        $process->run();

        if (!$process->isSuccessful()) {
            $detail = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException('Falha ao executar o coletor Playwright da Abler: ' . Str::limit($detail, 1200));
        }

        try {
            $payload = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('O coletor da Abler retornou JSON inválido.', previous: $exception);
        }

        if (!is_array($payload) || !is_array($payload['vacancies'] ?? null)) {
            throw new RuntimeException('Formato inesperado na resposta do coletor Playwright da Abler.');
        }

        $raw = is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : [];
        $this->diagnostics['terms'] = (int) ($raw['terms'] ?? 0);
        $this->diagnostics['links_found'] = (int) ($raw['links_found'] ?? 0);
        $this->diagnostics['details_opened'] = (int) ($raw['details_opened'] ?? 0);
        $this->diagnostics['errors'] = count($raw['errors'] ?? []);
        $this->diagnostics['records_received'] = count($payload['vacancies']);

        $cutoff = CarbonImmutable::now()->subDays($this->initialLookbackDays);
        $vacancies = [];
        $seen = [];

        foreach ($payload['vacancies'] as $job) {
            if (!is_array($job)) continue;
            $id = trim((string) ($job['external_id'] ?? ''));
            if ($id === '' || isset($seen[$id])) continue;
            $seen[$id] = true;

            $publishedAt = $this->normalizeDate($job['published_at'] ?? null);
            if ($publishedAt === null || CarbonImmutable::parse($publishedAt)->lt($cutoff)) continue;

            $vacancy = $this->normalizeVacancy($job, $publishedAt);
            if ($vacancy === null) {
                $this->diagnostics['ignored_by_filter']++;
                continue;
            }
            $vacancies[] = $vacancy;
            if (count($vacancies) >= $this->maxJobs) break;
        }

        $this->diagnostics['vacancies_importable'] = count($vacancies);
        Log::info('Abler: coleta via navegador concluída.', $this->diagnostics);
        return $vacancies;
    }

    private function normalizeVacancy(array $job, string $publishedAt): ?array
    {
        $title = $this->cleanText($job['title'] ?? '');
        $description = $this->cleanDescription($job['description'] ?? '');
        if ($title === '' || $description === '' || !$this->isTechnologyVacancy($title)) return null;

        $company = $this->cleanText($job['company'] ?? '') ?: 'Empresa não informada';
        $location = $this->cleanText($job['location'] ?? '');
        $workplace = Str::lower($this->cleanText($job['work_model'] ?? ''));
        $workModel = match (true) {
            str_contains($workplace, 'remot') => 'Remoto',
            str_contains($workplace, 'hibr') => 'Híbrido',
            str_contains($workplace, 'presenc'), str_contains($workplace, 'on-site') => 'Presencial',
            default => null,
        };
        $workModel ??= $this->locationFilter->classifyWorkModel($title, $location, $description);

        if (!$this->locationFilter->isEligibleForCollection($location, $title, $description, $workModel)) return null;

        $applicationUrl = trim((string) ($job['application_url'] ?? ''));
        if (!filter_var($applicationUrl, FILTER_VALIDATE_URL) || !str_contains($applicationUrl, 'abler.')) return null;

        return [
            'title' => $title, 'company' => $company, 'description' => $description,
            'location' => $location !== '' ? $location : null, 'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType($job['employment_type'] ?? null),
            'salary' => $this->cleanText($job['salary'] ?? '') ?: null,
            'application_url' => $applicationUrl, 'external_id' => $this->cleanText($job['external_id']),
            'original_published_at' => $publishedAt,
        ];
    }

    private function isTechnologyVacancy(string $title): bool
    {
        $normalized = Str::ascii(mb_strtolower($title));
        if (preg_match('/\\b(pleno|senior|sr\\.?|staff|principal|lead|lider|coordenador|gerente|director|diretor|head|estagio|estagiario|estagiaria|internship|intern)\\b/i', $normalized)) return false;
        return (bool) preg_match('/\\b(junior|jr\\.?|desenvolvedor|desenvolvedora|developer|programador|programadora|software engineer|engenheiro de software|backend|back-end|frontend|front-end|full.?stack|analista de sistemas|analista de suporte|suporte tecnico|suporte de ti|help.?desk|service desk|infraestrutura|infra|redes de computadores|analista de redes|devops|cloud|sysadmin|qa|quality assurance|analista de testes)\\b/i', $normalized);
    }

    private function normalizeEmploymentType(mixed $value): ?string
    {
        $value = Str::lower($this->cleanText($value));
        return match (true) {
            str_contains($value, 'pj'), str_contains($value, 'contract') => 'Contrato',
            str_contains($value, 'tempor') => 'Temporário',
            str_contains($value, 'part'), str_contains($value, 'meio periodo') => 'Meio período',
            str_contains($value, 'full'), str_contains($value, 'integral'), str_contains($value, 'clt') => 'Tempo integral',
            default => null,
        };
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        try { return CarbonImmutable::parse($value)->toIso8601String(); }
        catch (Throwable) { return null; }
    }

    private function cleanDescription(mixed $value): string
    {
        if (!is_string($value)) return '';
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/<\\s*br\\s*\\/?\\s*>|<\\/\\s*(p|div|h[1-6])\\s*>/i', "\\n", $value);
        $value = preg_replace('/<\\s*li\\b[^>]*>/i', "\\n- ", $value);
        $value = strip_tags($value);
        return trim(preg_replace('/\\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function cleanText(mixed $value): string
    {
        if (!is_scalar($value)) return '';
        return trim(preg_replace('/\\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
}
