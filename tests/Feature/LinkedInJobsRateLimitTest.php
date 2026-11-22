<?php

namespace Tests\Feature;

use App\Exceptions\SourceRateLimitedException;
use App\Jobs\FetchLinkedInJobsJob;
use App\Services\VacancyImporter;
use App\Sources\LinkedInJobsSource;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LinkedInJobsRateLimitTest extends TestCase
{
    private string $storageState;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageState = tempnam(sys_get_temp_dir(), 'linkedin-state-');
        file_put_contents($this->storageState, '{}');

        config([
            'linkedin.secondary_account.enabled' => true,
            'linkedin.secondary_account.storage_state' => $this->storageState,
            'linkedin.rate_limit.request_delay_ms' => 7000,
            'linkedin.rate_limit.max_retries' => 2,
            'linkedin.rate_limit.reschedule_base_seconds' => 900,
            'linkedin.rate_limit.reschedule_max_seconds' => 21600,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->storageState);

        parent::tearDown();
    }

    public function test_source_throws_rate_limited_exception_with_retry_after_on_exit_code_75(): void
    {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: "[LinkedIn Jobs] log\nLINKEDIN_RATE_LIMITED retry_after=120 attempts=4 reason=HTTP 429 persistente",
                exitCode: 75
            ),
        ]);

        try {
            app(LinkedInJobsSource::class)->fetch();
            $this->fail('Esperava SourceRateLimitedException.');
        } catch (SourceRateLimitedException $exception) {
            $this->assertSame(120, $exception->retryAfterSeconds);
            $this->assertSame('linkedin-jobs', $exception->source);
        }
    }

    public function test_source_rate_limit_without_retry_after_has_null_value(): void
    {
        Process::fake([
            '*' => Process::result(
                errorOutput: 'LINKEDIN_RATE_LIMITED retry_after= attempts=4 reason=x',
                exitCode: 75
            ),
        ]);

        try {
            app(LinkedInJobsSource::class)->fetch();
            $this->fail('Esperava SourceRateLimitedException.');
        } catch (SourceRateLimitedException $exception) {
            $this->assertNull($exception->retryAfterSeconds);
        }
    }

    public function test_source_keeps_treating_other_failures_as_runtime_errors(): void
    {
        Process::fake([
            '*' => Process::result(errorOutput: 'falha qualquer', exitCode: 1),
        ]);

        try {
            app(LinkedInJobsSource::class)->fetch();
            $this->fail('Esperava RuntimeException.');
        } catch (SourceRateLimitedException) {
            $this->fail('Falha comum não deve ser tratada como limitação.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Falha na coleta de LinkedIn Jobs', $exception->getMessage());
        }
    }

    public function test_source_passes_configured_pacing_to_the_node_script(): void
    {
        Process::fake(['*' => Process::result(output: '[]')]);

        $this->assertSame([], app(LinkedInJobsSource::class)->fetch());

        Process::assertRan(function ($process) {
            return ($process->environment['LINKEDIN_REQUEST_DELAY_MS'] ?? null) === '7000'
                && ($process->environment['LINKEDIN_RATE_LIMIT_MAX_RETRIES'] ?? null) === '2';
        });
    }

    public function test_job_releases_with_exponential_delay_and_does_not_fail_on_rate_limit(): void
    {
        $source = Mockery::mock(LinkedInJobsSource::class);
        $source->shouldReceive('fetch')->once()
            ->andThrow(new SourceRateLimitedException('429', null));

        $importer = Mockery::mock(VacancyImporter::class);
        $importer->shouldNotReceive('importFetched');

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(2);
        $queueJob->shouldReceive('release')->once()->with(1800);
        $queueJob->shouldNotReceive('fail');

        $job = new FetchLinkedInJobsJob();
        $job->setJob($queueJob);

        $job->handle($source, $importer);
    }

    public function test_job_release_delay_respects_larger_retry_after(): void
    {
        $source = Mockery::mock(LinkedInJobsSource::class);
        $source->shouldReceive('fetch')->once()
            ->andThrow(new SourceRateLimitedException('429', 7200));

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(7200);

        $job = new FetchLinkedInJobsJob();
        $job->setJob($queueJob);

        $job->handle($source, Mockery::mock(VacancyImporter::class));
    }

    public function test_job_stops_rescheduling_after_attempts_are_exhausted_without_failing(): void
    {
        $source = Mockery::mock(LinkedInJobsSource::class);
        $source->shouldReceive('fetch')->once()
            ->andThrow(new SourceRateLimitedException('429', 300));

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(5);
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldNotReceive('fail');

        $job = new FetchLinkedInJobsJob();
        $job->setJob($queueJob);

        $job->handle($source, Mockery::mock(VacancyImporter::class));

        $this->addToAssertionCount(1);
    }

    public function test_job_imports_normally_when_there_is_no_rate_limit(): void
    {
        $vacancies = [['title' => 'Desenvolvedor PHP Júnior']];

        $source = Mockery::mock(LinkedInJobsSource::class);
        $source->shouldReceive('fetch')->once()->andReturn($vacancies);
        $source->shouldReceive('name')->andReturn('linkedin-jobs');

        $importer = Mockery::mock(VacancyImporter::class);
        $importer->shouldReceive('importFetched')
            ->once()
            ->with('linkedin-jobs', $vacancies)
            ->andReturn(['created' => 1, 'updated' => 0, 'skipped' => 0]);

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldNotReceive('release');

        $job = new FetchLinkedInJobsJob();
        $job->setJob($queueJob);

        $job->handle($source, $importer);
    }

    public function test_job_still_propagates_regular_errors_for_normal_retry(): void
    {
        $source = Mockery::mock(LinkedInJobsSource::class);
        $source->shouldReceive('fetch')->once()->andThrow(new RuntimeException('erro comum'));

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldNotReceive('release');

        $job = new FetchLinkedInJobsJob();
        $job->setJob($queueJob);

        $this->expectException(RuntimeException::class);

        $job->handle($source, Mockery::mock(VacancyImporter::class));
    }

    public function test_job_settings_allow_recovery_from_temporary_rate_limit(): void
    {
        $job = new FetchLinkedInJobsJob();

        $this->assertGreaterThan($job->maxExceptions, $job->tries);
        $this->assertGreaterThan(
            (int) config('linkedin.secondary_account.timeout'),
            $job->timeout,
            'O timeout do job deve exceder o do processo Node.'
        );
        $this->assertSame([300, 900, 1800], $job->backoff());
        $this->assertSame('vacancies', $job->queue);
    }

    public function test_reschedule_delay_grows_exponentially_and_is_capped(): void
    {
        $job = new FetchLinkedInJobsJob();

        $this->assertSame(900, $job->rescheduleDelay(null, 1));
        $this->assertSame(1800, $job->rescheduleDelay(null, 2));
        $this->assertSame(3600, $job->rescheduleDelay(null, 3));
        $this->assertSame(21600, $job->rescheduleDelay(null, 10));
        $this->assertSame(7200, $job->rescheduleDelay(7200, 1));
    }

    public function test_collect_command_reports_rate_limit_without_failing_or_importing(): void
    {
        Process::fake([
            '*' => Process::result(
                errorOutput: 'LINKEDIN_RATE_LIMITED retry_after=60 attempts=4 reason=x',
                exitCode: 75
            ),
        ]);

        $this->artisan('linkedin:collect-jobs')
            ->expectsOutputToContain('HTTP 429')
            ->expectsOutputToContain('Retry-After informado: 60s')
            ->assertExitCode(0);
    }

    public function test_collect_command_can_dispatch_the_queued_job(): void
    {
        Queue::fake();

        $this->artisan('linkedin:collect-jobs --queue')->assertExitCode(0);

        Queue::assertPushed(FetchLinkedInJobsJob::class);
    }
}
