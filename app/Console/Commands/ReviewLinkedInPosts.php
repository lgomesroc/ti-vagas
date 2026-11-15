<?php

namespace App\Console\Commands;

use App\Contracts\VacancySource;
use App\Models\LinkedInPostCandidate;
use App\Services\VacancyImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class ReviewLinkedInPosts extends Command
{
    protected $signature = 'linkedin:review
        {action : list, approve ou reject}
        {id? : ID do candidato}
        {--reason= : Motivo da rejeição}';

    protected $description = 'Lista, aprova ou rejeita candidatos de vagas encontrados no LinkedIn';

    public function handle(VacancyImporter $importer): int
    {
        $action = (string) $this->argument('action');

        if ($action === 'list') {
            $candidates = LinkedInPostCandidate::query()
                ->where('status', 'pending_review')
                ->orderByDesc('published_at')
                ->limit(100)
                ->get();

            $this->table(
                ['ID', 'Cargo', 'Empresa', 'Localidade', 'Autor', 'Data'],
                $candidates->map(fn ($candidate) => [
                    $candidate->id,
                    $candidate->title ?? '(cargo não identificado)',
                    $candidate->company ?? '(empresa não identificada)',
                    $candidate->location ?? '(local não identificado)',
                    $candidate->author_name ?? '(autor)',
                    $candidate->published_at?->format('d/m/Y H:i'),
                ])->all()
            );

            return self::SUCCESS;
        }

        if ($action === 'show') {
            $id = $this->argument('id');
            $candidate = is_numeric($id)
                ? LinkedInPostCandidate::query()->find($id)
                : null;

            if ($candidate === null) {
                $this->error('Informe um ID válido de candidato.');

                return self::INVALID;
            }

            $this->line('Publicação: ' . $candidate->post_url);
            $this->line('Autor: ' . ($candidate->author_name ?? 'Não identificado'));
            $this->line('Perfil: ' . ($candidate->author_url ?? 'Não informado'));
            $this->line('Cargo: ' . ($candidate->title ?? 'Não identificado'));
            $this->line('Empresa: ' . ($candidate->company ?? 'Não identificada'));
            $this->line('Localidade: ' . ($candidate->location ?? 'Não identificada'));
            $this->line('Modalidade: ' . ($candidate->work_model ?? 'Não identificada'));
            $this->line('E-mails: ' . implode(', ', $candidate->emails ?? []));
            $this->line('Status: ' . $candidate->status);
            $this->line('Motivo: ' . ($candidate->decision_reason ?? 'Não informado'));
            $this->newLine();
            $this->line('Texto da publicação:');
            $this->line($candidate->post_text);
            $this->newLine();
            $this->line('Descrição extraída:');
            $this->line($candidate->description ?? '(vazia)');
            $this->newLine();
            $this->line('Comentários públicos e links:');
            $this->line(json_encode([
                'comments' => $candidate->comments,
                'links' => $candidate->embedded_links,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $id = $this->argument('id');
        if (!is_numeric($id)) {
            $this->error('Informe o ID numérico do candidato.');

            return self::INVALID;
        }

        $candidate = LinkedInPostCandidate::query()->find($id);
        if ($candidate === null) {
            $this->error('Candidato não encontrado.');

            return self::FAILURE;
        }

        if ($candidate->status !== 'pending_review') {
            $this->error('Somente candidatos pendentes podem ser revisados.');

            return self::FAILURE;
        }

        if ($action === 'reject') {
            $candidate->forceFill([
                'status' => 'rejected',
                'decision_reason' => $this->option('reason') ?: 'Rejeitado durante revisão manual.',
                'reviewed_at' => now(),
            ])->save();

            $this->info("Candidato {$candidate->id} rejeitado.");

            return self::SUCCESS;
        }

        if ($action !== 'approve') {
            $this->error('Ação inválida. Use list, approve ou reject.');

            return self::INVALID;
        }

        if (
            blank($candidate->title)
            || blank($candidate->company)
            || blank($candidate->description)
            || $candidate->published_at === null
        ) {
            $this->error('O candidato precisa ter cargo, empresa, descrição e data. Complete os dados antes da aprovação.');

            return self::FAILURE;
        }

        $applicationUrl = $candidate->application_url ?: $candidate->post_url;

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

        try {
            DB::transaction(function () use ($candidate, $source, $importer): void {
                $result = $importer->import($source);

                if (($result['created'] ?? 0) !== 1 && ($result['updated'] ?? 0) !== 1) {
                    throw new InvalidArgumentException(
                        'O importador não criou a vaga. Verifique os dados obrigatórios.'
                    );
                }

                $candidate->forceFill([
                    'status' => 'approved',
                    'decision_reason' => 'Aprovado manualmente e enviado ao cadastro de vagas.',
                    'reviewed_at' => now(),
                ])->save();
            });
        } catch (Throwable $exception) {
            $this->error('Não foi possível aprovar: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Candidato {$candidate->id} aprovado e importado como vaga.");

        return self::SUCCESS;
    }
}