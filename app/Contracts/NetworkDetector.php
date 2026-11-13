<?php

namespace App\Contracts;

use App\Services\Network\NetworkSignal;

interface NetworkDetector
{
    /**
     * Sinais de rede encontrados para o IP.
     *
     * Deve ser rápido e local: roda em toda requisição de IP desconhecido.
     * Consultas externas ficam fora deste método.
     *
     * @return list<NetworkSignal>
     */
    public function detect(string $ip): array;
}
