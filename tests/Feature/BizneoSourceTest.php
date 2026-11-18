<?php

namespace Tests\Feature;

use App\Sources\BizneoSource;
use Tests\TestCase;

class BizneoSourceTest extends TestCase
{
    public function test_it_builds_a_stable_source_identifier(): void
    {
        $source = new BizneoSource(
            companyKey: 'example',
            companyName: 'Empresa Exemplo',
            url: 'https://careers.example.com',
        );

        $this->assertSame('bizneo-bizneo-example', $source->name());
    }
}
