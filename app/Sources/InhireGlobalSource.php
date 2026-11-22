<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Models\LinkedInPostCandidate;
use App\Models\Vacancy;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Facades\Log;
use Throwable;

class InhireGlobalSource implements VacancySource
{
    private const DEFAULT_TENANTS = [
        'tqi', 'atlastechnol', 'clinicaexperts', 'objective', 'sympla',
        'db1', 'zappts', 'lyncas', 'bixtecnologia', 'programmers',
        'jump', 'orizon', 'dock', 'rentbrella', 'edge', 'ninecon', 'mambape',
    ];

    private const EXCLUDED_TENANTS = [
        'api', 'auth', 'files', 'www', 'app', 'vagas', 'blog', 'help',
    ];

    public function __construct(
        private readonly ?string $caBundle = null,
        private readonly int $maxJobsPerTenant = 200,
        private readonly ?VacancyLocationFilter $locationFilter = null,
        private readonly int $delayMs = 150,
    ) {
    }

    public function name(): string
    {
        return 'inhire-global';
    }

    public function fetch(): iterable
    {
        $tenants = $this->discoverTenants();
        $vacancies = [];

        Log::info('InHire: tenants descobertos para coleta global.', [
            'count' => count($tenants),
            'tenants' => $tenants,
        ]);

        foreach ($tenants as $tenant) {
            $knownIds = $this->knownJobIds($tenant);

            try {
                $source = new InhireSource(
                    tenant: $tenant,
                    companyName: $tenant,
                    caBundle: $this->caBundle,
                    maxJobs: $this->maxJobsPerTenant,
                    locationFilter: $this->locationFilter,
                    knownJobIds: $knownIds,
                );

                foreach ($source->fetch() as $vacancy) {
                    $vacancies[] = $vacancy;
                }
            } catch (Throwable $exception) {
                // Um tenant inválido/indisponível não deve interromper os demais.
                Log::warning('InHire: falha isolada ao consultar tenant.', [
                    'tenant' => $tenant,
                    'error' => $exception->getMessage(),
                ]);
            }

            if ($this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }
        }

        return $vacancies;
    }

    private function discoverTenants(): array
    {
        $tenants = self::DEFAULT_TENANTS;

        // Mantém descoberta retrocompatível pelos nomes das fontes antigas
        // (inhire-{tenant}), mesmo quando a URL de candidatura é externa.
        $legacySources = Vacancy::query()
            ->where('source', 'like', 'inhire-%')
            ->pluck('source');

        foreach ($legacySources as $sourceName) {
            $tenants[] = substr((string) $sourceName, strlen('inhire-'));
        }

        // Reaproveita tenants já conhecidos pelos links das vagas importadas.
        $vacancyUrls = Vacancy::query()
            ->where('application_url', 'like', '%.inhire.app%')
            ->pluck('application_url');

        foreach ($vacancyUrls as $url) {
            $tenants = array_merge($tenants, $this->extractTenants((string) $url));
        }

        // Publicações do LinkedIn podem revelar empresas InHire ainda não
        // cadastradas no portal, inclusive links encontrados nos comentários.
        $candidates = LinkedInPostCandidate::query()
            ->where(function ($query) {
                $query->where('post_text', 'like', '%.inhire.app%')
                    ->orWhere('post_url', 'like', '%.inhire.app%')
                    ->orWhereRaw('CAST(embedded_links AS TEXT) LIKE ?', ['%inhire.app%'])
                    ->orWhereRaw('CAST(comments AS TEXT) LIKE ?', ['%inhire.app%']);
            })
            ->get(['post_text', 'post_url', 'embedded_links', 'comments']);

        foreach ($candidates as $candidate) {
            $texts = [
                (string) $candidate->post_text,
                (string) $candidate->post_url,
                json_encode($candidate->embedded_links, JSON_UNESCAPED_SLASHES) ?: '',
                json_encode($candidate->comments, JSON_UNESCAPED_SLASHES) ?: '',
            ];

            foreach ($texts as $text) {
                $tenants = array_merge($tenants, $this->extractTenants($text));
            }
        }

        $tenants = array_map(
            static fn ($tenant) => strtolower(trim((string) $tenant)),
            $tenants
        );

        return array_values(array_unique(array_filter(
            $tenants,
            static fn ($tenant) => preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $tenant)
                && !in_array($tenant, self::EXCLUDED_TENANTS, true)
        )));
    }

    private function extractTenants(string $text): array
    {
        preg_match_all(
            '/(?:https?:\/\/)?(?:www\.)?([a-z0-9][a-z0-9-]{1,62})\.inhire\.app\b/i',
            $text,
            $matches
        );

        return array_map('strtolower', $matches[1] ?? []);
    }

    private function knownJobIds(string $tenant): array
    {
        $legacy = Vacancy::query()
            ->where('source', 'inhire-' . $tenant)
            ->pluck('external_id')
            ->map(static fn ($id) => (string) $id)
            ->all();

        $global = Vacancy::query()
            ->where('source', $this->name())
            ->where('external_id', 'like', $tenant . ':%')
            ->pluck('external_id')
            ->map(static fn ($id) => substr((string) $id, strlen($tenant) + 1))
            ->all();

        return array_values(array_unique(array_merge($legacy, $global)));
    }
}
