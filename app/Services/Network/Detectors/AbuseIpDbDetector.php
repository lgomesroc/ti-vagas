<?php

namespace App\Services\Network\Detectors;

use App\Contracts\NetworkDetector;
use App\Services\Network\IpFingerprint;
use App\Services\Network\NetworkCategory;
use App\Services\Network\NetworkSignal;
use App\Services\Network\SignalConfidence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Enriquecimento opcional com o AbuseIPDB (plano gratuito: 1.000 consultas
 * por dia). Só funciona com ABUSEIPDB_API_KEY.
 *
 * detect() apenas lê o cache e nunca chama a API, para não atrasar a
 * requisição. warm() consulta a API e guarda o resultado; o middleware o
 * chama depois de enviar a resposta. Assim, a primeira requisição de um IP
 * novo não usa este sinal e as seguintes usam.
 *
 * Todos os sinais têm confiança média: dados colaborativos podem acusar IPs
 * compartilhados (CGNAT, Wi-Fi público) e não devem levar a bloqueio.
 */
class AbuseIpDbDetector implements NetworkDetector
{
    private const ENDPOINT = 'https://api.abuseipdb.com/api/v2/check';

    private const DAILY_LIMITER = 'network.abuseipdb.daily';

    private const PAUSED_KEY = 'network.abuseipdb.paused';

    public function __construct(
        private readonly ?string $key,
        private readonly int $dailyLimit = 900,
        private readonly int $minScore = 75,
        private readonly int $cacheHours = 24,
    ) {}

    public function enabled(): bool
    {
        return $this->key !== null && $this->key !== '';
    }

    public function detect(string $ip): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $data = Cache::get($this->cacheKey($ip));

        return is_array($data) ? $this->signalsFrom($data) : [];
    }

    /**
     * Consulta a API e guarda o resultado (inclusive quando o IP está limpo,
     * para não gastar a cota do dia de novo).
     */
    public function warm(string $ip): void
    {
        if (
            ! $this->enabled()
            || Cache::has($this->cacheKey($ip))
            || Cache::has(self::PAUSED_KEY)
            || RateLimiter::tooManyAttempts(self::DAILY_LIMITER, $this->dailyLimit)
        ) {
            return;
        }

        RateLimiter::hit(self::DAILY_LIMITER, 86400);

        try {
            $response = Http::timeout(3)
                ->connectTimeout(2)
                ->acceptJson()
                ->withHeaders(['Key' => (string) $this->key])
                ->get(self::ENDPOINT, ['ipAddress' => $ip, 'maxAgeInDays' => 90]);

            if ($response->status() === 429) {
                // Cota esgotada no provedor: pausa até o horário informado.
                $seconds = max(60, (int) $response->header('Retry-After'));
                Cache::put(self::PAUSED_KEY, true, now()->addSeconds(min($seconds, 86400)));

                return;
            }

            $data = $response->successful() ? $response->json('data') : null;

            if (! is_array($data)) {
                return;
            }

            Cache::put($this->cacheKey($ip), [
                'score' => (int) ($data['abuseConfidenceScore'] ?? 0),
                'usage' => (string) ($data['usageType'] ?? ''),
                'tor' => ($data['isTor'] ?? false) === true,
                'whitelisted' => ($data['isWhitelisted'] ?? false) === true,
            ], now()->addHours($this->cacheHours));
        } catch (Throwable) {
            // Falha aberta: sem o sinal, vale a classificação pelas listas.
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<NetworkSignal>
     */
    private function signalsFrom(array $data): array
    {
        // IP na lista de confiança do próprio AbuseIPDB: nenhum sinal.
        if (($data['whitelisted'] ?? false) === true) {
            return [];
        }

        $signals = [];

        if (($data['tor'] ?? false) === true) {
            $signals[] = new NetworkSignal(NetworkCategory::Tor, 'abuseipdb', SignalConfidence::Medium);
        }

        if (str_starts_with(strtolower((string) ($data['usage'] ?? '')), 'data center')) {
            $signals[] = new NetworkSignal(NetworkCategory::Datacenter, 'abuseipdb', SignalConfidence::Medium);
        }

        if ((int) ($data['score'] ?? 0) >= $this->minScore) {
            $signals[] = new NetworkSignal(NetworkCategory::Abusive, 'abuseipdb', SignalConfidence::Medium);
        }

        return $signals;
    }

    private function cacheKey(string $ip): string
    {
        return 'network.abuseipdb.ip.'.IpFingerprint::of($ip);
    }
}
