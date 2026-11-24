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

    public function test_rejects_telecom_role_identified_in_title(): void
    {
        $this->assertFalse($this->filter->isEligible(
            $this->vacancy([
                'title' => 'Analista de Suporte Telecom Júnior',
                'description' => 'Suporte técnico e monitoramento de redes de telecomunicações.',
            ]),
            'nort'
        ));
    }

    public function test_rejects_noc_telecom_role_with_generic_title_and_protocol_requirements(): void
    {
        $this->assertFalse($this->filter->isEligible(
            $this->vacancy([
                'title' => 'JOB 68 - Suporte L1 NOC Telecom',
                'description' => 'Suporte N1 em operadora de internet. Monitoramento de rede, incidentes DWDM, IP, MPLS, BGP, OSPF e EIGRP. Experiência de 2 anos.',
                'work_model' => 'Presencial',
            ]),
            'nort'
        ));
    }

    public function test_accepts_generic_n1_it_support_role_with_technical_context(): void
    {
        $this->assertTrue($this->filter->isEligible(
            $this->vacancy([
                'title' => 'JOB 72 - Atendimento Técnico',
                'description' => 'Atendimento de suporte técnico N1, diagnóstico de problemas e resolução de incidentes em estações Windows, Active Directory e hardware. Experiência de até 2 anos.',
                'work_model' => 'Presencial',
            ]),
            'nort'
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
