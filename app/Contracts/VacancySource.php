<?php

namespace App\Contracts;

interface VacancySource
{
    /**
     * Identificador único da fonte.
     */
    public function name(): string;

    /**
     * Busca oportunidades na fonte.
     *
     * Cada oportunidade deve retornar os seguintes campos:
     *
     * title
     * company
     * description
     * location
     * work_model
     * employment_type
     * salary
     * application_url
     * external_id
     * original_published_at
     *
     * A data original de publicação é obrigatória.
     *
     * @return iterable<array<string, mixed>>
     */
    public function fetch(): iterable;
}
