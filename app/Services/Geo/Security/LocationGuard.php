<?php

namespace App\Services\Geo\Security;

use App\Services\Geo\IpLocationResolver;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Decide se uma posição enviada pelo navegador pode alterar a região do
 * visitante e mantém, na sessão, o histórico de suspeitas.
 *
 * Não há proteção absoluta contra GPS falso: o navegador do visitante é
 * controlado por ele. Este guard reduz e registra o abuso; não o elimina.
 *
 * Os logs não guardam coordenadas, IP nem identificador de sessão em claro
 * (apenas resumos), para não ampliar o tratamento de dado pessoal.
 */
class LocationGuard
{
    public const SESSION_KEY = 'location_guard';

    private const HOUR_SECONDS = 3600;

    public function __construct(
        private readonly LocationSpoofingDetector $detector,
        private readonly IpLocationResolver $ipLocationResolver
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('location_guard.enabled', true);
    }

    /**
     * Região congelada por excesso de tentativas suspeitas.
     */
    public function isLocked(Session $session): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $lockedUntil = $this->state($session)['locked_until'] ?? null;

        return is_int($lockedUntil) && $lockedUntil > time();
    }

    /**
     * Analisa uma posição ainda não confiável.
     *
     * @param array<string, mixed>|null $municipality Município resolvido.
     */
    public function assess(
        Request $request,
        LocationSample $sample,
        ?array $municipality
    ): LocationAssessment {
        if (!$this->isEnabled()) {
            return new LocationAssessment(true, 0, []);
        }

        $session = $request->session();

        if ($this->isLocked($session)) {
            return new LocationAssessment(false, $this->rejectScore(), ['locked']);
        }

        $state = $this->state($session);

        $signals = $this->detector->detect(
            $sample,
            $this->previousFix($state),
            $municipality,
            $this->ipStateCode($request, $municipality)
        );

        $score = array_sum($signals);
        $reasons = array_keys($signals);

        if ($score > 0) {
            $this->addSuspicion($request, $reasons, $score, $municipality);
        }

        return new LocationAssessment(
            $score < $this->rejectScore(),
            $score,
            $reasons
        );
    }

    /**
     * Registra um sinal suspeito que não vem da análise de coordenadas
     * (payload malformado, parâmetro de localização forjado, etc.).
     */
    public function registerSuspicion(Request $request, string $reason, ?int $weight = null): void
    {
        if (!$this->isEnabled() || !$request->hasSession()) {
            return;
        }

        $weight ??= (int) config("location_guard.weights.{$reason}", 1);

        if ($weight <= 0) {
            return;
        }

        $this->addSuspicion($request, [$reason], $weight, null);
    }

    /**
     * Guarda a posição aceita para comparar com as próximas (velocidade).
     */
    public function recordTrustedFix(Session $session, LocationSample $sample): void
    {
        $state = $this->state($session);

        $state['last_fix'] = [
            'latitude' => $sample->latitude,
            'longitude' => $sample->longitude,
            'at_ms' => $sample->receivedAtMs,
        ];

        $session->put(self::SESSION_KEY, $state);
    }

    /**
     * Há trocas de região demais na última hora (nesta sessão ou neste IP)?
     */
    public function regionChangeLimitReached(Request $request): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $changes = $this->recentRegionChanges($this->state($request->session()));

        if (count($changes) >= (int) config('location_guard.max_region_changes_per_session_hour', 3)) {
            return true;
        }

        return RateLimiter::tooManyAttempts(
            $this->ipLimiterKey($request),
            (int) config('location_guard.max_region_changes_per_ip_hour', 20)
        );
    }

    public function recordRegionChange(Request $request): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $session = $request->session();
        $state = $this->state($session);

        $changes = $this->recentRegionChanges($state);
        $changes[] = time();
        $state['region_changes'] = $changes;

        $session->put(self::SESSION_KEY, $state);

        RateLimiter::hit($this->ipLimiterKey($request), self::HOUR_SECONDS);
    }

    /**
     * @param list<string> $reasons
     * @param array<string, mixed>|null $municipality
     */
    private function addSuspicion(
        Request $request,
        array $reasons,
        int $weight,
        ?array $municipality
    ): void {
        $session = $request->session();
        $state = $this->state($session);

        $now = time();
        $state['score'] = $this->decayedScore($state, $now) + $weight;
        $state['score_at'] = $now;

        $locked = false;

        if (
            $state['score'] >= (int) config('location_guard.lock_score', 8)
            && !$this->isLocked($session)
        ) {
            $state['locked_until'] = $now + 60 * (int) config('location_guard.lock_minutes', 30);
            $locked = true;
        }

        $session->put(self::SESSION_KEY, $state);

        $this->log($request, $reasons, $weight, $state['score'], $locked, $municipality);
    }

    /**
     * @param list<string> $reasons
     * @param array<string, mixed>|null $municipality
     */
    private function log(
        Request $request,
        array $reasons,
        int $weight,
        float $cumulative,
        bool $locked,
        ?array $municipality
    ): void {
        try {
            $channel = config('location_guard.log_channel');
            $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();

            $logger->warning('location_guard.suspicious', [
                'reasons' => $reasons,
                'score' => $weight,
                'cumulative_score' => round($cumulative, 2),
                'locked' => $locked,
                'municipality_ibge' => $municipality['ibge'] ?? null,
                'route' => $request->route()?->getName(),
                'session' => substr(hash('sha256', $request->session()->getId()), 0, 12),
                'client' => substr(hash('sha256', (string) $request->ip()), 0, 12),
            ]);
        } catch (Throwable) {
            // Falha de log nunca pode derrubar a requisição.
        }
    }

    /**
     * @param array<string, mixed> $municipality
     */
    private function ipStateCode(Request $request, ?array $municipality): ?string
    {
        if ($municipality === null || !config('location_guard.ip_crosscheck', true)) {
            return null;
        }

        try {
            return $this->ipLocationResolver->resolve($request)['state_code'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Session $session): array
    {
        $state = $session->get(self::SESSION_KEY);

        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function decayedScore(array $state, int $now): float
    {
        $score = (float) ($state['score'] ?? 0);
        $at = (int) ($state['score_at'] ?? $now);
        $halfLife = max(1, (int) config('location_guard.score_half_life_minutes', 10)) * 60;

        return $score * (0.5 ** (max(0, $now - $at) / $halfLife));
    }

    /**
     * @param array<string, mixed> $state
     * @return array{latitude: float, longitude: float, at_ms: int}|null
     */
    private function previousFix(array $state): ?array
    {
        $fix = $state['last_fix'] ?? null;

        if (
            !is_array($fix)
            || !isset($fix['latitude'], $fix['longitude'], $fix['at_ms'])
        ) {
            return null;
        }

        return [
            'latitude' => (float) $fix['latitude'],
            'longitude' => (float) $fix['longitude'],
            'at_ms' => (int) $fix['at_ms'],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return list<int>
     */
    private function recentRegionChanges(array $state): array
    {
        $limit = time() - self::HOUR_SECONDS;

        return array_values(array_filter(
            (array) ($state['region_changes'] ?? []),
            static fn ($at): bool => is_int($at) && $at > $limit
        ));
    }

    private function ipLimiterKey(Request $request): string
    {
        return 'location-region-change:' . hash('sha256', (string) $request->ip());
    }

    private function rejectScore(): int
    {
        return (int) config('location_guard.reject_score', 4);
    }
}
