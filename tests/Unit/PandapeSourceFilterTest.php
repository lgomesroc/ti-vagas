<?php

namespace Tests\Unit;

use App\Services\VacancyLocationFilter;
use App\Sources\PandapeSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PandapeSourceFilterTest extends TestCase
{
    #[DataProvider('eligibleJuniorVacancies')]
    public function test_accepts_junior_technology_vacancies(
        string $title,
        string $description
    ): void {
        $source = new PandapeSource(
            companyKey: 'test',
            companyName: 'Empresa de teste',
            baseUrl: 'https://example.test',
            locationFilter: new VacancyLocationFilter(),
        );

        $method = new ReflectionMethod($source, 'isEligibleSeniority');

        self::assertTrue($method->invoke($source, $title, $description));
    }

    public static function eligibleJuniorVacancies(): array
    {
        return [
            'junior in title' => [
                'Desenvolvedor PHP Júnior',
                'Atuação com desenvolvimento de aplicações.',
            ],
            'jr abbreviation' => [
                'Analista de Suporte Jr.',
                'Atendimento técnico e suporte aos usuários.',
            ],
            'junior in description' => [
                'Desenvolvedor PHP',
                'Oportunidade para profissional em início de carreira (nível júnior).',
            ],
        ];
    }

    #[DataProvider('ineligibleVacancies')]
    public function test_rejects_senior_and_out_of_scope_vacancies(
        string $title,
        string $description
    ): void {
        $source = new PandapeSource(
            companyKey: 'test',
            companyName: 'Empresa de teste',
            baseUrl: 'https://example.test',
            locationFilter: new VacancyLocationFilter(),
        );

        $method = new ReflectionMethod($source, 'isEligibleSeniority');

        self::assertFalse($method->invoke($source, $title, $description));
    }

    public static function ineligibleVacancies(): array
    {
        return [
            'pleno' => [
                'Desenvolvedor PHP Pleno',
                'Experiência com Laravel.',
            ],
            'senior' => [
                'Analista de Infraestrutura Sênior',
                'Atuação com redes e servidores.',
            ],
            'trainee' => [
                'Trainee de Tecnologia',
                'Programa de formação profissional.',
            ],
            'internship in title' => [
                'Estágio em Infraestrutura de TI',
                'Apoio à equipe de redes e infraestrutura.',
            ],
            'intern in description without junior level' => [
                'Analista de QA',
                'Vaga de intern para atuar com testes de software.',
            ],
            'no junior indication' => [
                'Analista de Suporte Técnico',
                'Experiência com atendimento e suporte.',
            ],
        ];
    }
}
