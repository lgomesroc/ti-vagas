<?php

namespace App\Console\Commands;

use App\Sources\GupyGlobalSource;
use App\Services\VacancyImporter;
use App\Services\VacancySourceFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectGupyVacancies extends Command
{
    protected $signature = 'vacancies:collect-gupy';
    protected $description = 'Coleta vagas pela API global pública da Gupy';

    public function handle(VacancySourceFactory $factory, VacancyImporter $importer): int
    {
        $lock = Cache::lock('vacancies:gupy-global:collector', 3600);
        if (!$lock->get()) {
            $this->warn('A coleta global da Gupy já está em execução.');
            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        try {
            $source = $factory->make('gupy-global');
            $vacancies = $source->fetch();
            if ($vacancies instanceof \Traversable) {
                $vacancies = iterator_to_array($vacancies, false);
            }

            $received = count($vacancies);
            $result = $importer->importFetched($source->name(), $vacancies);

            // Só avança a janela depois que a importação terminou sem exceções.
            Cache::put(
                GupyGlobalSource::LAST_SUCCESSFUL_AT_KEY,
                now()->toIso8601String(),
                now()->addDays(90)
            );

            $diagnostics = $source->diagnostics();
            $this->table(
                ['Recebidas', 'Novas', 'Atualizadas', 'Ignoradas', 'Tempo (s)'],
                [[
                    $received, $result['created'], $result['updated'], $result['skipped'],
                    round(microtime(true) - $startedAt, 2),
                ]]
            );
            $this->table(
                ['Métrica', 'Quantidade'],
                array_map(
                    static fn (string $key, mixed $value): array => [$key, $value],
                    array_keys($diagnostics),
                    array_values($diagnostics)
                )
            );

            Log::info('Gupy global: importação concluída.', [
                'result' => $result,
                'diagnostics' => $diagnostics,
                'duration_seconds' => round(microtime(true) - $startedAt, 2),
            ]);
            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Gupy global: falha na coleta/importação.', ['error' => $exception->getMessage()]);
            $this->error($exception->getMessage());
            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
