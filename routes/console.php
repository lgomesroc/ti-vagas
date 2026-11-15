<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('vacancies:collect')
    ->twiceDaily(6, 18)
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

/*
 * Publicações públicas do LinkedIn: execução local independente das fontes
 * tradicionais de vagas. O scheduler local deve rodar a cada minuto.
 */
Schedule::command('linkedin:collect-posts')
    ->dailyAt('06:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

Schedule::command('linkedin:collect-posts')
    ->dailyAt('10:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

Schedule::command('linkedin:collect-posts')
    ->dailyAt('14:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

Schedule::command('linkedin:collect-posts')
    ->dailyAt('18:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

// Listas de Tor, VPN, proxies, datacenters e abuso. Roda no GitHub Actions
// (que executa schedule:run a cada seis horas) e grava no cache compartilhado.
Schedule::command('network:update-lists')
    ->everySixHours()
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

Schedule::command('vacancies:check-links --limit=500')
    ->everySixHours()
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

Schedule::command('vacancies:prune-expired')
    ->weeklyOn(1, '03:00')
    ->timezone('America/Sao_Paulo')
    ->when(fn (): bool => now()->isoWeek % 2 === 0)
    ->withoutOverlapping();
