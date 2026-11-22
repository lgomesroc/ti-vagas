<?php

namespace App\\Console\\Commands;

use App\\Sources\\InhireGlobalSource;
use App\\Services\\VacancyImporter;
use App\\Services\\VacancySourceFactory;
use Illuminate\\Console\\Command;
use Illuminate\\Support\\Facades\\Cache;
use Illuminate\\Support\\Facades\\Log;
use Throwable;

class CollectInhireVacancies extends Command
{
    protected $signature = 'vacancies:collect-inhire';
    protected $description = 'Executa isoladamente a coleta global da InHire';

    public function handle(VacancySourceFactory $factory, VacancyImporter $importer): int
    {
        $lock = Cache::lock('vacancies:inhire-global:collector', 3600);

        if (!$lock->get()) {
            $this->warn('A coleta global da InHire já está em execução.');
            return self::SUCCESS;
        }

        $startedAt = microtime(true);

        try {
            $source = $factory->make('inhire-global');

            if (!$source instanceof InhireGlobalSource) {
                throw new \\RuntimeException('A fonte inhire-global possui uma implementação inesperada.');
            }

            $vacancies = $source->fetch();
            if ($vacancies instanceof \\Traversable) {
                $vacancies = iterator_to_array($vacancies, false);
            }

            $received = count($vacancies);
            $result = $importer->importFetched($source->name(), $vacancies);
            $duration = round(microtime(true) - $startedAt, 2);

            $this->table(
                ['Tenants consultados', 'Recebidas', 'Novas', 'Atualizadas', 'Ignoradas', 'Tempo (s)'],
                [[
                    $source->tenantCount(),
                    $received,
                    $result['created'],
                    $result['updated'],
                    $result['skipped'],
                    $duration,
                ]]
            );

            Log::info('InHire global: importação isolada concluída.', [
                'tenants_consultados' => $source->tenantCount(),
                'received' => $received,
                'result' => $result,
                'duration_seconds' => $duration,
            ]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('InHire global: falha na coleta/importação isolada.', [
                'error' => $exception->getMessage(),
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
