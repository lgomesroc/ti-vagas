<?php

namespace Tests\Feature;

use App\Sources\FactorialSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FactorialSourceTest extends TestCase
{
    public function test_it_collects_public_factorial_jobs_using_sitemap_lastmod_and_detail_pages(): void
    {
        $base = 'https://ibrowse.factorialhr.com.br';
        $jobUrl = $base . '/job_posting/analista-infraestrutura-junior-332502';

        Http::fake([
            $base . '/' => Http::response(<<<'HTML'
                <html><body>
                    <select id="location_filter">
                        <option value="318886">Florianópolis, SC, Brasil</option>
                    </select>
                    <ul>
                        <li data-controller="job-postings"
                            data-is-remote="false"
                            data-job-postings-url="https://ibrowse.factorialhr.com.br/job_posting/analista-infraestrutura-junior-332502"
                            data-location-id="318886"
                            data-team-id="95948"
                            data-contract-type="indefinite"
                            role="button">
                            <div class="font-bold">Analista de Infraestrutura Júnior</div>
                        </li>
                    </ul>
                </body></html>
                HTML, 200),
            $base . '/sitemap.xml' => Http::response(
                '<urlset><url><loc>' . $jobUrl . '</loc><lastmod>' . now('UTC')->subDays(5)->toDateString() . '</lastmod></url></urlset>',
                200
            ),
            $jobUrl => Http::response(<<<'HTML'
                <html><body>
                    <div class="styledText">
                        <p>Atuação com Linux, Docker e monitoramento de serviços.</p>
                    </div>
                    <a href="/apply/analista-infraestrutura-junior-332502">Candidate-se agora</a>
                </body></html>
                HTML, 200),
        ]);

        $source = new FactorialSource(
            companyKey: 'ibrowse',
            companyName: 'Ibrowse Consultoria e Informatica Ltda',
            careerPageUrl: $base,
        );

        $vacancies = collect($source->fetch());

        $this->assertCount(1, $vacancies);
        $this->assertSame('Ibrowse Consultoria e Informatica Ltda', $vacancies[0]['company']);
        $this->assertSame('factorial:ibrowse:332502', $vacancies[0]['external_id']);
        $this->assertSame($base . '/apply/analista-infraestrutura-junior-332502', $vacancies[0]['application_url']);
        $this->assertSame('Florianópolis, SC, Brasil', $vacancies[0]['location']);
        $this->assertSame('Tempo integral', $vacancies[0]['employment_type']);
        $this->assertStringContainsString('Linux, Docker', $vacancies[0]['description']);
        $this->assertNotNull($vacancies[0]['original_published_at']);
        Http::assertSentCount(3);
    }

    public function test_it_discards_factorial_jobs_without_a_sitemap_publication_date(): void
    {
        $base = 'https://ibrowse.factorialhr.com.br';

        Http::fake([
            $base . '/' => Http::response(<<<'HTML'
                <li data-controller="job-postings"
                    data-is-remote="false"
                    data-job-postings-url="https://ibrowse.factorialhr.com.br/job_posting/analista-infraestrutura-junior-332502"
                    data-location-id="1"
                    data-team-id="2"
                    role="button">
                    <div class="font-bold">Analista de Infraestrutura Júnior</div>
                </li>
                HTML, 200),
            $base . '/sitemap.xml' => Http::response('<urlset></urlset>', 200),
        ]);

        $source = new FactorialSource(
            companyKey: 'ibrowse',
            companyName: 'Ibrowse',
            careerPageUrl: $base,
        );

        $this->assertSame([], collect($source->fetch())->all());
        Http::assertSentCount(2);
    }
}
