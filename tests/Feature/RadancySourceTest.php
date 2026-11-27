<?php

namespace Tests\Feature;

use App\Sources\RadancySource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadancySourceTest extends TestCase
{
    public function test_it_collects_only_the_configured_country_and_technology_facet(): void
    {
        $listing = <<<'HTML'
<section id="search-results" data-total-pages="1" data-total-results="1">
  <ul>
    <li class="search-results-list__item">
      <a class="search-results-list__job-link" href="/en/job/rio/full-stack-developer/tech/12345" data-job-id="12345">
        <h3 class="search-results-list__job-title">Full Stack Developer</h3>
        <span class="job-location">Rio de Janeiro, Brazil</span>
      </a>
    </li>
  </ul>
</section>
HTML;

        $details = <<<'HTML'
<html><head>
<script type="application/ld+json">
{
  "@context":"https://schema.org",
  "@type":"JobPosting",
  "title":"Full Stack Developer",
  "description":"<p>Develop software, APIs and integrations.</p>",
  "datePosted":"2026-11-25T09:00:00-03:00",
  "industry":"Technology",
  "employmentType":"FULL_TIME",
  "jobLocation":{"address":{"addressLocality":"Rio de Janeiro","addressRegion":"RJ","addressCountry":"Brazil"}}
}
</script>
</head><body></body></html>
HTML;

        Http::fake(function (Request $request) use ($listing, $details) {
            $url = $request->url();

            if (str_contains($url, '/results?')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                if (($query['CurrentPage'] ?? '1') !== '1') {
                    return Http::response(['results' => ''], 200);
                }

                return Http::response(['results' => $listing], 200);
            }

            if (str_contains($url, '/en/job/')) {
                return Http::response($details, 200);
            }

            return Http::response('', 404);
        });

        $source = new RadancySource(
            companyKey: 'loreal',
            companyName: "L'Oréal",
            url: 'https://careers.loreal.com/en',
            countries: ['Brazil'],
        );

        $vacancies = collect($source->fetch());

        $this->assertCount(1, $vacancies);
        $this->assertSame('Full Stack Developer', $vacancies[0]['title']);
        $this->assertSame('Rio de Janeiro, RJ, Brazil', $vacancies[0]['location']);
        $this->assertSame('radancy:loreal:12345', $vacancies[0]['external_id']);
        $this->assertTrue($vacancies[0]['source_is_technology']);
        $this->assertSame('Technology', $vacancies[0]['source_category']);
        $this->assertSame('https://careers.loreal.com/en/job/rio/full-stack-developer/tech/12345', $vacancies[0]['application_url']);

        Http::assertSent(function (Request $request): bool {
            if (!str_contains($request->url(), '/results?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['Location'] ?? null) === 'Brazil'
                && ($query['FacetTerm'] ?? null) === 'Tech & Data'
                && ($query['CustomFacetName'] ?? null) === 'Professional Field';
        });

        Http::assertSentCount(2); // One filtered listing request and one detail request.
    }

    public function test_it_ignores_postings_without_a_verifiable_original_publication_date(): void
    {
        $listing = <<<'HTML'
<section data-total-pages="1">
  <li class="search-results-list__item">
    <a class="search-results-list__job-link" href="/en/job/rio/support/tech/67890" data-job-id="67890">
      <h3 class="search-results-list__job-title">IT Support Analyst</h3>
      <span class="job-location">Rio de Janeiro, Brazil</span>
    </a>
  </li>
</section>
HTML;

        Http::fake(function (Request $request) use ($listing) {
            if (str_contains($request->url(), '/results?')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response([
                    'results' => ($query['CurrentPage'] ?? '1') === '1' ? $listing : '',
                ], 200);
            }

            return Http::response('<html><body><p>Support the IT team.</p></body></html>', 200);
        });

        $source = new RadancySource(
            companyKey: 'loreal',
            companyName: "L'Oréal",
            url: 'https://careers.loreal.com/en',
            countries: ['Brazil'],
        );

        $this->assertSame([], collect($source->fetch())->all());
    }
}
