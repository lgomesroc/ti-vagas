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
    private int $lastTenantCount = 0;

    public function tenantCount(): int
    {
        return $this->lastTenantCount;
    }

    private const DEFAULT_TENANTS = [
        'tqi', 'atlastechnol', 'clinicaexperts', 'objective', 'sympla',
        'db1', 'zappts', 'lyncas', 'bixtecnologia', 'programmers',
        'jump', 'orizon', 'dock', 'rentbrella', 'edge', 'ninecon', 'mambape',
        // Empresas InHire identificadas e selecionadas para o TI Vagas.
        'brivia', 'idwall', 'rposolutions', 'aarin', 'uhuu', 'amaro', 'bionexo', 'cerc', 'enjoei',
        'mjv', 'instacarro', 'bancopine', 'riachuelo', 'fiap', 'alura',
        'pm3', 'avenue', 'dtidigital', 'trinca', 'brq', 'mottu',
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
        $this->lastTenantCount = count($tenants);
        $knownIdsByTenant = $this->knownJobIdsByTenant($tenants);
        $vacancies = [];

        Log::info('InHire: tenants descobertos para coleta global.', [
            'count' => count($tenants),
            'tenants' => $tenants,
        ]);

        foreach ($tenants as $tenant) {
            try {
                $source = new InhireSource(
                    tenant: $tenant,
                    companyName: $tenant,
                    caBundle: $this->caBundle,
                    maxJobs: $this->maxJobsPerTenant,
                    locationFilter: $this->locationFilter,
                    knownJobIds: $knownIdsByTenant[$tenant] ?? [],
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

    private function knownJobIdsByTenant(array $tenants): array
    {
        $known = array_fill_keys($tenants, []);

        // Duas leituras compartilhadas substituem duas consultas por tenant.
        $legacyRows = Vacancy::query()
            ->whereIn('source', array_map(
                static fn ($tenant) => 'inhire-' . $tenant,
                $tenants
            ))
            ->get(['source', 'external_id']);

        foreach ($legacyRows as $row) {
            $tenant = substr((string) $row->source, strlen('inhire-'));
            if (isset($known[$tenant])) {
                $known[$tenant][] = (string) $row->external_id;
            }
        }

        $globalIds = Vacancy::query()
            ->where('source', $this->name())
            ->pluck('external_id');

        foreach ($globalIds as $externalId) {
            $externalId = (string) $externalId;
            $separator = strpos($externalId, ':');
            if ($separator === false) {
                continue;
            }

            $tenant = substr($externalId, 0, $separator);
            $jobId = substr($externalId, $separator + 1);

            if (isset($known[$tenant])) {
                $known[$tenant][] = $jobId;
            }
        }

        foreach ($known as $tenant => $ids) {
            $known[$tenant] = array_values(array_unique($ids));
        }

        return $known;
    }
}
