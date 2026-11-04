<?php

namespace Database\Seeders;

use App\Models\Vacancy;
use Illuminate\Database\Seeder;

class VacancySeeder extends Seeder
{
    public function run(): void
    {
        $vacancies = [
            [
                'title' => 'Desenvolvedor PHP Júnior',
                'company' => 'Empresa Teste A',
                'description' => 'Vaga fictícia para desenvolvedor PHP júnior. Conhecimentos em PHP, Laravel, PostgreSQL, APIs REST e Git.',
                'location' => 'Rio de Janeiro - RJ',
                'work_model' => 'Remoto',
                'employment_type' => 'CLT',
                'salary' => 'R$ 3.000 a R$ 4.000',
                'application_url' => 'https://example.com/candidatura/php',
                'source' => 'test',
                'external_id' => 'test-php-junior',
                'original_published_at' => now()->subDays(2),
                'collected_at' => now(),
            ],
            [
                'title' => 'Desenvolvedor .NET Júnior',
                'company' => 'Empresa Teste B',
                'description' => 'Vaga fictícia para desenvolvedor C#. Conhecimentos em .NET, ASP.NET Core, SQL Server e orientação a objetos.',
                'location' => 'Brasil',
                'work_model' => 'Remoto',
                'employment_type' => 'CLT',
                'salary' => 'R$ 3.500 a R$ 4.500',
                'application_url' => 'https://example.com/candidatura/dotnet',
                'source' => 'test',
                'external_id' => 'test-dotnet-junior',
                'original_published_at' => now()->subDays(5),
                'collected_at' => now(),
            ],
            [
                'title' => 'Analista de Suporte Técnico Júnior',
                'company' => 'Empresa Teste C',
                'description' => 'Vaga fictícia para suporte técnico. Atendimento ao usuário, manutenção de computadores, Windows e diagnóstico de problemas.',
                'location' => 'Rio de Janeiro - RJ',
                'work_model' => 'Híbrido',
                'employment_type' => 'CLT',
                'salary' => 'R$ 2.500 a R$ 3.200',
                'application_url' => 'https://example.com/candidatura/suporte',
                'source' => 'test',
                'external_id' => 'test-suporte-junior',
                'original_published_at' => now()->subDay(),
                'collected_at' => now(),
            ],
        ];

        foreach ($vacancies as $vacancy) {
            Vacancy::updateOrCreate(
                [
                    'source' => $vacancy['source'],
                    'external_id' => $vacancy['external_id'],
                ],
                $vacancy
            );
        }
    }
}
