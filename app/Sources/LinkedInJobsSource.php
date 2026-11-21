<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class LinkedInJobsSource implements VacancySource
{
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
            throw new RuntimeException('Estado de sessão do LinkedIn não encontrado: ' . $storageState);
        }

        $result = Process::path(base_path())
            ->env([
                'LINKEDIN_SECONDARY_ACCOUNT_PROFILE' => (string) config('linkedin.secondary_account.profile'),
                'LINKEDIN_STORAGE_STATE_PATH' => (string) config('linkedin.secondary_account.storage_state'),
                'LINKEDIN_HEADLESS' => (string) env('LINKEDIN_HEADLESS', 'false'),
                'LINKEDIN_POSTS_LOOKBACK_HOURS' => (string) config('linkedin.lookback_hours', 72),
            ])
            ->timeout(max(600, (int) config('linkedin.secondary_account.timeout', 600)))
            ->run(['node', 'scripts/linkedin/secondary-account.mjs', 'jobs']);

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

    public function filterFetched(array $vacancies): array
    {
        return array_values(array_filter($vacancies, function (array $vacancy): bool {
            $title = trim((string) ($vacancy['title'] ?? ''));
            $description = trim((string) ($vacancy['description'] ?? ''));
            $location = trim((string) ($vacancy['location'] ?? ''));
            $workModel = $this->locationFilter->classifyWorkModel(
                $title,
                $location,
                $description
            ) ?? ($vacancy['work_model'] ?? null);

            if ($title === '' || $description === '') {
                return false;
            }

            $normalizedTitle = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title));
            $normalizedDescription = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $description));

            // Senioridade precisa estar explícita no título. Uma menção incidental
            // a "junior" na descrição não transforma uma vaga pleno em júnior.
            if (preg_match('/\\b(pleno|senior|sênior|staff|principal|lead|manager|gerente|diretor|head|especialista|coordenador|supervisor|trainee|estagio|estagiario|intern|internship)\\b/u', $normalizedTitle)) {
                return false;
            }

            if (preg_match('/\\b(junior|júnior|jr\\.?|entry.level)\\b/u', $normalizedTitle) !== 1) {
                return false;
            }

            if (preg_match('/ative um alerta para vagas semelhantes|pesquise vagas mais rapido com premium|milhoes de usuarios tem contas premium|demonstracao gratuita de 1 mes|activate an alert for similar jobs|search faster with premium/i', $normalizedDescription)) {
                return false;
            }

            if (mb_strlen($description) < 100) {
                return false;
            }

            $technologyTerms = [
                'desenvolvedor', 'developer', 'programador', 'software engineer',
                'engenheiro de software', 'backend', 'back-end', 'frontend',
                'front-end', 'full stack', 'fullstack', 'php', '.net', 'java',
                'python', 'javascript', 'typescript', 'analista de sistemas',
                'analista de desenvolvimento', 'suporte', 'help desk', 'helpdesk',
                'service desk', 'infraestrutura', 'infrastructure', 'redes',
                'network', 'devops', 'sysadmin', 'qa', 'quality assurance',
                'analista de testes', 'technical support', 'it support',
            ];

            $isTechnology = false;
            foreach ($technologyTerms as $term) {
                $term = mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $term));
                if (str_contains($normalizedTitle, $term)) {
                    $isTechnology = true;
                    break;
                }
            }

            if (!$isTechnology) {
                return false;
            }

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
}
