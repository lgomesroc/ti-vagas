<?php

namespace Tests\Unit;

use App\Services\Geo\RegionScope;
use App\Services\VacancyLocationFilter;
use PHPUnit\Framework\TestCase;

class VacancyLocationFilterTest extends TestCase
{
    private VacancyLocationFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new VacancyLocationFilter();
    }

    public function test_classifies_remote_work_model(): void
    {
        $result = $this->filter->classifyWorkModel(
            'Desenvolvedor Backend Remoto',
            'Sao Paulo, Brasil',
            null
        );

        $this->assertSame('Remoto', $result);
    }

    public function test_classifies_hybrid_work_model(): void
    {
        $result = $this->filter->classifyWorkModel(
            'Desenvolvedor Hibrido',
            'Niteroi, RJ',
            null
        );

        $this->assertSame('Híbrido', $result);
    }

    public function test_classifies_onsite_work_model(): void
    {
        $result = $this->filter->classifyWorkModel(
            'Analista Presencial',
            'Rio de Janeiro, RJ',
            null
        );

        $this->assertSame('Presencial', $result);
    }

    public function test_classifies_remote_work_from_description(): void
    {
        $result = $this->filter->classifyWorkModel(
            'Desenvolvedor Backend',
            'Brasil',
            'A vaga oferece trabalho 100% remoto.'
        );

        $this->assertSame('Remoto', $result);
    }

    public function test_returns_null_when_work_model_is_not_identified(): void
    {
        $result = $this->filter->classifyWorkModel(
            'Desenvolvedor Backend',
            'Rio de Janeiro, RJ',
            'Atuacao em desenvolvimento de sistemas.'
        );

        $this->assertNull($result);
    }

    private function scope(string $regionCode = 'RM-A'): RegionScope
    {
        return new RegionScope(RegionScope::UNIT, $regionCode, $regionCode);
    }

    private function municipality(string $regionCode = 'RM-A'): array
    {
        return [
            'ibge' => '1111111',
            'region_code' => $regionCode,
            'intermediate_region_code' => '9999',
        ];
    }

    public function test_accepts_remote_vacancy_for_visitor_without_location(): void
    {
        $this->assertTrue(
            $this->filter->isEligibleForVisitor(
                'Sao Paulo, Brasil',
                'Desenvolvedor Backend',
                null,
                'Remoto',
                null,
                null
            )
        );
    }

    public function test_accepts_remote_vacancy_explicitly_available_in_brazil(): void
    {
        $this->assertTrue(
            $this->filter->isEligibleForVisitor(
                'Sao Paulo',
                'Desenvolvedor Backend',
                'Remote in Brazil.',
                'Remoto',
                $this->scope(),
                null
            )
        );
    }

    public function test_rejects_remote_vacancy_without_brazil_confirmation(): void
    {
        $this->assertFalse(
            $this->filter->isEligibleForVisitor(
                'Sao Paulo',
                'Desenvolvedor Backend',
                'Remote position.',
                'Remoto',
                $this->scope(),
                null
            )
        );
    }

    public function test_accepts_local_vacancy_in_the_visitor_region(): void
    {
        foreach (['Híbrido', 'Presencial', null] as $workModel) {
            $this->assertTrue(
                $this->filter->isEligibleForVisitor(
                    'Niteroi, RJ, Brasil',
                    'Desenvolvedor Backend',
                    null,
                    $workModel,
                    $this->scope('RM-A'),
                    $this->municipality('RM-A')
                )
            );
        }
    }

    public function test_rejects_local_vacancy_outside_the_visitor_region(): void
    {
        $this->assertFalse(
            $this->filter->isEligibleForVisitor(
                'Sao Paulo, SP, Brasil',
                'Analista de Suporte',
                null,
                'Presencial',
                $this->scope('RM-A'),
                $this->municipality('RM-B')
            )
        );
    }

    public function test_rejects_local_vacancy_when_visitor_has_no_location(): void
    {
        $this->assertFalse(
            $this->filter->isEligibleForVisitor(
                'Niteroi, RJ',
                'Desenvolvedor Backend',
                null,
                'Presencial',
                null,
                $this->municipality()
            )
        );
    }

    public function test_rejects_local_vacancy_when_municipality_is_not_identified(): void
    {
        $this->assertFalse(
            $this->filter->isEligibleForVisitor(
                'Brasil',
                'Desenvolvedor Backend',
                null,
                'Híbrido',
                $this->scope(),
                null
            )
        );
    }

    public function test_rejects_vacancy_in_foreign_country(): void
    {
        $this->assertFalse(
            $this->filter->isEligibleForVisitor(
                'London, United Kingdom',
                'Backend Developer',
                null,
                'Remoto',
                $this->scope(),
                null
            )
        );
    }

    public function test_rejects_foreign_vacancy_even_when_description_mentions_brazil(): void
    {
        $this->assertFalse(
            $this->filter->isEligibleForVisitor(
                'New York, United States',
                'Backend Developer',
                'Remote in Brazil.',
                'Remoto',
                $this->scope(),
                null
            )
        );
    }

    public function test_rejects_foreign_countries_written_in_portuguese(): void
    {
        foreach (['Estados Unidos', 'Alemanha', 'Franca', 'Espanha', 'Portugal'] as $country) {
            $this->assertFalse(
                $this->filter->isEligibleForVisitor(
                    $country,
                    'Backend Developer',
                    'Remote in Brazil.',
                    'Remoto',
                    $this->scope(),
                    null
                )
            );
        }
    }

    public function test_accepts_remote_vacancy_with_null_location_when_brazil_is_explicit_in_description(): void
    {
        $this->assertTrue(
            $this->filter->isEligibleForVisitor(
                null,
                'Desenvolvedor Backend',
                'Remote in Brazil.',
                'Remoto',
                null,
                null
            )
        );
    }

    public function test_collection_rejects_empty_location_without_brazil_mention(): void
    {
        $this->assertFalse(
            $this->filter->isEligibleForCollection(
                '',
                'Desenvolvedor Backend',
                null,
                null
            )
        );
    }
}
