<?php

namespace App\Jobs;

use App\Services\VacancyImporter;
use App\Services\VacancyLocationFilter;
use App\Sources\LinkedInJobsSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchLinkedInJobsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;
    public bool $failOnTimeout = true;

    public function __construct()
    {
        $this->onQueue('linkedin');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('linkedin-jobs-collection'))
                ->expireAfter(1860)
                ->releaseAfter(30),
        ];
    }

    public function handle(
        VacancyImporter $importer,
        VacancyLocationFilter $locationFilter
    ): void {
        $startedAt = microtime(true);

        Log::info('Iniciando job de coleta LinkedIn Jobs.');

        $source = new LinkedInJobsSource($locationFilter);
        $vacancies = $source->fetch();
        $result = $importer->importFetched($source->name(), $vacancies);

        Log::info('Job de coleta LinkedIn Jobs concluído.', [
            'received' => count($vacancies),
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::critical('Job de coleta LinkedIn Jobs falhou.', [
            'error' => $exception?->getMessage(),
        ]);
    }
}
