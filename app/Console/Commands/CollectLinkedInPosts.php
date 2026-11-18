<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CollectLinkedInPosts extends Command
{
    protected $signature = 'linkedin:collect-posts';

    protected $description = 'Coleta oportunidades por uma fonte autorizada (atualmente desativada)';

    public function handle(): int
    {
        $this->warn(
            'Coleta desativada: não existe integração autorizada configurada que forneça ' .
            'conteúdo de candidatura e indicador verificável de publicação patrocinada.'
        );

        $this->line(
            'Nenhuma conta do LinkedIn será acessada e nenhuma busca automatizada de publicações será executada.'
        );

        return self::SUCCESS;
    }
}
