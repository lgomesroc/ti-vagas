<?php

namespace Tests\Unit;

use App\Services\VacancyAvailabilityDetector;
use PHPUnit\Framework\TestCase;

class VacancyAvailabilityDetectorCoverageTest extends TestCase
{
    private function detector(): VacancyAvailabilityDetector
    {
        return new VacancyAvailabilityDetector();
    }

    public function test_all_unavailable_api_statuses_are_detected(): void
    {
        $statuses = [
            // Português.
            'encerrado',
            'encerrada',
            'finalizado',
            'finalizada',
            'preenchido',
            'preenchida',
            'pausado',
            'pausada',
            'suspenso',
            'suspensa',
            'cancelado',
            'cancelada',
            'congelado',
            'congelada',

            // Inglês.
            'closed',
            'filled',
            'on hold',
            'paused',
            'suspended',
            'cancelled',
            'canceled',
            'completed',
            'finished',
            'inactive',
            'expired',
        ];

        foreach ($statuses as $status) {
            $this->assertTrue(
                $this->detector()->isUnavailable(
                    'Desenvolvedor PHP Junior',
                    'Vaga para desenvolvedor PHP.',
                    $status
                ),
                "O status '{$status}' deveria indicar indisponibilidade."
            );
        }
    }

    public function test_unavailable_statuses_are_detected_in_titles(): void
    {
        $titles = [
            'Desenvolvedor PHP - Encerrado',
            'Vaga encerrada - Desenvolvedor PHP',
            'Desenvolvedor PHP - Finalizada',
            'Desenvolvedor PHP - Pausada',
            'Desenvolvedor PHP - Suspensa',
            'Desenvolvedor PHP - Congelada',
            'Desenvolvedor PHP - Cancelada',
            'Desenvolvedor PHP - Preenchida',
            'Developer - Closed',
            'Developer - Filled',
            'Developer - On hold',
            'Developer - Paused',
            'Developer - Suspended',
            'Developer - Cancelled',
            'Developer - Canceled',
            'Developer - Completed',
            'Developer - Finished',
        ];

        foreach ($titles as $title) {
            $this->assertTrue(
                $this->detector()->isUnavailable(
                    $title,
                    'Vaga para desenvolvedor.'
                ),
                "O título '{$title}' deveria indicar indisponibilidade."
            );
        }
    }

    public function test_unavailable_expressions_are_detected_in_descriptions(): void
    {
        $expressions = [
            'Inscrições encerradas.',
            'Candidaturas encerradas.',
            'Não estamos mais recebendo candidaturas.',
            'Não estamos mais aceitando candidaturas.',
            'Não estamos mais aceitando inscrições.',
            'Não aceitamos mais candidaturas.',
            'No longer accepting applications.',
            'No longer accepting candidates.',
            'We are no longer receiving applications.',
            'This opportunity is no longer available.',
            'This position is no longer available.',
            'Position filled.',
            'Position is filled.',
            'The position has been filled.',
            'This job is closed.',
            'This vacancy has been closed.',
            'Applications are closed.',
            'Applications have been closed.',
            'Esta vaga foi encerrada.',
            'Esta vaga foi finalizada.',
            'Esta vaga foi cancelada.',
            'Esta vaga foi suspensa.',
            'Esta vaga foi pausada.',
            'Esta vaga foi congelada.',
            'Esta vaga foi preenchida.',
            'Esta oportunidade foi removida.',
            'Processo seletivo encerrado.',
            'Processo seletivo finalizado.',
            'Processo seletivo suspenso.',
            'Processo seletivo cancelado.',
        ];

        foreach ($expressions as $expression) {
            $this->assertTrue(
                $this->detector()->isUnavailable(
                    'Desenvolvedor PHP Junior',
                    $expression
                ),
                "A expressão '{$expression}' deveria indicar indisponibilidade."
            );
        }
    }

    public function test_future_closing_announcements_are_not_detected(): void
    {
        $expressions = [
            'A vaga será encerrada amanhã.',
            'A vaga poderá ser encerrada a qualquer momento.',
            'As inscrições serão encerradas na próxima semana.',
            'As candidaturas poderão ser encerradas em breve.',
            'Applications will close tomorrow.',
            'This position may be closed at any time.',
        ];

        foreach ($expressions as $expression) {
            $this->assertFalse(
                $this->detector()->isUnavailable(
                    'Desenvolvedor PHP Junior',
                    $expression
                ),
                "A expressão futura '{$expression}' não deveria indicar encerramento."
            );
        }
    }

    public function test_historical_and_unrelated_events_are_not_detected(): void
    {
        $expressions = [
            'Experiência em processos seletivos encerrados.',
            'Participação em processo seletivo finalizado.',
            'Nossa empresa está com o processo seletivo suspenso para outras áreas.',
            'O processo seletivo de outros departamentos foi encerrado.',
            'Projeto concluído com sucesso.',
            'Experiência com projetos finalizados.',
            'O processo de contratação de outra equipe está pausado.',
        ];

        foreach ($expressions as $expression) {
            $this->assertFalse(
                $this->detector()->isUnavailable(
                    'Desenvolvedor PHP Junior',
                    $expression
                ),
                "A expressão '{$expression}' não deveria encerrar a vaga atual."
            );
        }
    }

    public function test_generic_finished_words_do_not_indicate_unavailability(): void
    {
        $expressions = [
            'Finished developing the application.',
            'Completed the technical documentation.',
            'Projeto finalizado com sucesso.',
            'Atividades concluídas dentro do prazo.',
        ];

        foreach ($expressions as $expression) {
            $this->assertFalse(
                $this->detector()->isUnavailable(
                    'Desenvolvedor PHP Junior',
                    $expression
                ),
                "A expressão '{$expression}' não deveria indicar indisponibilidade."
            );
        }
    }
}
