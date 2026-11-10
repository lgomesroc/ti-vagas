<?php

namespace App\Console\Commands;

use App\Models\Vacancy;
use App\Services\Geo\VacancyLocationResolver;
use Illuminate\Console\Command;

class ResolveVacancyMunicipalities extends Command
{
    protected $signature = 'vacancies:resolve-municipalities
        {--all : Reprocessa também as vagas que já têm município}';

    protected $description = 'Identifica o município IBGE das vagas já gravadas';

    public function handle(VacancyLocationResolver $resolver): int
    {
        $resolved = 0;
        $unresolved = 0;

        $query = Vacancy::query();

        if (!$this->option('all')) {
            $query->whereNull('ibge_municipality_code');
        }

        $query->orderBy('id')->each(function (Vacancy $vacancy) use (
            $resolver,
            &$resolved,
            &$unresolved
        ): void {
            $municipality = $resolver->resolve(
                $vacancy->location,
                $vacancy->title,
                $vacancy->city,
                $vacancy->state_code
            );

            if ($municipality === null) {
                $unresolved++;

                return;
            }

            $vacancy->update([
                'city' => $municipality['name'],
                'state_code' => $municipality['uf'],
                'country_code' => $vacancy->country_code ?? 'BR',
                'ibge_municipality_code' => $municipality['ibge'],
            ]);

            $resolved++;
        });

        $this->info("Vagas com município identificado: {$resolved}");
        $this->line("Vagas sem município identificado: {$unresolved}");

        return self::SUCCESS;
    }
}
