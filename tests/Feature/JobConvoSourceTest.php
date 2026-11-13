<?php

namespace Tests\Feature;

use App\Services\VacancyLocationFilter;
use App\Sources\JobConvoSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JobConvoSourceTest extends TestCase
{
    private const CAREER_PAGE_ID = '7b3711ce-e38f-4f76-ab5c-1fbef972c147';

    private const CAREER_PAGE_URL = 'https://app.jobconvo.com/pt-br/careers/Adecco/7b3711ce-e38f-4f76-ab5c-1fbef972c147/';

    private const UUID = '54fab743-a38a-4139-b34e-856be55f6c99';

    private const SLUG = 'MTQ2NjA1NQ-desenvolvedor-php-junior';

    private array $fakeResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            $url = $request->url();

            return $this->fakeResponses[$url]
                ?? $this->defaultFakeResponse($url);
        });
    }

    private function defaultFakeResponse(string $url)
    {
        if (str_starts_with($url, self::CAREER_PAGE_URL)) {
            return Http::response(
                '<!DOCTYPE html><html><body></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        }

        return Http::response(
            'Resposta não simulada: ' . $url,
            404
        );
    }

    private function makeSource(
        ?VacancyLocationFilter $locationFilter = null,
        int $maxPages = 110
    ): JobConvoSource {
        return new JobConvoSource(
            companyKey: 'adecco',
            companyName: 'Adecco',
            baseUrl: self::CAREER_PAGE_URL,
            careerPageId: self::CAREER_PAGE_ID,
            caBundle: null,
            maxPages: $maxPages,
            locationFilter: $locationFilter ?? app(VacancyLocationFilter::class),
        );
    }

    private function makeJobData(array $overrides = []): array
    {
        return array_merge([
            'id' => self::UUID,
            'title' => 'Desenvolvedor PHP Júnior',
            'company' => 'Adecco',
            'status' => '1',
            'visibility' => '0',
            'description' => '<p>Desenvolvimento de aplicações PHP.</p>',
            'requirements' => '<p>Conhecimento em PHP e Laravel.</p>',
            'benefits' => '<p>Vale-refeição e plano de saúde.</p>',
            'annotation' => '',
            'company_about' => '<p>Empresa de recrutamento.</p>',
            'city' => 'Rio de Janeiro',
            'state' => 'Rio de Janeiro',
            'country' => 'Brasil',
            'type_work_location' => 1,
            'employment' => 'N - Normal',
            'salary' => null,
            'pub_date' => '2026-11-10 10:00:00',
            'level' => 'Não se aplica',
        ], $overrides);
    }

    private function makeListingHtml(array $jobs): string
    {
        $html = '<!DOCTYPE html><html><body>';

        foreach ($jobs as $job) {
            $html .= sprintf(
                '<a href="/job/%s/%s/?career_page=%s">%s</a>',
                $job['slug'],
                $job['uuid'],
                self::CAREER_PAGE_ID,
                $job['title']
            );
        }

        return $html . '</body></html>';
    }

    private function makeJobLink(
        string $uuid = self::UUID,
        string $slug = self::SLUG,
        string $title = 'Desenvolvedor PHP Júnior'
    ): array {
        return [
            'uuid' => $uuid,
            'slug' => $slug,
            'title' => $title,
        ];
    }

    private function fakeListingPage(int $page, string $html): void
    {
        $url = self::CAREER_PAGE_URL . '?page=' . $page;

        $this->fakeResponses[$url] = Http::response(
            $html,
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    private function fakeJobDetails(
        string $uuid,
        string $slug,
        array $data,
        int $status = 200
    ): void {
        $url = 'https://app.jobconvo.com/pt-br/api/job/'
            . $uuid . '/' . $slug . '/';

        $this->fakeResponses[$url] = Http::response(
            $data,
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public function test_source_name_uses_company_key(): void
    {
        $source = $this->makeSource();

        $this->assertSame('jobconvo:adecco', $source->name());
    }

    public function test_fetch_returns_normalized_vacancy(): void
    {
        $job = $this->makeJobLink();

        $this->fakeListingPage(
            1,
            $this->makeListingHtml([$job])
        );

        $this->fakeJobDetails(
            $job['uuid'],
            $job['slug'],
            $this->makeJobData()
        );

        $source = $this->makeSource();

        $vacancies = iterator_to_array($source->fetch());

        $this->assertCount(1, $vacancies);

        $vacancy = array_values($vacancies)[0];

        $this->assertSame('Desenvolvedor PHP Júnior', $vacancy['title']);
        $this->assertSame('Adecco', $vacancy['company']);
        $this->assertSame(
            'Rio de Janeiro, Rio de Janeiro, Brasil',
            $vacancy['location']
        );
        $this->assertSame('Híbrido', $vacancy['work_model']);
        $this->assertSame('Tempo integral', $vacancy['employment_type']);
        $this->assertNull($vacancy['salary']);

        $this->assertSame(
            'jobconvo:adecco:' . $job['uuid'],
            $vacancy['external_id']
        );

        $this->assertNotEmpty($vacancy['description']);
        $this->assertNotEmpty($vacancy['application_url']);
        $this->assertNotNull($vacancy['original_published_at']);
    }

    public function test_fetch_preserves_utf8_characters(): void
    {
        $job = $this->makeJobLink(
            title: 'Desenvolvedor PHP Júnior'
        );

        $data = $this->makeJobData([
            'title' => 'Desenvolvedor PHP Júnior',
            'city' => 'São Gonçalo',
            'state' => 'Rio de Janeiro',
            'description' => '<p>Desenvolvimento de aplicações.</p>',
            'company_about' => '<p>Líder mundial em recrutamento.</p>',
        ]);

        $this->fakeListingPage(
            1,
            $this->makeListingHtml([$job])
        );

        $this->fakeJobDetails(
            $job['uuid'],
            $job['slug'],
            $data
        );

        $source = $this->makeSource();

        $vacancies = array_values(iterator_to_array($source->fetch()));

        $this->assertCount(1, $vacancies);
        $this->assertSame('Desenvolvedor PHP Júnior', $vacancies[0]['title']);

        $this->assertStringContainsString(
            'São Gonçalo',
            $vacancies[0]['location']
        );
    }

    public function test_fetch_processes_multiple_pages_and_stops_when_page_repeats(): void
    {
        $firstJob = $this->makeJobLink(
            uuid: '54fab743-a38a-4139-b34e-856be55f6c99',
            slug: 'MTQ2NjA1NQ-desenvolvedor-php-junior'
        );

        $secondJob = $this->makeJobLink(
            uuid: '4c251259-c35e-452b-bae6-f6fb64616177',
            slug: 'MzgxMDMxMA-analista-infraestrutura-junior',
            title: 'Analista de Infraestrutura Júnior'
        );

        $this->fakeListingPage(
            1,
            $this->makeListingHtml([$firstJob])
        );

        $this->fakeListingPage(
            2,
            $this->makeListingHtml([$secondJob])
        );

        $this->fakeListingPage(
            3,
            $this->makeListingHtml([$secondJob])
        );

        $this->fakeJobDetails(
            $firstJob['uuid'],
            $firstJob['slug'],
            $this->makeJobData()
        );

        $this->fakeJobDetails(
            $secondJob['uuid'],
            $secondJob['slug'],
            $this->makeJobData([
                'id' => $secondJob['uuid'],
                'title' => $secondJob['title'],
            ])
        );

        $source = $this->makeSource();

        $vacancies = array_values(iterator_to_array($source->fetch()));

        $this->assertCount(2, $vacancies);

        $this->assertSame(
            $firstJob['uuid'],
            substr(
                $vacancies[0]['external_id'],
                strlen('jobconvo:adecco:')
            )
        );

        $this->assertSame(
            $secondJob['uuid'],
            substr(
                $vacancies[1]['external_id'],
                strlen('jobconvo:adecco:')
            )
        );
    }

    public function test_fetch_does_not_process_duplicate_vacancy_ids(): void
    {
        $job = $this->makeJobLink();

        $this->fakeListingPage(
            1,
            $this->makeListingHtml([$job, $job])
        );

        $this->fakeJobDetails(
            $job['uuid'],
            $job['slug'],
            $this->makeJobData()
        );

        $source = $this->makeSource();

        $vacancies = array_values(iterator_to_array($source->fetch()));

        $this->assertCount(1, $vacancies);
    }

    public function test_fetch_skips_titles_outside_technology_scope(): void
    {
        $job = $this->makeJobLink(
            uuid: '54fab743-a38a-4139-b34e-856be55f6c99',
            slug: 'MTQ2NjA1NQ-agente-monitoramento',
            title: 'Agente de Monitoramento de Parceiros'
        );

        $this->fakeListingPage(
            1,
            $this->makeListingHtml([$job])
        );

        $source = $this->makeSource();

        $vacancies = array_values(iterator_to_array($source->fetch()));

        $this->assertCount(0, $vacancies);

        Http::assertNotSent(function (Request $request) use ($job) {
            return str_contains(
                $request->url(),
                '/api/job/' . $job['uuid'] . '/'
            );
        });
    }

    public function test_fetch_skips_senior_titles(): void
    {
        $job = $this->makeJobLink(
            title: 'Desenvolvedor PHP Sênior'
        );

        $this->fakeListingPage(
            1,
            $this->makeListingHtml([$job])
        );

        $source = $this->makeSource();

        $vacancies = array_values(iterator_to_array($source->fetch()));

        $this->assertCount(0, $vacancies);

        Http::assertNotSent(function (Request $request) use ($job) {
            return str_contains(
                $request->url(),
                '/api/job/' . $job['uuid'] . '/'
            );
        });
    }

    public function test_fetch_skips_vacancies_without_junior_seniority(): void
    {
        $job = $this->makeJobLink(
            title: 'Desenvolvedor PHP'
        );

        $this->fakeListingPage(
            1,
            $this->makeListingHtml([$job])
        );

        $this->fakeJobDetails(
            $job['uuid'],
            $job['slug'],
            $this->makeJobData([
                'title' => 'Desenvolvedor PHP',
                'description' => '<p>Desenvolvimento de aplicações PHP e Laravel.</p>',
                'requirements' => '<p>Conhecimento em programação orientada a objetos.</p>',
                'benefits' => '',
                'annotation' => '',
                'company_about' => '',
            ])
        );

        $source = $this->makeSource();

        $vacancies = array_values(iterator_to_array($source->fetch()));

        $this->assertCount(0, $vacancies);

        Http::assertSent(function (Request $request) use ($job) {
            return str_contains(
                $request->url(),
                '/api/job/' . $job['uuid'] . '/'
            );
        });
    }

    public function test_fetch_ignores_vacancies_without_original_publication_date(): void
    {
        $job = $this->makeJobLink();

        $this->fakeListingPage(
            1,
            $this->makeListingHtml([$job])
        );

        $this->fakeJobDetails(
            $job['uuid'],
            $job['slug'],
            $this->makeJobData([
                'pub_date' => null,
            ])
        );

        $source = $this->makeSource();

        $vacancies = array_values(iterator_to_array($source->fetch()));

        $this->assertCount(0, $vacancies);
    }

    public function test_fetch_ignores_vacancies_with_invalid_publication_date(): void
    {
        $job = $this->makeJobLink();

        $this->fakeListingPage(
            1,
            $this->makeListingHtml([$job])
        );

        $this->fakeJobDetails(
            $job['uuid'],
            $job['slug'],
            $this->makeJobData([
                'pub_date' => 'data-invalida',
            ])
        );

        $source = $this->makeSource();

        $vacancies = array_values(iterator_to_array($source->fetch()));

        $this->assertCount(0, $vacancies);
    }

    public function test_fetch_ignores_inactive_vacancies(): void
    {
        $job = $this->makeJobLink();

        $this->fakeListingPage(
            1,
            $this->makeListingHtml([$job])
        );

        $this->fakeJobDetails(
            $job['uuid'],
            $job['slug'],
            $this->makeJobData([
                'status' => '0',
            ])
        );

        $source = $this->makeSource();

        $vacancies = array_values(iterator_to_array($source->fetch()));

        $this->assertCount(0, $vacancies);
    }

    public function test_fetch_ignores_vacancies_with_empty_description(): void
    {
        $job = $this->makeJobLink();

        $this->fakeListingPage(
            1,
            $this->makeListingHtml([$job])
        );

        $this->fakeJobDetails(
            $job['uuid'],
            $job['slug'],
            $this->makeJobData([
                'description' => '',
                'requirements' => '',
                'benefits' => '',
                'annotation' => '',
                'company_about' => '',
            ])
        );

        $source = $this->makeSource();

        $vacancies = array_values(iterator_to_array($source->fetch()));

        $this->assertCount(0, $vacancies);
    }

    public function test_fetch_continues_after_a_detail_request_fails(): void
    {
        $firstJob = $this->makeJobLink(
            uuid: '54fab743-a38a-4139-b34e-856be55f6c99',
            slug: 'MTQ2NjA1NQ-desenvolvedor-php-junior'
        );

        $secondJob = $this->makeJobLink(
            uuid: '4c251259-c35e-452b-bae6-f6fb64616177',
            slug: 'MzgxMDMxMA-analista-infraestrutura-junior',
            title: 'Analista de Infraestrutura Júnior'
        );

        $this->fakeListingPage(
            1,
            $this->makeListingHtml([$firstJob, $secondJob])
        );

        $this->fakeJobDetails(
            $firstJob['uuid'],
            $firstJob['slug'],
            [],
            500
        );

        $this->fakeJobDetails(
            $secondJob['uuid'],
            $secondJob['slug'],
            $this->makeJobData([
                'id' => $secondJob['uuid'],
                'title' => $secondJob['title'],
            ])
        );

        $source = $this->makeSource();

        $vacancies = array_values(iterator_to_array($source->fetch()));

        $this->assertCount(1, $vacancies);
        $this->assertSame($secondJob['title'], $vacancies[0]['title']);
    }
}
