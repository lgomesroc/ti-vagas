<?php

namespace App\Console\Commands;

use App\Models\Vacancy;
use App\Services\VacancyImporter;
use App\Services\VacancyLocationFilter;
use App\Sources\GreenhouseSource;
use App\Sources\LeverSource;
use App\Sources\OzySource;
use App\Sources\SensediaSource;
use App\Sources\WorkableSource;
use App\Sources\WorkdaySource;
use Illuminate\Console\Command;
use Throwable;

class CollectVacancies extends Command
{
    protected $signature = 'vacancies:collect';

    protected $description = 'Coleta vagas nas fontes configuradas';

    public function handle(
        VacancyImporter $importer,
        VacancyLocationFilter $locationFilter
    ): int {
        $total = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        $failed = false;

        $sources = [];

        $greenhouseConfig = config('vacancy_sources.greenhouse', []);

        foreach (
            $greenhouseConfig['companies'] ?? []
            as $boardToken => $companyName
        ) {
            $sources[] = new GreenhouseSource(
                boardToken: $boardToken,
                companyName: $companyName,
                caBundle: $greenhouseConfig['ca_bundle'] ?? null,
                locationFilter: $locationFilter,
            );
        }

        $sources[] = new SensediaSource(
            caBundle: config('vacancy_sources.sensedia.ca_bundle'),
            locationFilter: $locationFilter,
        );

        $workdayConfig = config('vacancy_sources.workday', []);

        foreach (
            $workdayConfig['companies'] ?? []
            as $tenantKey => $company
        ) {
            $sources[] = new WorkdaySource(
                host: $company['host'],
                tenant: $company['tenant'] ?? $tenantKey,
                site: $company['site'],
                companyName: $company['name'] ?? $tenantKey,
                locale: $company['locale'] ?? 'en-US',
                caBundle: $workdayConfig['ca_bundle'] ?? null,
                maxJobs: (int) (
                    $workdayConfig['max_jobs'] ?? 200
                ),
                locationFilter: $locationFilter,
            );
        }

        $leverConfig = config('vacancy_sources.lever', []);

        foreach (
            $leverConfig['companies'] ?? []
            as $site => $company
        ) {
            $sources[] = new LeverSource(
                site: $site,
                companyName: $company['name'] ?? $site,
                caBundle: $leverConfig['ca_bundle'] ?? null,
                region: $company['region'] ?? 'global',
                maxJobs: (int) (
                    $leverConfig['max_jobs'] ?? 200
                ),
                locationFilter: $locationFilter,
            );
        }

        $ozyConfig = config('vacancy_sources.ozy', []);

        $sources[] = new OzySource(
            caBundle: $ozyConfig['ca_bundle'] ?? null,
            maxJobs: (int) (
                $ozyConfig['max_jobs'] ?? 200
            ),
            locationFilter: $locationFilter,
        );

        $workableConfig = config('vacancy_sources.workable', []);

        foreach (
            $workableConfig['companies'] ?? []
            as $account => $company
        ) {
            $sources[] = new WorkableSource(
                account: $account,
                companyName: $company['name'] ?? $account,
                caBundle: $workableConfig['ca_bundle'] ?? null,
                maxJobs: (int) (
                    $workableConfig['max_jobs'] ?? 200
                ),
                locationFilter: $locationFilter,
            );
        }

        foreach ($sources as $source) {
            $this->newLine();
            $this->info('Consultando fonte: ' . $source->name());

            try {
                $result = $importer->import($source);

                foreach ($total as $key => $value) {
                    $total[$key] += $result[$key] ?? 0;
                }

                $this->info('Criadas: ' . $result['created']);
                $this->info('Atualizadas: ' . $result['updated']);
                $this->info('Ignoradas: ' . $result['skipped']);
            } catch (Throwable $exception) {
                $failed = true;

                report($exception);

                $this->error(
                    'Falha na fonte ' .
                    $source->name() .
                    ': ' .
                    $exception->getMessage()
                );
            }
        }

        $this->newLine();
        $this->info('RESUMO DA COLETA');

        $this->line('Criadas: ' . $total['created']);
        $this->line('Atualizadas: ' . $total['updated']);
        $this->line('Ignoradas: ' . $total['skipped']);

        $this->line('Total no PostgreSQL: ' . Vacancy::count());

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
