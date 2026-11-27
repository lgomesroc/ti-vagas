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
            ->whereIn('status', ['pending_publication', 'pending_review'])
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

        $applicationUrl = $candidate->application_url;
        $applicationEmail = $candidate->application_email;

        if (filled($applicationUrl) && !$this->isValidApplicationUrl($applicationUrl)) $missing[] = 'application_url';
        if (filled($applicationEmail) && filter_var($applicationEmail, FILTER_VALIDATE_EMAIL) === false) $missing[] = 'application_email';
        if (blank($applicationUrl)) $missing[] = 'application_url';

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
                private readonly ?string $applicationUrl
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
                    'application_email' => $this->candidate->application_email,
                    'application_subject' => $this->candidate->application_subject,
                    'source_url' => $this->candidate->post_url,
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
                'status' => 'published',
                'decision_reason' => 'Publicado automaticamente após validação dos dados e cadastro da vaga.',
                'reviewed_at' => now(),
            ])->save();
        });

        return 'approved';
    }

    private function isValidApplicationUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return in_array(
                strtolower((string) parse_url($url, PHP_URL_SCHEME)),
                ['http', 'https'],
                true
            );
        }

        return false;
    }
}
