<?php

use App\Http\Controllers\VacancyController;
use Illuminate\Support\Facades\Route;

Route::get('/', [VacancyController::class, 'index'])
    ->name('vacancies.index');

Route::get('/vagas/{vacancy}', [VacancyController::class, 'show'])
    ->name('vacancies.show');
