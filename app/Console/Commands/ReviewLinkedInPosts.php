<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ReviewLinkedInPosts extends Command
{
    protected $signature = 'linkedin:review
        {action : list, show, approve ou reject}
        {id? : ID do candidato}
        {--reason= : Motivo da rejeição}';

    protected $description = 'Comando legado desativado: as publicações são processadas automaticamente';

    public function handle(): int
    {
        $this->error('A revisão manual foi desativada. A coleta executa análise, aprovação, rejeição e cadastro automaticamente.');

        return self::FAILURE;
    }
}
