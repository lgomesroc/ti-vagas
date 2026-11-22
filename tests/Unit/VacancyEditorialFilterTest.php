<?php

namespace Tests\Unit;

use App\Services\VacancyEditorialFilter;
use App\Services\VacancyLocationFilter;
use PHPUnit\Framework\TestCase;

class VacancyEditorialFilterTest extends TestCase
{
    private VacancyEditorialFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new VacancyEditorialFilter(
            new VacancyLocationFilter()
        );
    }

    public function test_accepts_junior_development_vacancy_in_brazil(): void
    {
        $this->assertTrue($this->filter->isEligible($this->vacancy(), 'greenhouse'));
    }

    public function test_rejects_internship_before_persistence(): void
    {
        $this->assertFalse($this->filter->isEligible(
            $this->vacancy(['title' => 'Estágio em Desenvolvimento']),
            'greenhouse'
        ));
    }

    public function test_rejects_senior_vacancy_before_persistence(): void
    {
        $this->assertFalse($this->filter->isEligible(
            $this->vacancy(['title' => 'Desenvolvedor Backend Sênior']),
            'greenhouse'
        ));
    }

    public function test_rejects_mid_vacancy_in_title(): void
    {
        $this->assertFalse($this->filter->isEligible(
            $this->vacancy(['title' => 'Angular Front-End Developer Mid']),
            'greenhouse'
        ));
    }

    public function test_rejects_intermediate_vacancy_mentioned_in_description(): void
    {
        $this->assertFalse($this->filter->isEligible(
            $this->vacancy([
                'title' => 'Angular Front-End Developer',
                'description' => 'We are looking for an intermediate-level developer with Angular experience.',
            ]),
            'greenhouse'
        ));
    }

    public function test_rejects_vacancy_requiring_three_or_more_years(): void
    {
        $this->assertFalse($this->filter->isEligible(
            $this->vacancy([
                'description' => 'Requisitos: 5 anos de experiência com PHP.',
            ]),
            'greenhouse'
        ));
    }

    public function test_rejects_non_technology_role(): void
    {
        $this->assertFalse($this->filter->isEligible(
            $this->vacancy(['title' => 'Analista Financeiro Júnior']),
            'greenhouse'
        ));
    }

    public function test_rejects_vacancy_outside_brazil(): void
    {
        $this->assertFalse($this->filter->isEligible(
            $this->vacancy([
                'location' => 'London, United Kingdom',
                'work_model' => 'Presencial',
            ]),
            'greenhouse'
        ));
    }

    private function vacancy(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Desenvolvedor Backend Júnior',
            'description' => 'Atuação com PHP e Laravel. Experiência de até 2 anos.',
            'location' => 'Brasil',
            'work_model' => 'Remoto',
            'external_id' => 'job-123',
        ], $overrides);
    }
}
