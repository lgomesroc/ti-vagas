<?php

namespace App\Providers;

use App\Services\Geo\MunicipalityDirectory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MunicipalityDirectory::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Aceite dos termos e confirmação de cookies: uso humano, poucas vezes.
        RateLimiter::for('welcome', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('welcome:' . $request->ip()));
    }
}
