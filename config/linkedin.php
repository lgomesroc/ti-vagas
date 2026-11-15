<?php

return [
    'enabled' => filter_var(
        env('LINKEDIN_POSTS_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    'lookback_hours' => (int) env('LINKEDIN_POSTS_LOOKBACK_HOURS', 72),

    'apify' => [
        'token' => env('APIFY_API_TOKEN'),
        'actor_id' => env(
            'APIFY_LINKEDIN_POSTS_ACTOR',
            'johnvc~linkedin-post-search-api'
        ),
        'max_total_charge_usd' => (string) env(
            'APIFY_LINKEDIN_MAX_CHARGE_USD',
            '0.15'
        ),
        'max_posts_per_keyword' => (int) env(
            'APIFY_LINKEDIN_MAX_POSTS_PER_KEYWORD',
            8
        ),
        'max_wait_seconds' => (int) env(
            'APIFY_LINKEDIN_MAX_WAIT_SECONDS',
            300
        ),
        'keywords' => [
            'estamos contratando',
            'vaga desenvolvedor',
            'vaga desenvolvedora',
            'vaga programador',
            'vaga programadora',
            'desenvolvedor junior',
            'desenvolvedor júnior',
            'desenvolvedor backend',
            'desenvolvedor full stack',
            'desenvolvedor PHP',
            'desenvolvedor .NET',
            'vaga suporte técnico',
            'vaga infraestrutura',
            'vaga DevOps',
            'vaga QA',
            'oportunidade tecnologia',
            'tech recruiter',
            'recrutador tecnologia',
            'recrutadora tecnologia',
            'talent acquisition tech',
        ],
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