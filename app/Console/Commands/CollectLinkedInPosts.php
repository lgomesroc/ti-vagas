<?php

namespace App\Console\Commands;

use App\Models\LinkedInPostCandidate;
use App\Services\LinkedInPostAutoPublisher;
use App\Services\LinkedInPostCandidateProcessor;
use App\Services\LinkedInPostSearchClient;
use App\Services\LinkedInPublicPostFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectLinkedInPosts extends Command
{
    protected $signature = 'linkedin:collect-posts {--mode=all : all, duckduckgo ou bot}';

    protected $description = 'Busca, analisa, aprova e cadastra automaticamente oportunidades de publicações públicas do LinkedIn';

    public function handle(
        LinkedInPostSearchClient $client,
        LinkedInPublicPostFetcher $fetcher,
        LinkedInPostCandidateProcessor $processor,
        LinkedInPostAutoPublisher $publisher
    ): int {
        $mode = (string) $this->option('mode');
        if (!in_array($mode, ['all', 'duckduckgo', 'bot'], true)) {
            $this->error('Modo inválido. Use all, duckduckgo ou bot.');
            return self::INVALID;
        }

        if (!config('linkedin.enabled')) {
            $this->warn('Coleta do LinkedIn desabilitada. Configure LINKEDIN_POSTS_ENABLED=true.');
            return self::SUCCESS;
        }

        try {
            $posts = $client->search($mode);
        } catch (Throwable $exception) {
            Log::error('Falha na pesquisa de publicações do LinkedIn.', ['error' => $exception->getMessage()]);
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $knownPostUrls = LinkedInPostCandidate::query()
            ->pluck('post_url')
            ->mapWithKeys(fn ($url): array => [$this->canonicalPostUrl((string) $url) => true])
            ->all();

        $totals = [
            'received' => count($posts),
            'already_seen' => 0,
            'created' => 0,
            'updated' => 0,
            'rejected' => 0,
            'duplicates' => 0,
            'approved' => 0,
            'publish_rejected' => 0,
            'failed' => 0,
        ];

        foreach ($posts as $post) {
            $postUrl = $post['postUrl'] ?? null;
            if (!is_string($postUrl) || $postUrl === '') {
                $totals['rejected']++;
                continue;
            }

            $postId = $post['postId'] ?? null;
            if (!is_string($postId) || $postId === '') {
                $postId = hash('sha256', $postUrl);
            }

            $canonicalPostUrl = $this->canonicalPostUrl($postUrl);
            if (isset($knownPostUrls[$canonicalPostUrl])) {
                $totals['already_seen']++;
                continue;
            }

            // Reserva antes de abrir/enriquecer. Os índices únicos em post_id e
            // post_url protegem contra provedores duplicados e execuções concorrentes.
            $reserved = DB::table('linkedin_post_candidates')->insertOrIgnore([
                'post_id' => $postId,
                'post_url' => $postUrl,
                'post_text' => (string) ($post['text'] ?? $post['title'] ?? ''),
                'status' => 'processing',
                'decision_reason' => 'Publicação reservada antes da consulta detalhada.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($reserved !== 1) {
                $totals['already_seen']++;
                continue;
            }

            $knownPostUrls[$canonicalPostUrl] = true;

            try {
                $post = $fetcher->fetch($post);
                $result = $processor->process($post);

                foreach (['created', 'updated', 'rejected', 'duplicates'] as $key) {
                    $totals[$key] += $result[$key] ?? 0;
                }
            } catch (Throwable $exception) {
                LinkedInPostCandidate::query()
                    ->where('post_id', $postId)
                    ->where('status', 'processing')
                    ->update([
                        'status' => 'rejected',
                        'decision_reason' => 'Falha durante a única consulta/processamento da publicação: '
                            . mb_substr($exception->getMessage(), 0, 1200),
                        'updated_at' => now(),
                    ]);

                Log::warning('Falha ao processar publicação do LinkedIn; ela ficará registrada e não será consultada novamente.', [
                    'post_id' => $postId,
                    'post_url' => $postUrl,
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
            ['Recebidas', 'Já vistas', 'Novas', 'Atualizadas', 'Rejeitadas', 'Duplicadas', 'Cadastradas', 'Falhas'],
            [[
                $totals['received'],
                $totals['already_seen'],
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
    /**
     * Remove parâmetros de rastreamento e variações de host/barra final.
     * Também reconhece registros históricos gravados antes da canonicalização.
     */
    private function canonicalPostUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return strtolower(trim($url));
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return 'https://www.linkedin.com' . $path;
    }

}
