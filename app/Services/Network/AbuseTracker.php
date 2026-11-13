<?php

namespace App\Services\Network;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Observa o comportamento de IPs que não são "rede comum".
 *
 * Só é usado para IPs em "evaluate" ou "restrict". Redes comuns nunca são
 * rastreadas nem punidas por comportamento: um IP de Wi-Fi público é
 * compartilhado por muitas pessoas, e o excesso de uma não pode afetar as
 * outras.
 *
 * As chaves usam o HMAC do IP (IpFingerprint): nenhum IP é gravado.
 */
class AbuseTracker
{
    /**
     * @param  array<string, mixed>  $behavior  configuração network_security.behavior
     */
    public function __construct(private readonly array $behavior = []) {}

    /**
     * Registra uma requisição e devolve quantas houve no último minuto.
     */
    public function recordRequest(string $ip): int
    {
        return (int) RateLimiter::hit($this->key('requests', $ip), 60);
    }

    /**
     * Registra uma resposta 429 (limite de requisições excedido).
     */
    public function recordStrike(string $ip): void
    {
        $key = $this->key('strikes', $ip);

        Cache::add($key, 0, now()->addMinutes($this->minutes('strike_window_minutes', 15)));
        Cache::increment($key);
    }

    public function strikes(string $ip): int
    {
        return (int) Cache::get($this->key('strikes', $ip), 0);
    }

    /**
     * Passa o IP de "evaluate" para "restrict" por um tempo.
     */
    public function escalate(string $ip): void
    {
        Cache::put(
            $this->key('escalated', $ip),
            true,
            now()->addMinutes($this->minutes('escalation_minutes', 15))
        );
    }

    public function isEscalated(string $ip): bool
    {
        return Cache::has($this->key('escalated', $ip))
            || $this->strikes($ip) >= max(1, (int) ($this->behavior['strikes_to_escalate'] ?? 5));
    }

    public function exceedsEvaluateLimit(int $requestsInLastMinute): bool
    {
        return $requestsInLastMinute > max(1, (int) ($this->behavior['evaluate_requests_per_minute'] ?? 60));
    }

    /**
     * Aplica o limite de um IP em "restrict".
     *
     * @return int|null segundos até poder tentar de novo, ou null se a
     *                  requisição está dentro do limite
     */
    public function restrictedRetryAfter(string $ip): ?int
    {
        $key = $this->key('restricted', $ip);
        $limit = max(1, (int) ($this->behavior['restricted_requests_per_minute'] ?? 30));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return max(1, (int) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    /**
     * Verdadeiro na primeira vez, em dez minutos, que este IP e esta ação
     * são vistos. Evita um log por requisição.
     */
    public function firstSighting(string $ip, string $action): bool
    {
        return Cache::add($this->key('seen.'.$action, $ip), true, now()->addMinutes(10));
    }

    private function key(string $kind, string $ip): string
    {
        return 'network.'.$kind.'.'.IpFingerprint::of($ip);
    }

    private function minutes(string $setting, int $default): int
    {
        return max(1, (int) ($this->behavior[$setting] ?? $default));
    }
}
