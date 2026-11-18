<?php

namespace Tests\Feature;

use App\Sources\TalentbrandSource;
use Tests\TestCase;

class TalentbrandSourceTest extends TestCase
{
    public function test_it_builds_a_stable_source_identifier(): void
    {
        $source = new TalentbrandSource(
            companyKey: 'example',
            companyName: 'Empresa Exemplo',
            url: 'https://careers.example.com',
        );

        $this->assertSame('talentbrand-talentbrand-example', $source->name());
    }
}
