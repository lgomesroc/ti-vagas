<?php

return [
    'enabled' => filter_var(
        env('LINKEDIN_POSTS_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    'lookback_hours' => (int) env('LINKEDIN_POSTS_LOOKBACK_HOURS', 72),

    'keywords' => [
        'estamos contratando',
        'vaga aberta',
        'vaga desenvolvedor',
        'vaga desenvolvedora',
        'desenvolvedor junior',
        'desenvolvedor júnior',
        'junior developer',
        'junior software engineer',
        'desenvolvedor backend',
        'desenvolvedor PHP',
        'desenvolvedor .NET',
        'desenvolvedor full stack',
        'vaga suporte técnico',
        'analista de suporte',
        'vaga infraestrutura',
        'analista de infraestrutura',
        'vaga DevOps',
        'vaga QA',
        'we are hiring',
        'oportunidade tecnologia',
    ],

    /*
     * Domínios de agregadores são rejeitados mesmo quando chegam por
     * encurtadores de URL. ATS e páginas oficiais de carreira não entram
     * nesta lista.
     */
    'aggregator_domains' => [
        'indeed.com',
        'vagas.com.br',
        'infojobs.com.br',
        'jooble.org',
        'jobrapido.com',
        'glassdoor.com',
        'talent.com',
        'adzuna.com',
        'careerjet.com',
        'empregos.com.br',
        'catho.com.br',
        'netvagas.com.br',
        'remotar.com.br',
        'programathor.com.br',
        'coodesh.com',
        'trabalhabrasil.com.br',
        'jobisjob.com',
        'jobsora.com',
        'careerbuilder.com',
        'simplyhired.com',
        'ziprecruiter.com',
        'monster.com',
        'bebee.com',
        'buscojobs.com',
        'emprego.com.br',
    ],

    'recruiter_terms' => [
        'recruiter',
        'tech recruiter',
        'technical recruiter',
        'recrutador',
        'recrutadora',
        'recrutamento',
        'talent acquisition',
        'talent partner',
        'people acquisition',
        'rh',
        'recursos humanos',
        'human resources',
        'people and culture',
        'people & culture',
    ],
];