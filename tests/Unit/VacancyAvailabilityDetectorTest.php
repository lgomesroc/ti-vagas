<?php

namespace Tests\Unit;

use App\Services\VacancyAvailabilityDetector;
use PHPUnit\Framework\TestCase;

class VacancyAvailabilityDetectorTest extends TestCase
{
    private VacancyAvailabilityDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new VacancyAvailabilityDetector();
    }

    public function test_detects_unavailable_vacancy_in_portuguese(): void
    {
        $this->assertTrue($this->detector->isUnavailable(
            'Desenvolvedor PHP Júnior',
            'Esta vaga foi encerrada.'
        ));
    }

    public function test_detects_closed_applications_in_english(): void
    {
        $this->assertTrue($this->detector->isUnavailable(
            'Junior Backend Developer',
            'Applications are closed.'
        ));
    }

    public function test_detects_filled_position(): void
    {
        $this->assertTrue($this->detector->isUnavailable(
            'Backend Developer',
            'The position has been filled.'
        ));
    }

    public function test_detects_finished_hiring_process(): void
    {
        $this->assertTrue($this->detector->isUnavailable(
            'Software Engineer',
            'The hiring process for this position has been completed.'
        ));
    }

    public function test_detects_explicit_api_status(): void
    {
        $this->assertTrue($this->detector->isUnavailable(
            'Backend Developer',
            'Opportunity description.',
            'closed'
        ));
    }

    public function test_does_not_detect_future_closing_date(): void
    {
        $this->assertFalse($this->detector->isUnavailable(
            'Desenvolvedor PHP Júnior',
            'A vaga será encerrada no dia 20 de novembro.'
        ));
    }

    public function test_does_not_detect_possible_future_closing(): void
    {
        $this->assertFalse($this->detector->isUnavailable(
            'Backend Developer',
            'The position may close at any time.'
        ));
    }

    public function test_does_not_detect_historical_selection_process(): void
    {
        $this->assertFalse($this->detector->isUnavailable(
            'Desenvolvedor PHP Júnior',
            'Experiência em processos seletivos encerrados.'
        ));
    }

    public function test_does_not_detect_other_departments_hiring_pause(): void
    {
        $this->assertFalse($this->detector->isUnavailable(
            'Desenvolvedor PHP Júnior',
            'Nossa empresa está com o processo seletivo suspenso para outras áreas.'
        ));
    }

    public function test_does_not_detect_completed_project(): void
    {
        $this->assertFalse($this->detector->isUnavailable(
            'Junior Developer',
            'Worked on completed projects using PHP and Laravel.'
        ));
    }

    public function test_does_not_detect_generic_finished_word(): void
    {
        $this->assertFalse($this->detector->isUnavailable(
            'Junior Developer',
            'The candidate will work with finished software projects.'
        ));
    }
}
