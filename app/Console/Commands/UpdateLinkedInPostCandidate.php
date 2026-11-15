<?php

namespace App\Console\Commands;

use App\Models\LinkedInPostCandidate;
use Illuminate\Console\Command;

class UpdateLinkedInPostCandidate extends Command
{
    protected $signature = 'linkedin:candidate-update
        {id : ID do candidato}
        {--title=}
        {--company=}
        {--location=}
        {--work-model=}
        {--application-url=}
        {--description=}
        {--employment-type=}
        {--salary=}
        {--email=}';

    protected $description = 'Completa dados extraídos de uma publicação do LinkedIn antes da revisão';

    public function handle(): int
    {
        $candidate = LinkedInPostCandidate::query()->find($this->argument('id'));

        if ($candidate === null) {
            $this->error('Candidato não encontrado.');

            return self::FAILURE;
        }

        $fields = [
            'title' => 'title',
            'company' => 'company',
            'location' => 'location',
            'work-model' => 'work_model',
            'application-url' => 'application_url',
            'description' => 'description',
            'employment-type' => 'employment_type',
            'salary' => 'salary',
        ];

        $changes = [];
        foreach ($fields as $option => $column) {
            $value = $this->option($option);
            if (is_string($value) && trim($value) !== '') {
                $changes[$column] = trim($value);
            }
        }

        $email = $this->option('email');
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails = $candidate->emails ?? [];
            $emails[] = strtolower($email);
            $changes['emails'] = array_values(array_unique($emails));
        }

        if ($changes === []) {
            $this->warn('Nenhum campo válido informado.');

            return self::SUCCESS;
        }

        $candidate->forceFill($changes)->save();

        $this->info("Candidato {$candidate->id} atualizado.");

        return self::SUCCESS;
    }
}