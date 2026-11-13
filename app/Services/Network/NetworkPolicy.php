<?php

namespace App\Services\Network;

/**
 * Política de acesso: transforma os sinais de um IP em uma ação.
 *
 * Regras:
 * - rede comum é sempre liberada;
 * - a ação de cada categoria vem da configuração;
 * - a confiança do sinal limita a ação (baixa: observar; média: restringir;
 *   alta: bloquear), então só fontes oficiais ou conservadoras bloqueiam;
 * - com vários sinais, vale o que pedir a ação mais rígida.
 */
class NetworkPolicy
{
    /**
     * @param  array<string, mixed>  $actions  ação por categoria (network_security.actions)
     */
    public function __construct(private readonly array $actions = []) {}

    public function actionFor(NetworkCategory $category): NetworkAction
    {
        if ($category === NetworkCategory::Ordinary) {
            return NetworkAction::Allow;
        }

        return NetworkAction::fromConfig(
            $this->actions[$category->value] ?? null,
            self::defaultFor($category)
        );
    }

    /**
     * @param  list<NetworkSignal>  $signals
     */
    public function assess(array $signals): NetworkAssessment
    {
        $primary = null;
        $action = NetworkAction::Allow;

        foreach ($signals as $signal) {
            $candidate = $this->actionFor($signal->category)
                ->cappedAt($signal->confidence->maxAction());

            if ($primary === null || $this->outranks($candidate, $signal, $action, $primary)) {
                $primary = $signal;
                $action = $candidate;
            }
        }

        if ($primary === null) {
            return NetworkAssessment::ordinary();
        }

        return new NetworkAssessment($primary->category, $action, array_values($signals), $primary);
    }

    private function outranks(
        NetworkAction $candidate,
        NetworkSignal $signal,
        NetworkAction $current,
        NetworkSignal $primary,
    ): bool {
        if ($candidate->severity() !== $current->severity()) {
            return $candidate->severity() > $current->severity();
        }

        if ($signal->confidence->rank() !== $primary->confidence->rank()) {
            return $signal->confidence->rank() > $primary->confidence->rank();
        }

        return $signal->category->priority() < $primary->category->priority();
    }

    private static function defaultFor(NetworkCategory $category): NetworkAction
    {
        return match ($category) {
            NetworkCategory::Abusive => NetworkAction::Block,
            NetworkCategory::Tor, NetworkCategory::AnonymousProxy => NetworkAction::Restrict,
            NetworkCategory::Vpn, NetworkCategory::Datacenter => NetworkAction::Evaluate,
            NetworkCategory::Ordinary => NetworkAction::Allow,
        };
    }
}
