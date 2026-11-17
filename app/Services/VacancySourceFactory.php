<?php

namespace App\Services;

use App\Sources\AshbySource;
use App\Sources\CompleoSource;
use App\Sources\DerevoSource;
use App\Sources\GeekHunterSource;
use App\Sources\GreenhouseSource;
use App\Sources\GupySource;
use App\Sources\InhireSource;
use App\Sources\JobConvoSource;
use App\Sources\JobylonSource;
use App\Sources\LeverSource;
use App\Sources\OracleHcmSource;
use App\Sources\OzySource;
use App\Sources\OwnCareerPageSource;
use App\Sources\PandapeSource;
use App\Sources\PlooralSource;
use App\Sources\RadarTecSource;
use App\Sources\RecrutAiSource;
use App\Sources\SensediaSource;
use App\Sources\QuickinSource;
use App\Sources\SuccessFactorsSource;
use App\Sources\TalentbrandSource;
use App\Sources\BizneoSource;
use App\Sources\SolidesSource;
use App\Sources\TotvsAtsSource;
use App\Sources\WorkableSource;
use App\Sources\WorkdaySource;
use App\Sources\VagasSource;
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
                technologyOnly: (bool) ($company['technology_only'] ?? false),
            );

            $sources[$source->name()] = $source;
        }

        $oracle = config('vacancy_sources.oracle_hcm', []);

        foreach ($oracle['companies'] ?? [] as $companyKey => $company) {
            $source = new OracleHcmSource(
                siteNumber: $company['site_number'],
                siteName: $company['site_name'],
                baseUrl: $company['base_url'],
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

        foreach ($zohoRecruit['companies'] ?? [] as $subdomain => $company) {
            $source = new ZohoRecruitSource(
                subdomain: $subdomain,
                companyName: $company['name'] ?? $subdomain,
                pageName: $company['page_name'] ?? 'Careers',
                caBundle: $zohoRecruit['ca_bundle'] ?? null,
                maxJobs: (int) ($zohoRecruit['max_jobs'] ?? 200),
                remoteCountry: $company['remote_country'] ?? 'BR',
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $compleo = config('vacancy_sources.compleo', []);

        foreach ($compleo['companies'] ?? [] as $companyKey => $company) {
            $source = new CompleoSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                caBundle: $compleo['ca_bundle'] ?? null,
                maxJobs: (int) ($compleo['max_jobs'] ?? 200),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $jobconvo = config('vacancy_sources.jobconvo', []);

        foreach ($jobconvo['companies'] ?? [] as $companyKey => $company) {
            $source = new JobConvoSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                baseUrl: $company['url'],
                careerPageId: $company['career_page_id'],
                caBundle: $jobconvo['ca_bundle'] ?? null,
                maxPages: (int) ($jobconvo['max_pages'] ?? 110),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }


        $solides = config('vacancy_sources.solides', []);

        foreach ($solides['companies'] ?? [] as $companyKey => $company) {
            $source = new SolidesSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                slug: $company['slug'] ?? $companyKey,
                careerPageUrl: $company['url'],
                caBundle: $solides['ca_bundle'] ?? null,
                maxPages: (int) ($solides['max_pages'] ?? 20),
                pageSize: (int) ($solides['page_size'] ?? 25),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }


        $quickin = config('vacancy_sources.quickin', []);

        foreach ($quickin['companies'] ?? [] as $companyKey => $company) {
            $source = new QuickinSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                url: $company['url'],
                caBundle: $quickin['ca_bundle'] ?? null,
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $successFactors = config('vacancy_sources.successfactors', []);

        foreach ($successFactors['companies'] ?? [] as $companyKey => $company) {
            $source = new SuccessFactorsSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                url: $company['url'],
                caBundle: $successFactors['ca_bundle'] ?? null,
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $bizneo = config('vacancy_sources.bizneo', []);

        foreach ($bizneo['companies'] ?? [] as $companyKey => $company) {
            $source = new BizneoSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                url: $company['url'],
                caBundle: $bizneo['ca_bundle'] ?? null,
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $talentbrand = config('vacancy_sources.talentbrand', []);

        foreach ($talentbrand['companies'] ?? [] as $companyKey => $company) {
            $source = new TalentbrandSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                url: $company['url'],
                caBundle: $talentbrand['ca_bundle'] ?? null,
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $plooral = config('vacancy_sources.plooral', []);
        foreach ($plooral['companies'] ?? [] as $companyKey => $company) {
            $source = new PlooralSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                subdomain: $company['subdomain'] ?? $companyKey,
                caBundle: $plooral['ca_bundle'] ?? null,
                maxJobs: (int) ($plooral['max_jobs'] ?? 200),
                locationFilter: $this->locationFilter,
            );
            $sources[$source->name()] = $source;
        }

        $jobylon = config('vacancy_sources.jobylon', []);

        foreach ($jobylon['companies'] ?? [] as $companyKey => $company) {
            $source = new JobylonSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                companyId: (int) $company['company_id'],
                careerUrl: $company['url'],
                caBundle: $jobylon['ca_bundle'] ?? null,
                maxJobs: (int) ($jobylon['max_jobs'] ?? 200),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $ownCareerPages = config('vacancy_sources.own_career_pages', []);

        foreach ($ownCareerPages['companies'] ?? [] as $companyKey => $company) {
            $source = new OwnCareerPageSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                url: $company['url'],
                caBundle: $ownCareerPages['ca_bundle'] ?? null,
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $vagas = config('vacancy_sources.vagas', []);

        foreach ($vagas['companies'] ?? [] as $companyKey => $company) {
            $source = new VagasSource(
                companyKey: $companyKey,
                companyName: $company['name'] ?? $companyKey,
                url: $company['url'],
                caBundle: $vagas['ca_bundle'] ?? null,
                maxJobs: (int) ($vagas['max_jobs'] ?? 200),
                locationFilter: $this->locationFilter,
            );

            $sources[$source->name()] = $source;
        }

        $rjEmpregos = config('vacancy_sources.rjempregos', []);

        $rjEmpregosSource = new RjEmpregosSource(
            baseUrl: $rjEmpregos['base_url'] ?? 'https://rjempregos.net',
            caBundle: $rjEmpregos['ca_bundle'] ?? null,
            maxPages: (int) ($rjEmpregos['max_pages'] ?? 5),
            maxJobs: (int) ($rjEmpregos['max_jobs'] ?? 50),
            locationFilter: $this->locationFilter,
        );

        $sources[$rjEmpregosSource->name()] = $rjEmpregosSource;

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
