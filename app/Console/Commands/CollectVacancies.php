<?php

namespace App\Console\Commands;

use App\Models\Vacancy;
use App\Services\VacancyImporter;
use App\Services\VacancyLocationFilter;
use App\Sources\GreenhouseSource;
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
        $companies = config('vacancy_sources.greenhouse.companies', []);
        $caBundle = config('vacancy_sources.greenhouse.ca_bundle');

        $total = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
        ];

        $failed = false;

        foreach ($companies as $boardToken => $companyName) {
            $source = new GreenhouseSource(
                boardToken: $boardToken,
                companyName: $companyName,
                caBundle: $caBundle,
                locationFilter: $locationFilter,
            );

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
