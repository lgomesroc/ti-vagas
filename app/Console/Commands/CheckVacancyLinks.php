<?php

namespace App\Console\Commands;

use App\Models\Vacancy;
use App\Services\VacancyLinkChecker;
use Illuminate\Console\Command;
use Throwable;

class CheckVacancyLinks extends Command
{
    protected $signature = 'vacancies:check-links {--limit=500 : Quantidade máxima de links verificados nesta execução}';

    protected $description = 'Verifica os links de candidatura e registra o histórico de disponibilidade';

    public function handle(VacancyLinkChecker $checker): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $vacancies = Vacancy::query()
            ->whereIn('availability_status', ['active', 'suspect'])
            ->orderByRaw('availability_checked_at IS NULL DESC')
            ->orderBy('availability_checked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $totals = [
            'active' => 0,
            'suspect' => 0,
            'expired' => 0,
            'failed' => 0,
        ];

        foreach ($vacancies as $vacancy) {
            try {
                $status = $checker->check($vacancy);
                $totals[$status]++;
                $this->line("#{$vacancy->id} {$vacancy->company} — {$status}");
            } catch (Throwable $exception) {
                $totals['failed']++;
                report($exception);
                $this->error("#{$vacancy->id} {$vacancy->company} — falha inesperada: {$exception->getMessage()}");
            }
        }

        $this->newLine();
        $this->info('Verificação de links concluída.');
        $this->line('Links verificados: ' . $vacancies->count());
        $this->line('Ativas: ' . $totals['active']);
        $this->line('Suspeitas: ' . $totals['suspect']);
        $this->line('Encerradas: ' . $totals['expired']);
        $this->line('Falhas internas: ' . $totals['failed']);

        return $totals['failed'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
