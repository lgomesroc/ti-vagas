<?php

namespace Tests\Feature;

use App\Jobs\FetchLinkedInJobsJob;
use Tests\TestCase;

class LinkedInRuntimeConfigTest extends TestCase
{
    public function test_browser_runs_headless_by_default(): void
    {
        $this->assertTrue(config('linkedin.headless'), 'Sem X server o Chromium precisa subir headless.');
    }

    public function test_time_budgets_finish_before_their_timeouts(): void
    {
        $job = new FetchLinkedInJobsJob();

        $this->assertGreaterThan(0, config('linkedin.jobs.time_budget'));
        $this->assertLessThan(
            config('linkedin.jobs.timeout'),
            config('linkedin.jobs.time_budget'),
            'O Node deve encerrar sozinho antes do timeout do processo.'
        );
        $this->assertLessThan(
            $job->timeout,
            config('linkedin.jobs.timeout'),
            'O processo deve terminar antes do timeout do job.'
        );

        $this->assertLessThan(
            config('linkedin.secondary_account.timeout'),
            config('linkedin.secondary_account.time_budget')
        );
    }
}
