<?php

namespace App\Services\Network;

use App\Contracts\NetworkDetector;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Classifica o IP de um acesso.
 *
 * Só IPs públicos, fora da allowlist e apontados por algum detector deixam
 * de ser "rede comum". Qualquer falha de detector é ignorada: na dúvida, o
 * acesso é tratado como comum. Um IP público não é um IP suspeito.
 */
class NetworkClassifier
{
    /**
     * @param  list<NetworkDetector>  $detectors
     */
    public function __construct(
        private readonly array $detectors,
        private readonly NetworkPolicy $policy,
        private readonly IpRangeSet $allowlist,
    ) {}

    public function assess(?string $ip): NetworkAssessment
    {
        if ($ip === null || ! $this->isPublic($ip) || $this->allowlist->contains($ip)) {
            return NetworkAssessment::ordinary();
        }

        $signals = [];

        foreach ($this->detectors as $detector) {
            try {
                array_push($signals, ...$detector->detect($ip));
            } catch (Throwable $exception) {
                Log::warning('network.detector_failed', [
                    'detector' => $detector::class,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $signals === []
            ? NetworkAssessment::ordinary()
            : $this->policy->assess($signals);
    }

    private function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
