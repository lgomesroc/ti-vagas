<?php

namespace App\Console\Commands;

use App\Services\LinkedInPostCandidateProcessor;
use App\Services\LinkedInPostSearchClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectLinkedInPosts extends Command
{
    protected $signature = 'linkedin:collect-posts';

    protected $description = 'Busca publicações públicas de recrutadores e empresas no LinkedIn';

    public function handle(
        LinkedInPostSearchClient $client,
        LinkedInPostCandidateProcessor $processor
    ): int {
        if (!config('linkedin.enabled')) {
            $this->warn('Coleta do LinkedIn desabilitada. Configure LINKEDIN_POSTS_ENABLED=true.');

            return self::SUCCESS;
        }

        try {
            $posts = $client->search();
        } catch (Throwable $exception) {
            Log::error('Falha na pesquisa de publicações do LinkedIn.', [
                'error' => $exception->getMessage(),
            ]);

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $totals = [
            'received' => count($posts),
            'created' => 0,
            'updated' => 0,
            'rejected' => 0,
            'duplicates' => 0,
        ];

        foreach ($posts as $post) {
            try {
                $result = $processor->process($post);

                foreach (['created', 'updated', 'rejected', 'duplicates'] as $key) {
                    $totals[$key] += $result[$key] ?? 0;
                }
            } catch (Throwable $exception) {
                Log::warning('Falha ao processar publicação do LinkedIn.', [
                    'post_url' => $post['postUrl'] ?? null,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->table(
            ['Recebidas', 'Novas', 'Atualizadas', 'Rejeitadas', 'Duplicadas'],
            [[
                $totals['received'],
                $totals['created'],
                $totals['updated'],
                $totals['rejected'],
                $totals['duplicates'],
            ]]
        );

        $this->info('Candidatos aprovados automaticamente permanecem pendentes de revisão manual.');

        return self::SUCCESS;
    }
}