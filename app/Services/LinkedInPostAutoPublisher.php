<?php

namespace App\Services;

use App\Contracts\VacancySource;
use App\Models\LinkedInPostCandidate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LinkedInPostAutoPublisher
{
    public function __construct(
        private readonly VacancyImporter $importer
    ) {
    }

    /**
     * Publica automaticamente candidatos que passaram pelos filtros editoriais.
     *
     * @return array{approved: int, rejected: int, failed: int}
     */
    public function publishPending(): array
    {
        $totals = ['approved' => 0, 'rejected' => 0, 'failed' => 0];

        LinkedInPostCandidate::query()
            ->where('status', 'pending_review')
            ->orderBy('id')
            ->chunkById(50, function ($candidates) use (&$totals): void {
                foreach ($candidates as $candidate) {
                    try {
                        $result = $this->publish($candidate);

                        $totals[$result]++;
                    } catch (Throwable $exception) {
                        $candidate->forceFill([
                            'status' => 'rejected',
                            'decision_reason' => 'Falha no processamento automático: ' . mb_substr($exception->getMessage(), 0, 1500),
                            'reviewed_at' => now(),
                        ])->save();

                        Log::error('Falha ao publicar automaticamente candidato do LinkedIn.', [
                            'candidate_id' => $candidate->id,
                            'post_url' => $candidate->post_url,
                            'error' => $exception->getMessage(),
                        ]);

                        $totals['failed']++;
                    }
                }
            });

        return $totals;
    }

    private function publish(LinkedInPostCandidate $candidate): string
    {
        $missing = [];

        foreach ([
            'title' => $candidate->title,
            'company' => $candidate->company,
            'description' => $candidate->description,
            'published_at' => $candidate->published_at,
        ] as $field => $value) {
            if (blank($value)) {
                $missing[] = $field;
            }
        }

        $applicationUrl = $candidate->application_url ?: $candidate->post_url;

        if (
            blank($applicationUrl)
            || !filter_var($applicationUrl, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($applicationUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
        ) {
            $missing[] = 'application_url';
        }

        if ($missing !== []) {
            $candidate->forceFill([
                'status' => 'rejected',
                'decision_reason' => 'Rejeitado automaticamente: dados obrigatórios ausentes ou inválidos (' . implode(', ', $missing) . ').',
                'reviewed_at' => now(),
            ])->save();

            return 'rejected';
        }

        $source = new class($candidate, $applicationUrl) implements VacancySource {
            public function __construct(
                private readonly LinkedInPostCandidate $candidate,
                private readonly string $applicationUrl
            ) {
            }

            public function name(): string
            {
                return 'linkedin-posts';
            }

            public function fetch(): iterable
            {
                return [[
                    'title' => $this->candidate->title,
                    'company' => $this->candidate->company,
                    'description' => $this->candidate->description,
                    'location' => $this->candidate->location,
                    'work_model' => $this->candidate->work_model,
                    'employment_type' => $this->candidate->employment_type,
                    'salary' => $this->candidate->salary,
                    'application_url' => $this->applicationUrl,
                    'external_id' => $this->candidate->post_id,
                    'original_published_at' => $this->candidate->published_at,
                ]];
            }
        };

        DB::transaction(function () use ($candidate, $source): void {
            $result = $this->importer->import($source);

            if (($result['created'] ?? 0) !== 1 && ($result['updated'] ?? 0) !== 1) {
                throw new \RuntimeException(
                    'O importador não cadastrou a oportunidade; os dados não passaram pela validação final.'
                );
            }

            $candidate->forceFill([
                'status' => 'approved',
                'decision_reason' => 'Aprovado automaticamente após validação dos dados e cadastrado como vaga.',
                'reviewed_at' => now(),
            ])->save();
        });

        return 'approved';
    }
}
