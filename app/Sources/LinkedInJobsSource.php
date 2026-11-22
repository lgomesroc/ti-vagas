<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Exceptions\SourceRateLimitedException;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class LinkedInJobsSource implements VacancySource
{
    /**
     * Código de saída do script Node para limitação temporária (EX_TEMPFAIL).
     */
    public const RATE_LIMIT_EXIT_CODE = 75;

    public function __construct(
        private readonly VacancyLocationFilter $locationFilter
    ) {
    }

    public function name(): string
    {
        return 'linkedin-jobs';
    }

    public function fetch(): iterable
    {
        $configuredState = (string) config('linkedin.secondary_account.storage_state');

        $storageState = str_starts_with($configuredState, DIRECTORY_SEPARATOR)
            ? $configuredState
            : base_path($configuredState);

        if (!is_file($storageState)) {
            throw new RuntimeException(
                'Estado de sessão do LinkedIn não encontrado: ' . $storageState
            );
        }

        $result = Process::path(base_path())
            ->env([
                'LINKEDIN_SECONDARY_ACCOUNT_PROFILE' => (string) config('linkedin.secondary_account.profile'),
                'LINKEDIN_STORAGE_STATE_PATH' => (string) config('linkedin.secondary_account.storage_state'),
                'LINKEDIN_HEADLESS' => (string) env('LINKEDIN_HEADLESS', 'false'),
                'LINKEDIN_POSTS_LOOKBACK_HOURS' => (string) config('linkedin.lookback_hours', 72),
            ] + self::rateLimitEnvironment())
            ->timeout(max(600, (int) config('linkedin.secondary_account.timeout', 1800)))
            ->run(['node', 'scripts/linkedin/secondary-account.mjs', 'jobs']);

        if ($result->exitCode() === self::RATE_LIMIT_EXIT_CODE) {
            $retryAfter = self::parseRetryAfter($result->errorOutput());

            Log::warning('LinkedIn Jobs limitou as requisições (HTTP 429); coleta interrompida.', [
                'source' => $this->name(),
                'retry_after_seconds' => $retryAfter,
                'detail' => trim($result->errorOutput()),
            ]);

            throw new SourceRateLimitedException(
                'LinkedIn Jobs limitou as requisições (HTTP 429); a coleta foi interrompida e deve ser reagendada.',
                $retryAfter,
                $this->name()
            );
        }

        if (!$result->successful()) {
            throw new RuntimeException(
                'Falha na coleta de LinkedIn Jobs: ' . trim($result->errorOutput())
            );
        }

        $vacancies = json_decode($result->output(), true);

        if (!is_array($vacancies)) {
            throw new RuntimeException('LinkedIn Jobs retornou JSON inválido.');
        }

        return $this->filterFetched($vacancies);
    }

    /**
     * Variáveis de ambiente de ritmo e backoff repassadas ao script Node.
     *
     * @return array<string, string>
     */
    public static function rateLimitEnvironment(): array
    {
        $settings = (array) config('linkedin.rate_limit', []);

        $map = [
            'LINKEDIN_REQUEST_DELAY_MS' => 'request_delay_ms',
            'LINKEDIN_PANEL_DELAY_MS' => 'panel_delay_ms',
            'LINKEDIN_REQUEST_JITTER_MS' => 'jitter_ms',
            'LINKEDIN_RATE_LIMIT_MAX_RETRIES' => 'max_retries',
            'LINKEDIN_RATE_LIMIT_BASE_BACKOFF_MS' => 'base_backoff_ms',
            'LINKEDIN_RATE_LIMIT_MAX_BACKOFF_MS' => 'max_backoff_ms',
        ];

        $environment = [];

        foreach ($map as $variable => $key) {
            if (isset($settings[$key])) {
                $environment[$variable] = (string) $settings[$key];
            }
        }

        return $environment;
    }

    /**
     * Extrai o Retry-After (segundos) informado pelo script, quando houver.
     */
    public static function parseRetryAfter(string $errorOutput): ?int
    {
        if (preg_match('/LINKEDIN_RATE_LIMITED[^\n]*?retry_after=(\d+)/', $errorOutput, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    public function filterFetched(array $vacancies): array
    {
        return array_values(array_filter($vacancies, function (array $vacancy): bool {
            $title = trim((string) ($vacancy['title'] ?? ''));
            $description = trim((string) ($vacancy['description'] ?? ''));
            $location = trim((string) ($vacancy['location'] ?? ''));

            if ($title === '' || mb_strlen($description) < 100) {
                return false;
            }

            $normalizedTitle = $this->normalize($title);
            $normalizedDescription = $this->normalize($description);

            // Rejeita descrições contaminadas com textos da interface do LinkedIn.
            if (preg_match(
                '/ative um alerta para vagas semelhantes|pesquise vagas mais rapido com premium|milhoes de usuarios tem contas premium|demonstracao gratuita de 1 mes|activate an alert for similar jobs|search faster with premium/i',
                $normalizedDescription
            )) {
                return false;
            }

            // Exige senioridade júnior explicitamente no título.
            // Rejeita estágio, trainee e níveis superiores.
            if (!$this->isStrictJuniorTitle($normalizedTitle)) {
                return false;
            }

            // A vaga precisa pertencer a uma área de tecnologia.
            if (!$this->isTechnologyTitle($normalizedTitle)) {
                return false;
            }

            // Rejeita descrições que contradizem o nível júnior
            // ou exigem experiência incompatível.
            if ($this->hasIncompatibleRequirements($normalizedDescription)) {
                return false;
            }

            $workModel = $this->locationFilter->classifyWorkModel(
                $title,
                $location,
                $description
            ) ?? ($vacancy['work_model'] ?? null);

            if (!$this->locationFilter->isEligibleForCollection(
                $location,
                $title,
                $description,
                is_string($workModel) ? $workModel : null
            )) {
                return false;
            }

            $vacancy['work_model'] = $workModel;

            return true;
        }));
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return mb_strtolower($ascii === false ? $value : $ascii);
    }

    private function isStrictJuniorTitle(string $title): bool
    {
        // WordPress foi excluído do escopo da coleta.
        if (preg_match('/\bwordpress\b/', $title)) {
            return false;
        }

        // Rejeita títulos que misturam júnior com pleno ou nível intermediário.
        if (preg_match(
            '/\b(jr|junior)\s*(\/|&|-|\|)\s*(pl|pleno|mid|mid-level)\b/',
            $title
        )) {
            return false;
        }

        // Rejeita senioridades incompatíveis.
        if (preg_match(
            '/\b(pleno|mid.level|midlevel|senior|sr|staff|principal|lead|manager|gerente|diretor|head|especialista|coordenador|supervisor)\b/',
            $title
        )) {
            return false;
        }

        // Estágio, trainee e posições de aprendizagem não fazem parte do escopo.
        if (preg_match(
            '/\b(estagio|trainee|intern|internship|apprentice)\b/',
            $title
        )) {
            return false;
        }

        // Júnior precisa estar explícito no título.
        return preg_match('/\b(junior|jr)\b/', $title) === 1;
    }

    private function isTechnologyTitle(string $title): bool
    {
        $terms = [
            'desenvolvedor',
            'developer',
            'programador',
            'software engineer',
            'engenheiro de software',
            'backend',
            'back-end',
            'frontend',
            'front-end',
            'full stack',
            'fullstack',
            'php',
            '.net',
            'java',
            'python',
            'javascript',
            'typescript',
            'analista de sistemas',
            'analista de desenvolvimento',
            'suporte',
            'help desk',
            'helpdesk',
            'service desk',
            'infraestrutura',
            'infrastructure',
            'redes',
            'network',
            'devops',
            'sysadmin',
            'qa',
            'quality assurance',
            'analista de testes',
            'technical support',
            'it support',
        ];

        foreach ($terms as $term) {
            if (str_contains($title, $this->normalize($term))) {
                return true;
            }
        }

        return false;
    }

    private function hasIncompatibleRequirements(string $description): bool
    {
        return preg_match(
            '/\b(?:minimum of |at least |minimum |minimo de |pelo menos )?(?:3|4|5|6|7|8|9|10)\s*\+?\s*(?:years?|anos?)\b/',
            $description
        ) === 1
            || preg_match(
                '/\b(?:3|4|5|6|7|8|9|10)\s*(?:or more|ou mais)\s*(?:years?|anos?)\b/',
                $description
            ) === 1
            || preg_match(
                '/\b(?:senior|mid.level|midlevel|pleno)\s+(?:experience|position|role|level|experiencia|nivel|posicao)\b/',
                $description
            ) === 1;
    }
}
