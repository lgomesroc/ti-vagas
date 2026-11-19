<?php

return [
    'enabled' => filter_var(
        env('LINKEDIN_POSTS_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    'lookback_hours' => (int) env('LINKEDIN_POSTS_LOOKBACK_HOURS', 72),

    'secondary_account' => [
        'enabled' => filter_var(env('LINKEDIN_SECONDARY_ACCOUNT_ENABLED', true), FILTER_VALIDATE_BOOL),
        'profile' => env('LINKEDIN_SECONDARY_ACCOUNT_PROFILE', '.local/linkedin-secondary-profile'),
        'timeout' => max(600, (int) env('LINKEDIN_SECONDARY_ACCOUNT_TIMEOUT', 600)),
        'keywords' => [
            'vaga suporte técnico',
            'vaga suporte TI',
            'analista de suporte júnior',
            'suporte técnico júnior',
            'técnico de suporte',
            'help desk vaga',
            'service desk vaga',
            'suporte N1 vaga',
            'suporte ao usuário vaga',
            'field service suporte',
            'infraestrutura suporte júnior',
            'technical support junior',
            'vaga desenvolvedor júnior',
            'desenvolvedor backend júnior',
            'desenvolvedor frontend júnior',
            'desenvolvedor full stack júnior',
            'programador júnior vaga',
            'junior software engineer',
            'junior web developer',
            'backend developer junior',
            'frontend developer junior',
            'full stack developer junior',
            'vaga desenvolvedor PHP Laravel',
            'vaga desenvolvedor C# .NET',
            'vaga desenvolvedor Java júnior',
            'vaga desenvolvedor Python júnior',
            'vaga desenvolvedor JavaScript TypeScript',
            'vaga desenvolvedor Node.js júnior',
            'vaga React Angular júnior',
            'estágio desenvolvimento software',
        ],
    ],

    // Certificado raiz da rede, quando há inspeção TLS corporativa.
    'ca_bundle' => env('LINKEDIN_CA_BUNDLE', env('CURL_CA_BUNDLE')),

    // Exige uma fonte contratada/autorizada com sinalização confiável de promoção.
    'authorized_source_enabled' => filter_var(
        env('LINKEDIN_AUTHORIZED_SOURCE_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

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
