<?php

namespace App\Console\Commands;

use App\Services\LinkedInPostAutoPublisher;
use App\Services\LinkedInPostCandidateProcessor;
use App\Services\LinkedInPostSearchClient;
use App\Services\LinkedInPublicPostFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectLinkedInPosts extends Command
{
    protected $signature = 'linkedin:collect-posts';

    protected $description = 'Busca, analisa, aprova e cadastra automaticamente oportunidades de publicações públicas do LinkedIn';

    public function handle(
        LinkedInPostSearchClient $client,
        LinkedInPublicPostFetcher $fetcher,
        LinkedInPostCandidateProcessor $processor,
        LinkedInPostAutoPublisher $publisher
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
            'approved' => 0,
            'publish_rejected' => 0,
            'failed' => 0,
        ];

        foreach ($posts as $post) {
            try {
                $post = $fetcher->fetch($post);
                $result = $processor->process($post);

                foreach (['created', 'updated', 'rejected', 'duplicates'] as $key) {
                    $totals[$key] += $result[$key] ?? 0;
                }
            } catch (Throwable $exception) {
                Log::warning('Falha ao processar publicação do LinkedIn.', [
                    'post_url' => $post['postUrl'] ?? null,
                    'error' => $exception->getMessage(),
                ]);
                $totals['failed']++;
            }
        }

        $publication = $publisher->publishPending();
        $totals['approved'] = $publication['approved'];
        $totals['publish_rejected'] = $publication['rejected'];
        $totals['failed'] += $publication['failed'];

        $this->table(
            ['Recebidas', 'Novas', 'Atualizadas', 'Rejeitadas', 'Duplicadas', 'Cadastradas', 'Falhas'],
            [[
                $totals['received'],
                $totals['created'],
                $totals['updated'],
                $totals['rejected'] + $totals['publish_rejected'],
                $totals['duplicates'],
                $totals['approved'],
                $totals['failed'],
            ]]
        );

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
