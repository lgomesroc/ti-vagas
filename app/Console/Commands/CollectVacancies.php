<?php

namespace App\Console\Commands;

use App\Jobs\FetchVacancySourceJob;
use App\Services\VacancySourceFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class CollectVacancies extends Command
{
    protected $signature = 'vacancies:collect';

    protected $description = 'Despacha a coleta das fontes configuradas para a fila Redis';

    public function handle(VacancySourceFactory $factory): int
    {
        $sources = $factory->all();

        if (empty($sources)) {
            $this->warn('Nenhuma fonte configurada.');

            return self::SUCCESS;
        }

        $this->info('Fontes configuradas: ' . count($sources));
        $this->info('Fila: vacancies');
        $this->info('Conexão: redis');

        $dispatched = 0;

        foreach (array_keys($sources) as $sourceName) {
            FetchVacancySourceJob::dispatch($sourceName);

            $dispatched++;

            $this->line("Job enviado: {$sourceName}");
        }

        $queueSize = Queue::connection('redis')->size('vacancies');

        $this->newLine();
        $this->info('DESPACHO CONCLUÍDO');
        $this->line("Jobs enviados: {$dispatched}");
        $this->line("Jobs aguardando na fila: {$queueSize}");

        return self::SUCCESS;
    }
}
