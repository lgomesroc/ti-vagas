<?php

namespace App\Services;

use Illuminate\Support\Str;

class VacancyAvailabilityDetector
{
    private const UNAVAILABLE_STATUSES = [
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
    ];

    public function isUnavailable(
        ?string $title,
        ?string $description,
        ?string $status = null
    ): bool {
        if ($this->isExplicitUnavailableStatus($status)) {
            return true;
        }

        $normalizedTitle = $this->normalize($title);
        $normalizedDescription = $this->normalize($description);

        if ($this->titleIndicatesUnavailable($normalizedTitle)) {
            return true;
        }

        return $this->descriptionIndicatesUnavailable(
            $normalizedDescription
        );
    }

    private function isExplicitUnavailableStatus(?string $status): bool
    {
        $status = $this->normalize($status);

        if ($status === '') {
            return false;
        }

        return in_array($status, self::UNAVAILABLE_STATUSES, true)
            || preg_match(
                '/^(?:no longer accepting applications|applications closed|position filled|job closed|vacancy closed|requisition closed|vaga encerrada|vaga finalizada|inscricoes encerradas)$/',
                $status
            ) === 1;
    }

    private function titleIndicatesUnavailable(string $title): bool
    {
        if ($title === '') {
            return false;
        }

        $status = implode('|', array_map(
            static fn (string $term): string => preg_quote($term, '/'),
            self::UNAVAILABLE_STATUSES
        ));

        $vacancy = '(?:job|position|role|vacancy|opening|requisition|posting|vaga|oportunidade|posicao|candidatura|inscricoes)';

        return preg_match(
            '/(?:^|[\[\(\-:|])\s*(?:' . $status . ')\s*(?:[\]\)\-:|]|$)/',
            $title
        ) === 1
            || preg_match(
                '/\b' . $vacancy . '\b.{0,35}\b(?:' . $status . ')\b/',
                $title
            ) === 1
            || preg_match(
                '/\b(?:' . $status . ')\b.{0,35}\b' . $vacancy . '\b/',
                $title
            ) === 1;
    }

    private function descriptionIndicatesUnavailable(
        string $description
    ): bool {
        if ($description === '') {
            return false;
        }

        // Evita confundir referências históricas com o status da vaga atual.
        if (preg_match(
            '/(?:experiencia|experience|participacao|participated|worked on|historico).{0,70}(?:processo seletivo|processo de selecao|hiring process|recruitment process).{0,50}(?:encerrad|finalizad|closed|completed|finished)/',
            $description
        ) === 1) {
            return false;
        }

        // Não considera processos seletivos de outras áreas ou equipes.
        if (preg_match(
            '/(?:outras areas|outras equipes|outros departamentos|other departments|other teams|other roles|other positions)/',
            $description
        ) === 1) {
            return false;
        }

        $patterns = [
            // Português.
            '/\b(?:esta|a|essa|desta)?\s*vaga\s+(?:foi\s+)?(?:encerrada|finalizada|cancelada|suspensa|pausada|congelada|preenchida|removida)\b/',
            '/\b(?:esta|a|essa|desta)?\s*oportunidade\s+(?:foi\s+)?(?:encerrada|finalizada|cancelada|suspensa|pausada|congelada|preenchida|removida)\b/',
            '/\b(?:esta|a|essa)?\s*posicao\s+(?:foi\s+)?(?:encerrada|finalizada|cancelada|suspensa|pausada|congelada|preenchida)\b/',
            '/\binscricoes\s+(?:foram\s+)?encerradas\b/',
            '/\bcandidaturas\s+(?:foram\s+)?encerradas\b/',
            '/\bnao estamos mais recebendo candidaturas\b/',
            '/\bnao estamos mais aceitando candidaturas\b/',
            '/\bnao estamos mais aceitando inscricoes\b/',
            '/\bnao aceitamos mais candidaturas\b/',
            '/\bprocesso seletivo (?:desta vaga|desta oportunidade|para esta vaga|para esta oportunidade|da vaga)\s+(?:foi\s+)?(?:encerrado|finalizado|cancelado|suspenso|pausado)\b/',
            '/\bprocesso seletivo\s+(?:foi\s+)?(?:encerrado|finalizado|cancelado|suspenso|pausado)\b/',

            // Inglês.
            '/\b(?:this|the|our)\s+(?:job|position|role|vacancy|opening|requisition|posting)\s+(?:is|has been|was)\s+(?:closed|filled|cancelled|canceled|suspended|paused|completed|finished)\b/',
            '/\b(?:job|position|role|vacancy|opening|requisition|posting)\s+(?:is|has been|was)\s+(?:closed|filled|cancelled|canceled|suspended|paused|completed|finished)\b/',
            '/\b(?:position|job|role|vacancy|opening)\s+(?:has been\s+)?filled\b/',
            '/\b(?:applications|registrations)\s+(?:are|have been|were)\s+(?:closed|suspended|cancelled|canceled)\b/',
            '/\bno longer accepting applications\b/',
            '/\bno longer accepting candidates\b/',
            '/\bwe are no longer receiving applications\b/',
            '/\bthis opportunity is no longer available\b/',
            '/\bthis position is no longer available\b/',
            '/\bthe hiring process for this (?:job|position|role|vacancy)\s+(?:is|has been|was)\s+(?:closed|completed|finished|cancelled|canceled)\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $description) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalize(?string $value): string
    {
        return Str::ascii(mb_strtolower(trim($value ?? '')));
    }
}
