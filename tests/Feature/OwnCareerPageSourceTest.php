<?php

namespace Tests\Feature;

use App\Sources\BizneoSource;
use App\Sources\OwnCareerPageSource;
use App\Sources\SuccessFactorsSource;
use App\Sources\TalentbrandSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OwnCareerPageSourceTest extends TestCase
{
    public function test_it_ignores_a_career_page_without_job_postings(): void
    {
        Http::fake([
            'https://careers.example.com' => Http::response('<html><body><h1>Trabalhe conosco</h1></body></html>', 200),
        ]);

        $source = new OwnCareerPageSource(
            companyKey: 'example',
            companyName: 'Empresa Exemplo',
            url: 'https://careers.example.com',
        );

        $this->assertSame('career-page:example', $source->name());
        $this->assertSame([], collect($source->fetch())->all());
    }

    public function test_bizneo_successfactors_and_talentbrand_wrappers_keep_their_source_names(): void
    {
        $this->assertSame(
            'bizneo-bizneo-example',
            (new BizneoSource('example', 'Empresa Exemplo', 'https://careers.example.com'))->name()
        );
        $this->assertSame(
            'successfactors-successfactors-example',
            (new SuccessFactorsSource('example', 'Empresa Exemplo', 'https://careers.example.com'))->name()
        );
        $this->assertSame(
            'talentbrand-talentbrand-example',
            (new TalentbrandSource('example', 'Empresa Exemplo', 'https://careers.example.com'))->name()
        );
    }
}
