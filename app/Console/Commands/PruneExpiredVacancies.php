<?php

namespace App\Console\Commands;

use App\Models\Vacancy;
use Illuminate\Console\Command;

class PruneExpiredVacancies extends Command
{
    protected $signature = 'vacancies:prune-expired';

    protected $description = 'Remove vagas publicadas há mais de 60 dias';

    public function handle(): int
    {
        $cutoffDate = now()->subDays(60);

        $deleted = Vacancy::query()
            ->whereNotNull('original_published_at')
            ->where('original_published_at', '<', $cutoffDate)
            ->delete();

        $this->info('Limpeza de vagas vencidas concluída.');
        $this->line('Data limite: ' . $cutoffDate->format('d/m/Y H:i:s'));
        $this->line('Vagas excluídas: ' . $deleted);

        return self::SUCCESS;
    }
}
