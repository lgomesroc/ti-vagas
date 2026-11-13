<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('vacancies:collect')
    ->everySixHours()
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

// Listas de Tor, VPN, proxies, datacenters e abuso. Roda no GitHub Actions
// (que executa schedule:run a cada seis horas) e grava no cache compartilhado.
Schedule::command('network:update-lists')
    ->everySixHours()
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

Schedule::command('vacancies:prune-expired')
    ->weeklyOn(1, '03:00')
    ->timezone('America/Sao_Paulo')
    ->when(fn (): bool => now()->isoWeek % 2 === 0)
    ->withoutOverlapping();
