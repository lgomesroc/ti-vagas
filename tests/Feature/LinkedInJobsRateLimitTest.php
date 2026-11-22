<?php

namespace Tests\Feature;

use App\Exceptions\SourceRateLimitedException;
use App\Jobs\FetchLinkedInJobsJob;
use App\Services\VacancyImporter;
use App\Sources\LinkedInJobsSource;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class LinkedInJobsRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'linkedin.jobs.enabled' => true,
            'linkedin.jobs.delay_ms' => 4000,
            'linkedin.jobs.jitter_ms' => 250,
            'linkedin.jobs.max_retries' => 2,
            'linkedin.jobs.backoff_base_ms' => 20000,
            'linkedin.jobs.backoff_max_ms' => 120000,
            'linkedin.jobs.reschedule_base_seconds' => 900,
            'linkedin.jobs.reschedule_max_seconds' => 14400,
        ]);
    }

    public function test_source_throws_rate_limited_exception_with_retry_after_on_exit_code_75(): void
    {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: "[LinkedIn Jobs público] log\nLINKEDIN_RATE_LIMITED retry_after=120 attempts=3",
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
                errorOutput: 'LINKEDIN_RATE_LIMITED retry_after= attempts=3',
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
            $this->assertStringContainsString('Falha na coleta pública de LinkedIn Jobs', $exception->getMessage());
        }
    }

    public function test_source_passes_configured_pacing_and_backoff_to_the_node_script(): void
    {
        Process::fake(['*' => Process::result(output: '[]')]);

        $this->assertSame([], app(LinkedInJobsSource::class)->fetch());

        Process::assertRan(function ($process) {
            $env = $process->environment;

            return ($env['LINKEDIN_JOBS_DELAY_MS'] ?? null) === '4000'
                && ($env['LINKEDIN_JOBS_JITTER_MS'] ?? null) === '250'
                && ($env['LINKEDIN_JOBS_MAX_RETRIES'] ?? null) === '2'
                && ($env['LINKEDIN_JOBS_BACKOFF_BASE_MS'] ?? null) === '20000'
                && ($env['LINKEDIN_JOBS_BACKOFF_MAX_MS'] ?? null) === '120000';
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

        $job->handle($importer, $source);
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

        $job->handle(Mockery::mock(VacancyImporter::class), $source);
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

        $job->handle(Mockery::mock(VacancyImporter::class), $source);

        $this->addToAssertionCount(1);
    }

    public function test_job_imports_normally_and_keeps_diagnostic_counters(): void
    {
        $vacancies = [['title' => 'Desenvolvedor PHP Júnior']];

        $source = Mockery::mock(LinkedInJobsSource::class);
        $source->shouldReceive('fetch')->once()->andReturn($vacancies);
        $source->shouldReceive('name')->andReturn('linkedin-jobs');

        $importer = Mockery::mock(VacancyImporter::class);
        $importer->shouldReceive('importFetched')
            ->once()
            ->with('linkedin-jobs', $vacancies)
            ->andReturn(['created' => 1, 'updated' => 2, 'skipped' => 3]);

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldNotReceive('release');

        \Log::spy();

        $job = new FetchLinkedInJobsJob();
        $job->setJob($queueJob);

        $job->handle($importer, $source);

        \Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            return $message === 'Job de coleta LinkedIn Jobs concluído.'
                && $context['received'] === 1
                && $context['created'] === 1
                && $context['updated'] === 2
                && $context['skipped'] === 3;
        })->once();
    }

    public function test_job_still_propagates_regular_errors(): void
    {
        $source = Mockery::mock(LinkedInJobsSource::class);
        $source->shouldReceive('fetch')->once()->andThrow(new RuntimeException('erro comum'));

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldNotReceive('release');

        $job = new FetchLinkedInJobsJob();
        $job->setJob($queueJob);

        $this->expectException(RuntimeException::class);

        $job->handle(Mockery::mock(VacancyImporter::class), $source);
    }

    public function test_job_settings_allow_recovery_from_temporary_rate_limit(): void
    {
        $job = new FetchLinkedInJobsJob();

        // Reagendamentos por 429 não esgotam as tentativas como um erro real.
        $this->assertGreaterThan(1, $job->tries);
        $this->assertSame(1, $job->maxExceptions, 'erros reais continuam falhando na primeira ocorrência');
        $this->assertSame(1800, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame('linkedin', $job->queue);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertGreaterThan(0, $job->uniqueFor);
    }

    public function test_reschedule_delay_grows_exponentially_and_is_capped(): void
    {
        $job = new FetchLinkedInJobsJob();

        $this->assertSame(900, $job->rescheduleDelay(null, 1));
        $this->assertSame(1800, $job->rescheduleDelay(null, 2));
        $this->assertSame(3600, $job->rescheduleDelay(null, 3));
        $this->assertSame(14400, $job->rescheduleDelay(null, 10));
        $this->assertSame(20000, $job->rescheduleDelay(20000, 1), 'Retry-After maior que o teto prevalece');
    }

    public function test_sync_command_reports_rate_limit_without_failing_or_importing(): void
    {
        Process::fake([
            '*' => Process::result(
                errorOutput: 'LINKEDIN_RATE_LIMITED retry_after=60 attempts=3',
                exitCode: 75
            ),
        ]);

        $this->artisan('linkedin:collect-jobs --sync')
            ->expectsOutputToContain('HTTP 429')
            ->expectsOutputToContain('Retry-After informado: 60s')
            ->assertExitCode(0);
    }

    public function test_sync_command_still_fails_on_regular_errors(): void
    {
        Process::fake([
            '*' => Process::result(errorOutput: 'erro qualquer', exitCode: 1),
        ]);

        $this->artisan('linkedin:collect-jobs --sync')->assertExitCode(1);
    }

    public function test_queue_mode_dispatches_the_job(): void
    {
        Queue::fake();

        $this->artisan('linkedin:collect-jobs')->assertExitCode(0);

        Queue::assertPushed(FetchLinkedInJobsJob::class);
    }
}
