<?php

namespace Tests\Feature;

use App\Sources\SuccessFactorsSource;
use Tests\TestCase;

class SuccessFactorsSourceTest extends TestCase
{
    public function test_it_builds_a_stable_source_identifier(): void
    {
        $source = new SuccessFactorsSource(
            companyKey: 'example',
            companyName: 'Empresa Exemplo',
            url: 'https://careers.example.com',
        );

        $this->assertSame('successfactors-example', $source->name());
    }
    public function test_cpfl_and_taesa_are_registered(): void
    {
        $companies = config('vacancy_sources.successfactors.companies');

        $this->assertSame('CPFL Energia', $companies['cpfl']['name']);
        $this->assertSame('https://vagas.cpfl.com.br/viewalljobs/', $companies['cpfl']['url']);
        $this->assertSame('TAESA', $companies['taesa']['name']);
        $this->assertSame('https://vagas.taesa.com.br/search/', $companies['taesa']['url']);
        $this->assertSame('Capgemini', $companies['capgemini-br']['name']);
        $this->assertSame('https://jobs.capgemini.com/viewalljobs/', $companies['capgemini-br']['url']);
    }
}
