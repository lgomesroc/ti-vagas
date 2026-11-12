
<?php

namespace App\Services;

use App\Sources\AshbySource;
use App\Sources\DerevoSource;
use App\Sources\GeekHunterSource;
use App\Sources\GreenhouseSource;
use App\Sources\GupySource;
use App\Sources\InhireSource;
use App\Sources\LeverSource;
use App\Sources\OracleHcmSource;
use App\Sources\OzySource;
use App\Sources\PandapeSource;
use App\Sources\RadarTecSource;
use App\Sources\RecrutAiSource;
use App\Sources\SensediaSource;
use App\Sources\TotvsAtsSource;
use App\Sources\WorkableSource;
use App\Sources\WorkdaySource;
use App\Sources\ZohoRecruitSource;
use InvalidArgumentException;

class VacancySourceFactory
{
    public function __construct(
        private readonly VacancyLocationFilter $locationFilter
    ) {
    }

    public function all(): array
    {
        $sources = [];

        $greenhouse = config('vacancy_sources.greenhouse', []);

        foreach ($greenhouse['companies'] ?? [] as $board => $company) {
            $source = new GreenhouseSource(
                boardToken: $board,
                companyName: $company,
                caBundle: $greenhouse['ca_bundle'] ?? null,
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $sensedia = new SensediaSource(
            caBundle: config('vacancy_sources.sensedia.ca_bundle'),
            locationFilter: $this->locationFilter,
        );

        $sources[$sensedia->name()] = $sensedia;

        $workday = config('vacancy_sources.workday', []);

        foreach ($workday['companies'] ?? [] as $tenantKey => $company) {
            $source = new WorkdaySource(
                host: $company['host'],
                tenant: $company['tenant'] ?? $tenantKey,
                site: $company['site'],
                companyName: $company['name'] ?? $tenantKey,
                locale: $company['locale'] ?? 'en-US',
                caBundle: $workday['ca_bundle'] ?? null,
                maxJobs: (int) ($workday['max_jobs'] ?? 200),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $lever = config('vacancy_sources.lever', []);

        foreach ($lever['companies'] ?? [] as $site => $company) {
            $source = new LeverSource(
                site: $site,
                companyName: $company['name'] ?? $site,
                caBundle: $lever['ca_bundle'] ?? null,
                region: $company['region'] ?? 'global',
                maxJobs: (int) ($lever['max_jobs'] ?? 200),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $ozy = config('vacancy_sources.ozy', []);

        $source = new OzySource(
            caBundle: $ozy['ca_bundle'] ?? null,
            maxJobs: (int) ($ozy['max_jobs'] ?? 200),
            locationFilter: $this->locationFilter,
        );

        $sources[$source->name()] = $source;

        $workable = config('vacancy_sources.workable', []);

        foreach ($workable['companies'] ?? [] as $account => $company) {
            $source = new WorkableSource(
                account: $account,
                companyName: $company['name'] ?? $account,
                caBundle: $workable['ca_bundle'] ?? null,
                maxJobs: (int) ($workable['max_jobs'] ?? 200),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $inhire = config('vacancy_sources.inhire', []);

        foreach ($inhire['companies'] ?? [] as $tenant => $company) {
            $source = new InhireSource(
                tenant: $tenant,
                companyName: $company['name'] ?? $tenant,
                caBundle: $inhire['ca_bundle'] ?? null,
                maxJobs: (int) ($inhire['max_jobs'] ?? 200),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $gupy = config('vacancy_sources.gupy', []);

        foreach ($gupy['companies'] ?? [] as $subdomain => $company) {
            $source = new GupySource(
                subdomain: $subdomain,
                companyName: $company['name'] ?? $subdomain,
                caBundle: $gupy['ca_bundle'] ?? null,
                maxJobs: (int) ($gupy['max_jobs'] ?? 200),
                baseUrl: $company['url'] ?? null,
            );

            $sources[$source->name()] = $source;
        }

        $oracle = config('vacancy_sources.oracle_hcm', []);

        foreach ($oracle['companies'] ?? [] as $companyKey => $company) {
            $source = new OracleHcmSource(
                siteNumber: $company['site_number'],
                siteName: $company['site_name'],
                companyName: $company['name'] ?? $companyKey,
                caBundle: $oracle['ca_bundle'] ?? null,
                maxJobs: (int) ($oracle['max_jobs'] ?? 1000),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $radartec = config('vacancy_sources.radartec', []);

        $source = new RadarTecSource(
            caBundle: $radartec['ca_bundle'] ?? null,
            maxJobs: (int) ($radartec['max_jobs'] ?? 200),
            locationFilter: $this->locationFilter,
        );

        $sources[$source->name()] = $source;

        $derevo = config('vacancy_sources.derevo', []);

        $source = new DerevoSource(
            caBundle: $derevo['ca_bundle']
                ?? $greenhouse['ca_bundle']
                ?? null,
            locationFilter: $this->locationFilter,
        );

        $sources[$source->name()] = $source;

        $ashby = config('vacancy_sources.ashby', []);

        foreach ($ashby['companies'] ?? [] as $board => $company) {
            $source = new AshbySource(
                board: $board,
                companyName: $company['name'] ?? $board,
                caBundle: $ashby['ca_bundle'] ?? null,
                maxJobs: (int) ($ashby['max_jobs'] ?? 200),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $geekhunter = config('vacancy_sources.geekhunter', []);

        $source = new GeekHunterSource(
            caBundle: $geekhunter['ca_bundle'] ?? null,
            maxJobs: (int) ($geekhunter['max_jobs'] ?? 50),
        );

        $sources[$source->name()] = $source;

        $recrutai = config('vacancy_sources.recrutai', []);

        foreach ($recrutai['companies'] ?? [] as $tenant => $company) {
            $source = new RecrutAiSource(
                tenant: $tenant,
                companyName: $company['name'] ?? $tenant,
                domain: $company['domain'],
                maxJobs: (int) ($recrutai['max_jobs'] ?? 200),
                caBundle: $recrutai['ca_bundle'] ?? null,
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $pandape = config('vacancy_sources.pandape', []);

        foreach ($pandape['companies'] ?? [] as $companyKey => $company) {
            $source = new PandapeSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                baseUrl: $company['url'],
                caBundle: $pandape['ca_bundle'] ?? null,
                maxPages: (int) ($pandape['max_pages'] ?? 100),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $totvsAts = config('vacancy_sources.totvs_ats', []);

        foreach ($totvsAts['companies'] ?? [] as $companyKey => $company) {
            $source = new TotvsAtsSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                url: $company['url'],
                caBundle: $totvsAts['ca_bundle'] ?? null,
                maxJobs: (int) ($totvsAts['max_jobs'] ?? 500),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $zohoRecruit = config('vacancy_sources.zoho_recruit', []);

        foreach ($zohoRecruit['companies'] ?? [] as $companyKey => $company) {
            $source = new ZohoRecruitSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                pageName: $company['page_name'] ?? 'Careers',
                caBundle: $zohoRecruit['ca_bundle'] ?? null,
                maxJobs: (int) ($zohoRecruit['max_jobs'] ?? 200),
                remoteCountry: $company['remote_country'] ?? 'BR',
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        return $sources;
    }

    public function make(string $sourceName)
    {
        $source = $this->all()[$sourceName] ?? null;

        if ($source === null) {
            throw new InvalidArgumentException(
                "Fonte de vagas não encontrada: {$sourceName}"
            );
        }

        return $source;
    }
}
