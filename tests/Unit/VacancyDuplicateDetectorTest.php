<?php

namespace Tests\Unit;

use App\Services\VacancyDuplicateDetector;
use PHPUnit\Framework\TestCase;

class VacancyDuplicateDetectorTest extends TestCase
{
    private VacancyDuplicateDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new VacancyDuplicateDetector();
    }

    public function test_removes_tracking_parameters_from_application_url(): void
    {
        $normalized = $this->detector->normalizeApplicationUrl(
            'https://www.example.com/jobs/123?utm_source=linkedin&ref=abc&gclid=xyz'
        );

        $this->assertSame(
            'example.com/jobs/123?ref=abc',
            $normalized
        );
    }

    public function test_keeps_functional_query_parameters(): void
    {
        $normalized = $this->detector->normalizeApplicationUrl(
            'https://jobs.example.com/apply?id=123&locale=pt_BR'
        );

        $this->assertSame(
            'jobs.example.com/apply?id=123&locale=pt_BR',
            $normalized
        );
    }

    public function test_normalizes_host_and_trailing_slash(): void
    {
        $normalized = $this->detector->normalizeApplicationUrl(
            'https://WWW.Example.com/jobs/123/'
        );

        $this->assertSame('example.com/jobs/123', $normalized);
    }

    public function test_returns_empty_for_invalid_or_non_http_url(): void
    {
        $this->assertSame('', $this->detector->normalizeApplicationUrl(''));
        $this->assertSame('', $this->detector->normalizeApplicationUrl('not-a-url'));
        $this->assertSame('', $this->detector->normalizeApplicationUrl('mailto:jobs@example.com'));
    }
}
