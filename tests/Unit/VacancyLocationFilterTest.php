<?php

namespace Tests\Unit;

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

    public function test_accepts_remote_vacancy_in_another_brazilian_state(): void
    {
        $this->assertTrue(
            $this->filter->isEligible(
                'Sao Paulo, Brasil',
                'Desenvolvedor Backend',
                null,
                'Remoto'
            )
        );
    }

    public function test_accepts_remote_vacancy_explicitly_available_in_brazil(): void
    {
        $this->assertTrue(
            $this->filter->isEligible(
                'Sao Paulo',
                'Desenvolvedor Backend',
                'Remote in Brazil.',
                'Remoto'
            )
        );
    }

    public function test_rejects_remote_vacancy_without_brazil_confirmation(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                'Sao Paulo',
                'Desenvolvedor Backend',
                'Remote position.',
                'Remoto'
            )
        );
    }

    public function test_accepts_hybrid_vacancy_in_niteroi(): void
    {
        $this->assertTrue(
            $this->filter->isEligible(
                'Niteroi, RJ, Brasil',
                'Desenvolvedor Backend',
                null,
                'Híbrido'
            )
        );
    }

    public function test_accepts_onsite_vacancy_in_duque_de_caxias(): void
    {
        $this->assertTrue(
            $this->filter->isEligible(
                'Duque de Caxias, RJ',
                'Analista de Suporte',
                null,
                'Presencial'
            )
        );
    }

    public function test_accepts_vacancy_with_unknown_work_model_in_rio_metro(): void
    {
        $this->assertTrue(
            $this->filter->isEligible(
                'Sao Goncalo, RJ',
                'Analista de Suporte',
                null,
                null
            )
        );
    }

    public function test_rejects_hybrid_vacancy_in_macae(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                'Macae, RJ',
                'Desenvolvedor Backend',
                null,
                'Híbrido'
            )
        );
    }

    public function test_rejects_city_outside_allowed_region_when_title_overrides_generic_location(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                'Brasil - Rio de Janeiro',
                'Desenvolvedor Backend - Nova Friburgo/RJ',
                null,
                'Presencial'
            )
        );
    }

    public function test_accepts_allowed_city_in_title(): void
    {
        $this->assertTrue(
            $this->filter->isEligible(
                'Brasil - Rio de Janeiro',
                'Analista de Suporte - Niteroi/RJ',
                null,
                'Presencial'
            )
        );
    }

    public function test_rejects_vacancy_in_foreign_country(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                'London, United Kingdom',
                'Backend Developer',
                null,
                'Remoto'
            )
        );
    }

    public function test_rejects_foreign_vacancy_even_when_description_mentions_brazil(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                'New York, United States',
                'Backend Developer',
                'Remote in Brazil.',
                'Remoto'
            )
        );
    }

    public function test_rejects_hybrid_vacancy_with_only_generic_brazil_location(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                'Brasil',
                'Desenvolvedor Backend',
                null,
                'Híbrido'
            )
        );
    }

    public function test_rejects_onsite_vacancy_in_another_brazilian_state(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                'Sao Paulo, SP, Brasil',
                'Analista de Suporte',
                null,
                'Presencial'
            )
        );
    }

    public function test_rejects_united_states_written_in_portuguese(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                'Estados Unidos',
                'Backend Developer',
                'Remote in Brazil.',
                'Remoto'
            )
        );
    }

    public function test_rejects_germany_written_in_portuguese(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                'Alemanha',
                'Backend Developer',
                'Remote in Brazil.',
                'Remoto'
            )
        );
    }

    public function test_rejects_france_written_in_portuguese(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                'Franca',
                'Backend Developer',
                'Remote in Brazil.',
                'Remoto'
            )
        );
    }

    public function test_rejects_spain_written_in_portuguese(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                'Espanha',
                'Backend Developer',
                'Remote in Brazil.',
                'Remoto'
            )
        );
    }

    public function test_rejects_portugal_written_in_portuguese(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                'Portugal',
                'Backend Developer',
                'Remote in Brazil.',
                'Remoto'
            )
        );
    }

    public function test_rejects_vacancy_with_empty_location_and_unknown_work_model(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                '',
                'Desenvolvedor Backend',
                null,
                null
            )
        );
    }

    public function test_rejects_vacancy_with_null_location_and_unknown_work_model(): void
    {
        $this->assertFalse(
            $this->filter->isEligible(
                null,
                'Desenvolvedor Backend',
                null,
                null
            )
        );
    }

    public function test_accepts_remote_vacancy_with_null_location_when_brazil_is_explicit_in_description(): void
    {
        $this->assertTrue(
            $this->filter->isEligible(
                null,
                'Desenvolvedor Backend',
                'Remote in Brazil.',
                'Remoto'
            )
        );
    }
}
