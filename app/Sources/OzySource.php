<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OzySource implements VacancySource
{
    private const BASE_URL = 'https://ozycompany.com.br';

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 200,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter
            ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'ozy-company';
    }

    public function fetch(): iterable
    {
        $response = $this->request('/api/vagas');

        $payload = $response->json();
        $postings = $payload['vagas'] ?? [];

        if (!is_array($postings)) {
            throw new RuntimeException(
                'Resposta inválida recebida da API da Ozy Company.'
            );
        }

        $vacancies = [];

        foreach (array_slice($postings, 0, $this->maxJobs) as $posting) {
            if (!is_array($posting)) {
                continue;
            }

            $slug = trim($posting['slug'] ?? '');

            if (
                $slug === '' ||
                !preg_match('/^[a-z0-9-]{1,80}$/', $slug)
            ) {
                continue;
            }

            $level = trim($posting['nivel'] ?? '');

            // A API informa explicitamente o nível da vaga.
            // Não coletamos Pleno, Sênior ou outros níveis superiores.
            if ($level !== '' && !$this->isJuniorOrIntern($level)) {
                continue;
            }

            $detailResponse = $this->request(
                '/api/vagas/' . rawurlencode($slug)
            );

            $detail = $detailResponse->json('vaga');

            if (!is_array($detail)) {
                continue;
            }

            $vacancy = $this->normalizePosting(
                $posting,
                $detail
            );

            if ($vacancy !== null) {
                $vacancies[] = $vacancy;
            }
        }

        return $vacancies;
    }

    private function request(string $path)
    {
        $response = Http::acceptJson()
            ->timeout(30)
            ->withHeaders([
                'User-Agent' => 'TI-Vagas/1.0',
            ])
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ])
            ->get(self::BASE_URL . $path);

        $response->throw();

        return $response;
    }

    private function normalizePosting(
        array $posting,
        array $detail
    ): ?array {
        $slug = trim($posting['slug'] ?? '');
        $title = trim($detail['titulo'] ?? $posting['titulo'] ?? '');

        if ($slug === '' || $title === '') {
            return null;
        }

        $level = trim(
            $detail['nivel'] ?? $posting['nivel'] ?? ''
        );

        if ($level !== '' && !$this->isJuniorOrIntern($level)) {
            return null;
        }

        $description = $this->extractDescription($detail);

        $publishedAt = $this->normalizePublicationDate(
            $detail['publicadaEm']
                ?? $posting['publicadaEm']
                ?? null
        );

        if ($publishedAt === null || $description === '') {
            return null;
        }

        $workModel = $this->normalizeWorkModel(
            $detail['modalidade'] ?? $posting['modalidade'] ?? ''
        );

        $location = trim(
            $detail['local'] ?? $posting['local'] ?? ''
        );

        // A própria página da Ozy declara o Brasil como
        // localização permitida para suas vagas remotas.
        if ($workModel === 'Remoto') {
            $location = 'Brasil (remoto)';
        }

        if (!$this->locationFilter->isEligible(
            $location,
            $title,
            $description,
            $workModel
        )) {
            return null;
        }

        return [
            'title' => $title,
            'company' => 'Ozy Company',
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType(
                $detail['contrato'] ?? $posting['contrato'] ?? ''
            ),
            'salary' => $detail['salario'] ?? $posting['salario'] ?? null,
            'application_url' => self::BASE_URL .
                '/vagas/' . rawurlencode($slug) . '#candidatura',
            'external_id' => $slug,
            'original_published_at' => $publishedAt,
        ];
    }

    private function isJuniorOrIntern(string $level): bool
    {
        $normalized = Str::ascii(mb_strtolower(trim($level)));

        return preg_match(
            '/\b(junior|jr|estagio|estagiario|intern|internship)\b/',
            $normalized
        ) === 1;
    }

    private function normalizeWorkModel(string $model): ?string
    {
        $normalized = Str::ascii(mb_strtolower(trim($model)));

        return match (true) {
            str_contains($normalized, 'remot') => 'Remoto',
            str_contains($normalized, 'hibrid') => 'Híbrido',
            str_contains($normalized, 'presencial'),
            str_contains($normalized, 'on-site') => 'Presencial',
            default => null,
        };
    }

    private function normalizeEmploymentType(
        string $type
    ): ?string {
        $normalized = Str::ascii(mb_strtolower(trim($type)));

        return match ($normalized) {
            'clt', 'efetivo' => 'CLT',
            'pj', 'pessoa juridica' => 'PJ',
            'estagio', 'internship' => 'Estágio',
            'temporario' => 'Temporário',
            'freelancer', 'freela' => 'Freelancer',
            default => trim($type) !== '' ? trim($type) : null,
        };
    }

    private function extractDescription(array $detail): string
    {
        $fields = [
            'resumo' => 'Resumo',
            'descricao' => 'Sobre a vaga',
            'responsabilidades' => 'Responsabilidades',
            'requisitos' => 'Requisitos',
            'diferenciais' => 'Diferenciais',
            'beneficios' => 'Benefícios',
        ];

        $parts = [];

        foreach ($fields as $field => $label) {
            $content = $detail[$field] ?? '';

            if (!is_string($content) || trim($content) === '') {
                continue;
            }

            $parts[] = $label . ":\n" . $this->cleanDescription($content);
        }

        return trim(implode("\n\n", $parts));
    }

    private function cleanDescription(string $content): string
    {
        $content = html_entity_decode(
            $content,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $content = preg_replace(
            '/<\s*br\s*\/?>|<\/\s*p\s*>|<\/\s*div\s*>|<\/\s*h[1-6]\s*>/i',
            "\n",
            $content
        );

        $content = preg_replace(
            '/<\s*li\b[^>]*>/i',
            "\n- ",
            $content
        );

        $content = strip_tags($content);

        $content = html_entity_decode(
            $content,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/ *\n */', "\n", $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return trim($content);
    }

    private function normalizePublicationDate(
        mixed $date
    ): ?string {
        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)
                ->utc()
                ->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
