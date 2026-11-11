<?php

use App\Http\Controllers\LocationRefreshController;
use App\Http\Controllers\VacancyController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/boas-vindas', [WelcomeController::class, 'show'])
    ->name('welcome');

Route::post('/boas-vindas/aceitar', [WelcomeController::class, 'accept'])
    ->middleware('throttle:welcome')
    ->name('welcome.accept');

Route::post('/cookies/aceitar', [WelcomeController::class, 'acknowledgeCookies'])
    ->middleware('throttle:welcome')
    ->name('cookies.acknowledge');

Route::middleware('welcome.accepted')->group(function (): void {
    Route::get('/', [VacancyController::class, 'index'])
        ->name('vacancies.index');

    Route::get('/vagas/{vacancy}', [VacancyController::class, 'show'])
        ->name('vacancies.show');

    Route::post('/localizacao/atualizar', [LocationRefreshController::class, 'refresh'])
        ->middleware('throttle:6,1')
        ->name('location.refresh');
});
