<?php

namespace App\Jobs;

use App\Exceptions\SourceRateLimitedException;
use App\Services\VacancyImporter;
use App\Sources\LinkedInJobsSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Coleta o LinkedIn Jobs pela fila.
 *
 * Uma limitação temporária (HTTP 429) não é falha definitiva: a coleta é
 * interrompida e o job volta à fila com atraso crescente. Esgotados os
 * reagendamentos, o job termina sem falha e a próxima execução agendada
 * tenta de novo. Enquanto houver um job pendente (inclusive reagendado), um
 * novo despacho é descartado (ShouldBeUnique), para não repetir a coleta
 * logo após uma limitação.
 */
class FetchLinkedInJobsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Total de execuções, incluindo os reagendamentos por limitação (429).
     */
    public int $tries = 5;

    /**
     * Erros reais continuam falhando na primeira ocorrência (como antes, com
     * tries = 1). Reagendamentos por 429 usam release() e não contam aqui.
     */
    public int $maxExceptions = 1;

    public int $timeout = 1800;
    public bool $failOnTimeout = true;

    /**
     * Validade do lock de unicidade, para não travar se o worker for morto.
     */
    public int $uniqueFor = 43200;

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
        LinkedInJobsSource $source
    ): void {
        $startedAt = microtime(true);

        Log::info('Iniciando job de coleta LinkedIn Jobs.', [
            'attempt' => $this->attempts(),
        ]);

        try {
            $vacancies = $source->fetch();
        } catch (SourceRateLimitedException $exception) {
            $this->handleRateLimit($exception);

            return;
        }

        $result = $importer->importFetched($source->name(), $vacancies);

        Log::info('Job de coleta LinkedIn Jobs concluído.', [
            'received' => count($vacancies),
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
        ]);
    }

    /**
     * Segundos até o reagendamento: o maior entre o Retry-After informado e um
     * atraso exponencial (base, 2x, 4x...), limitado ao teto configurado.
     */
    public function rescheduleDelay(?int $retryAfterSeconds, int $attempt): int
    {
        $base = max(1, (int) config('linkedin.jobs.reschedule_base_seconds', 900));
        $max = max($base, (int) config('linkedin.jobs.reschedule_max_seconds', 14400));

        $exponential = min($max, $base * (2 ** max(0, $attempt - 1)));

        return max($exponential, $retryAfterSeconds ?? 0);
    }

    private function handleRateLimit(SourceRateLimitedException $exception): void
    {
        $attempt = $this->attempts();

        if ($attempt >= $this->tries) {
            // Esgotou os reagendamentos: encerra sem falha e sem novo ciclo.
            Log::warning('LinkedIn Jobs seguiu limitando as requisições; coleta adiada para a próxima execução agendada.', [
                'attempt' => $attempt,
                'retry_after_seconds' => $exception->retryAfterSeconds,
            ]);

            return;
        }

        $delay = $this->rescheduleDelay($exception->retryAfterSeconds, $attempt);

        Log::warning('LinkedIn Jobs limitou as requisições (HTTP 429); job reagendado.', [
            'attempt' => $attempt,
            'retry_after_seconds' => $exception->retryAfterSeconds,
            'release_in_seconds' => $delay,
        ]);

        $this->release($delay);
    }

    public function failed(?Throwable $exception): void
    {
        Log::critical('Job de coleta LinkedIn Jobs falhou.', [
            'error' => $exception?->getMessage(),
        ]);
    }
}
