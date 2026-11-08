<?php

namespace App\Jobs;

use App\Services\VacancyImporter;
use App\Services\VacancySourceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchVacancySourceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Número máximo de tentativas.
     */
    public int $tries = 3;

    /**
     * Tempo máximo de execução de uma fonte.
     */
    public int $timeout = 120;

    /**
     * Considera timeout como falha definitiva após esgotar o processamento.
     */
    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $sourceName
    ) {
        $this->onConnection('database');
        $this->onQueue('vacancies');
    }

    /**
     * Intervalos entre tentativas, em segundos.
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Impede que a mesma fonte seja processada simultaneamente
     * por mais de um job.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('vacancy-source:' . $this->sourceName))
                ->expireAfter(150)
                ->releaseAfter(30),
        ];
    }

    public function handle(
        VacancySourceFactory $factory,
        VacancyImporter $importer
    ): void {
        $startedAt = microtime(true);

        Log::info('Iniciando coleta de vagas.', [
            'source' => $this->sourceName,
            'attempt' => $this->attempts(),
        ]);

        try {
            $source = $factory->make($this->sourceName);

            $vacancies = $source->fetch();

            $result = $importer->importFetched(
                $source->name(),
                $vacancies
            );

            Log::info('Coleta de vagas concluída.', [
                'source' => $this->sourceName,
                'duration_seconds' => round(
                    microtime(true) - $startedAt,
                    2
                ),
                'result' => $result,
            ]);
        } catch (RequestException $exception) {
            $status = $exception->response->status();

            $retryableStatuses = [
                408,
                425,
                429,
            ];

            $isPermanentClientError =
                $status >= 400
                && $status < 500
                && ! in_array($status, $retryableStatuses, true);

            if ($isPermanentClientError) {
                Log::error('Falha permanente na consulta da fonte.', [
                    'source' => $this->sourceName,
                    'http_status' => $status,
                    'error' => $exception->getMessage(),
                ]);

                $this->fail($exception);

                return;
            }

            Log::warning('Falha temporária na consulta da fonte.', [
                'source' => $this->sourceName,
                'http_status' => $status,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Erro durante a coleta da fonte.', [
                'source' => $this->sourceName,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * Executado quando o job esgota as tentativas ou falha definitivamente.
     */
    public function failed(?Throwable $exception): void
    {
        Log::critical('Job de coleta de vagas falhou definitivamente.', [
            'source' => $this->sourceName,
            'attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
        ]);
    }
}
