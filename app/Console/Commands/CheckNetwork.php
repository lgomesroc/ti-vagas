<?php

namespace App\Console\Commands;

use App\Services\Network\IpListStore;
use App\Services\Network\NetworkClassifier;
use App\Services\Network\NetworkSignal;
use Illuminate\Console\Command;

class CheckNetwork extends Command
{
    protected $signature = 'network:check
        {ip? : IP a classificar; sem ele, só mostra o estado das listas}';

    protected $description = 'Mostra o estado das listas de redes suspeitas e como um IP seria classificado.';

    public function handle(IpListStore $store, NetworkClassifier $classifier): int
    {
        $rows = [];

        foreach ((array) config('network_security.sources') as $name => $source) {
            $meta = ($source['enabled'] ?? true) ? $store->meta((string) $name) : null;

            $rows[] = [
                $name,
                ($source['enabled'] ?? true) ? 'sim' : 'não',
                $source['category'] ?? '-',
                $source['confidence'] ?? '-',
                $meta['ranges'] ?? '-',
                $meta['updated_at'] ?? ($source['enabled'] ?? true ? 'nunca baixada' : '-'),
            ];
        }

        $this->table(['Fonte', 'Ativa', 'Categoria', 'Confiança', 'Faixas', 'Atualizada em'], $rows);
        $this->line('Modo: '.config('network_security.mode'));

        $ip = $this->argument('ip');

        if ($ip === null) {
            return self::SUCCESS;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->error('IP inválido.');

            return self::INVALID;
        }

        $assessment = $classifier->assess($ip);

        $this->newLine();
        $this->line('Categoria: '.$assessment->category->label());
        $this->line('Ação da política: '.$assessment->action->value);

        foreach ($assessment->signals as $signal) {
            /** @var NetworkSignal $signal */
            $this->line(" - {$signal->source} ({$signal->category->value}, confiança {$signal->confidence->value})");
        }

        if ($assessment->isOrdinary()) {
            $this->line('Nenhuma lista local aponta este IP (IPs privados e da allowlist também aparecem assim).');
        }

        return self::SUCCESS;
    }
}
