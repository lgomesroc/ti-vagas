<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LinkedInPostSearchClient
{
    /**
     * Pesquisa somente publicações públicas do LinkedIn.
     *
     * Não utiliza login, senha ou cookie de sessão do usuário.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(): array
    {
        $token = (string) config('linkedin.apify.token');
        $actorId = trim((string) config('linkedin.apify.actor_id'));

        if ($token === '' || $actorId === '') {
            throw new RuntimeException(
                'Configure APIFY_API_TOKEN e APIFY_LINKEDIN_POSTS_ACTOR.'
            );
        }

        $keywords = array_values(array_unique(array_filter(
            config('linkedin.apify.keywords', []),
            fn ($keyword): bool => is_string($keyword) && trim($keyword) !== ''
        )));

        if ($keywords === []) {
            throw new RuntimeException(
                'Nenhuma palavra-chave de pesquisa do LinkedIn foi configurada.'
            );
        }

        $nowUtc = now('UTC');
        $input = [
            'keywords' => array_slice($keywords, 0, 20),
            'startDate' => $nowUtc
                ->copy()
                ->subHours((int) config('linkedin.lookback_hours', 72))
                ->toDateString(),
            'endDate' => $nowUtc->toDateString(),
            'maxPostsPerKeyword' => max(
                1,
                min(
                    100,
                    (int) config('linkedin.apify.max_posts_per_keyword', 8)
                )
            ),
            'includeArticles' => false,
        ];

        $actorPath = rawurlencode(str_replace('/', '~', $actorId));
        $maxCharge = rawurlencode(
            (string) config('linkedin.apify.max_total_charge_usd', '0.15')
        );

        $startResponse = Http::acceptJson()
            ->withToken($token)
            ->timeout(45)
            ->post(
                "https://api.apify.com/v2/acts/{$actorPath}/runs"
                    . "?maxTotalChargeUsd={$maxCharge}",
                $input
            );

        $startResponse->throw();

        $runId = $startResponse->json('data.id');

        if (!is_string($runId) || $runId === '') {
            throw new RuntimeException(
                'O provedor não retornou o identificador da execução.'
            );
        }

        $finishedRun = $this->waitForRun($token, $runId);

        $datasetId = $finishedRun['defaultDatasetId'] ?? null;

        if (!is_string($datasetId) || $datasetId === '') {
            throw new RuntimeException(
                'A execução terminou sem informar o dataset de resultados.'
            );
        }

        $itemsResponse = Http::acceptJson()
            ->withToken($token)
            ->timeout(45)
            ->get(
                "https://api.apify.com/v2/datasets/{$datasetId}/items",
                [
                    'format' => 'json',
                    'clean' => 'true',
                    'limit' => 1000,
                ]
            );

        $itemsResponse->throw();

        $items = $itemsResponse->json();

        if (!is_array($items)) {
            throw new RuntimeException(
                'O provedor retornou um formato de resultados inválido.'
            );
        }

        return array_values(array_filter(
            $items,
            fn ($item): bool => is_array($item)
                && ($item['resultType'] ?? 'post') === 'post'
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForRun(string $token, string $runId): array
    {
        $maxWait = max(
            30,
            (int) config('linkedin.apify.max_wait_seconds', 300)
        );
        $deadline = time() + $maxWait;

        do {
            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout(30)
                ->get("https://api.apify.com/v2/actor-runs/{$runId}");

            $response->throw();

            $run = $response->json('data');

            if (!is_array($run)) {
                throw new RuntimeException(
                    'Não foi possível consultar o estado da execução no provedor.'
                );
            }

            $status = $run['status'] ?? null;

            if ($status === 'SUCCEEDED') {
                return $run;
            }

            if (in_array($status, [
                'FAILED',
                'ABORTED',
                'TIMED-OUT',
            ], true)) {
                throw new RuntimeException(
                    'A execução do provedor terminou com status: '
                    . (string) $status
                );
            }

            sleep(5);
        } while (time() < $deadline);

        throw new RuntimeException(
            "A execução {$runId} excedeu o tempo máximo de espera."
        );
    }
}