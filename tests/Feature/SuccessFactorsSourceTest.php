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

        $this->assertSame('successfactors-successfactors-example', $source->name());
    }
}
