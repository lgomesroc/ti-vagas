<?php

namespace App\Jobs;

use App\Exceptions\SourceRateLimitedException;
use App\Services\VacancyImporter;
use App\Sources\LinkedInJobsSource;
use Illuminate\Bus\Queueable;
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
 * interrompida e o job é devolvido à fila com atraso crescente. Se os
 * reagendamentos se esgotarem, o job termina sem falha e a próxima execução
 * agendada tenta de novo.
 *
 * Atenção: com o driver "database", `retry_after` (DB_QUEUE_RETRY_AFTER) deve
 * ser maior que $timeout, senão outro worker reserva o job ainda em execução.
 */
class FetchLinkedInJobsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const SOURCE = 'linkedin-jobs';

    /**
     * Total de execuções, incluindo os reagendamentos por limitação (429).
     */
    public int $tries = 5;

    /**
     * Apenas exceções reais contam para falha definitiva; reagendamentos
     * por 429 usam release() e não contam aqui.
     */
    public int $maxExceptions = 2;

    /**
     * Acima do timeout do processo Node (linkedin.secondary_account.timeout,
     * 1800s por padrão), para o Laravel encerrar o processo antes do worker.
     */
    public int $timeout = 2100;

    /**
     * Timeout é tratado como falha comum, sujeita a nova tentativa.
     */
    public bool $failOnTimeout = false;

    public function __construct()
    {
        $this->onQueue('vacancies');
    }

    /**
     * Espera entre tentativas após erros comuns, em segundos.
     * Intervalos longos: a fonte é um navegador autenticado, não vale insistir.
     */
    public function backoff(): array
    {
        return [300, 900, 1800];
    }

    /**
     * Evita duas coletas simultâneas; a duplicada é descartada, não reagendada.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::SOURCE))
                ->expireAfter($this->timeout + 60)
                ->dontRelease(),
        ];
    }

    public function handle(
        LinkedInJobsSource $source,
        VacancyImporter $importer
    ): void {
        $startedAt = microtime(true);

        Log::info('Iniciando coleta de LinkedIn Jobs.', [
            'source' => self::SOURCE,
            'attempt' => $this->attempts(),
        ]);

        try {
            $vacancies = $source->fetch();
        } catch (SourceRateLimitedException $exception) {
            $this->handleRateLimit($exception);

            return;
        }

        $result = $importer->importFetched($source->name(), $vacancies);

        Log::info('Coleta de LinkedIn Jobs concluída.', [
            'source' => self::SOURCE,
            'duration_seconds' => round(microtime(true) - $startedAt, 2),
            'eligible' => count($vacancies),
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
        ]);
    }

    /**
     * Segundos até o reagendamento: o maior entre o Retry-After informado e um
     * atraso exponencial (base, 2x, 4x...), limitado ao teto configurado.
     */
    public function rescheduleDelay(?int $retryAfterSeconds, int $attempt): int
    {
        $base = max(1, (int) config('linkedin.rate_limit.reschedule_base_seconds', 900));
        $max = max($base, (int) config('linkedin.rate_limit.reschedule_max_seconds', 21600));

        $exponential = min($max, $base * (2 ** max(0, $attempt - 1)));

        return max($exponential, $retryAfterSeconds ?? 0);
    }

    private function handleRateLimit(SourceRateLimitedException $exception): void
    {
        $attempt = $this->attempts();

        if ($attempt >= $this->tries) {
            // Esgotou os reagendamentos: encerra sem falha e sem novo ciclo.
            Log::warning('LinkedIn Jobs seguiu limitando as requisições; coleta adiada para a próxima execução agendada.', [
                'source' => self::SOURCE,
                'attempt' => $attempt,
                'retry_after_seconds' => $exception->retryAfterSeconds,
            ]);

            return;
        }

        $delay = $this->rescheduleDelay($exception->retryAfterSeconds, $attempt);

        Log::warning('LinkedIn Jobs limitou as requisições (HTTP 429); job reagendado.', [
            'source' => self::SOURCE,
            'attempt' => $attempt,
            'retry_after_seconds' => $exception->retryAfterSeconds,
            'release_in_seconds' => $delay,
        ]);

        $this->release($delay);
    }

    public function failed(?Throwable $exception): void
    {
        Log::critical('Job de coleta de LinkedIn Jobs falhou definitivamente.', [
            'source' => self::SOURCE,
            'attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
        ]);
    }
}
