<?php

namespace App\Services\Network;

/**
 * Resultado da classificação de um IP: categoria, ação da política e os
 * sinais que levaram a ela.
 */
final readonly class NetworkAssessment
{
    /**
     * @param  list<NetworkSignal>  $signals  todos os sinais encontrados
     * @param  NetworkSignal|null  $primary  sinal que determinou a ação
     */
    public function __construct(
        public NetworkCategory $category,
        public NetworkAction $action,
        public array $signals = [],
        public ?NetworkSignal $primary = null,
    ) {}

    public static function ordinary(): self
    {
        return new self(NetworkCategory::Ordinary, NetworkAction::Allow);
    }

    public function isOrdinary(): bool
    {
        return $this->category === NetworkCategory::Ordinary;
    }

    /**
     * Fontes que apontaram o IP, para registro e diagnóstico.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        return array_values(array_unique(array_map(
            static fn (NetworkSignal $signal): string => $signal->source,
            $this->signals
        )));
    }
}
