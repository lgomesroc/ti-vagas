<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class VacancyQueueSize extends Command
{
    protected $signature = 'vacancies:queue-size';

    protected $description = 'Exibe a quantidade de jobs pendentes na fila de vagas';

    public function handle(): int
    {
        $this->line((string) Queue::connection('database')->size('vacancies'));

        return self::SUCCESS;
    }
}
