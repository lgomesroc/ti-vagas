<?php

namespace App\Providers;

use App\Services\Geo\MunicipalityDirectory;
use App\Services\Network\AbuseTracker;
use App\Services\Network\Detectors\AbuseIpDbDetector;
use App\Services\Network\Detectors\ListDetector;
use App\Services\Network\IpListStore;
use App\Services\Network\IpRangeSet;
use App\Services\Network\NetworkClassifier;
use App\Services\Network\NetworkPolicy;
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

        $this->registerNetworkSecurity();
    }

    /**
     * Detecção de VPN, proxy, Tor, datacenter e redes abusivas.
     */
    private function registerNetworkSecurity(): void
    {
        $this->app->singleton(IpListStore::class, fn ($app): IpListStore => new IpListStore(
            $app->make('cache.store'),
            (string) config('network_security.storage_path'),
            (int) config('network_security.local_ttl_seconds', 900),
        ));

        $this->app->singleton(NetworkPolicy::class, fn (): NetworkPolicy => new NetworkPolicy(
            (array) config('network_security.actions', [])
        ));

        $this->app->singleton(AbuseTracker::class, fn (): AbuseTracker => new AbuseTracker(
            (array) config('network_security.behavior', [])
        ));

        $this->app->singleton(AbuseIpDbDetector::class, fn (): AbuseIpDbDetector => new AbuseIpDbDetector(
            config('network_security.abuseipdb.key'),
            (int) config('network_security.abuseipdb.daily_limit', 900),
            (int) config('network_security.abuseipdb.min_score', 75),
            (int) config('network_security.abuseipdb.cache_hours', 24),
        ));

        $this->app->singleton(NetworkClassifier::class, fn ($app): NetworkClassifier => new NetworkClassifier(
            [
                new ListDetector(
                    $app->make(IpListStore::class),
                    (array) config('network_security.sources', [])
                ),
                $app->make(AbuseIpDbDetector::class),
            ],
            $app->make(NetworkPolicy::class),
            IpRangeSet::fromEntries((array) config('network_security.allowlist', [])),
        ));
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
