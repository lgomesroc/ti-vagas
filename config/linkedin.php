<?php

return [
    'enabled' => filter_var(
        env('LINKEDIN_POSTS_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    'lookback_hours' => (int) env('LINKEDIN_POSTS_LOOKBACK_HOURS', 168),

    // LinkedIn Jobs usa o endpoint público de visitantes, sem sessão autenticada.
    'jobs' => [
        'enabled' => filter_var(env('LINKEDIN_JOBS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'lookback_hours' => max(1, (int) env('LINKEDIN_JOBS_LOOKBACK_HOURS', 168)),
        'max_pages' => max(1, (int) env('LINKEDIN_JOBS_MAX_PAGES', 5)),
        // Intervalo mínimo entre requisições (mais conservador que o anterior, 1200ms).
        'delay_ms' => max(500, (int) env('LINKEDIN_JOBS_DELAY_MS', 2500)),
        'jitter_ms' => max(0, (int) env('LINKEDIN_JOBS_JITTER_MS', 500)),
        'location' => env('LINKEDIN_JOBS_LOCATION', 'Brasil'),
        // Tempo máximo do processo Node. Deve ser menor que o timeout do job (1500s)
        // e maior que o orçamento de coleta, para o Node encerrar sozinho e entregar
        // o resultado parcial antes de qualquer SIGKILL.
        'timeout' => max(300, (int) env('LINKEDIN_JOBS_TIMEOUT', 1200)),
        // Orçamento de tempo da coleta dentro do Node (segundos). 0 = sem limite.
        'time_budget' => max(0, (int) env('LINKEDIN_JOBS_TIME_BUDGET_SECONDS', 900)),

        // HTTP 429: novas tentativas e backoff exponencial dentro do processo (ms).
        // Não há rotação de IP, proxy ou troca de identidade: persistindo o 429,
        // a coleta é interrompida e o job é reagendado.
        'max_retries' => max(0, (int) env('LINKEDIN_JOBS_MAX_RETRIES', 3)),
        'backoff_base_ms' => max(0, (int) env('LINKEDIN_JOBS_BACKOFF_BASE_MS', 30000)),
        'backoff_max_ms' => max(0, (int) env('LINKEDIN_JOBS_BACKOFF_MAX_MS', 300000)),

        // Reagendamento do job após a coleta ser interrompida (segundos).
        'reschedule_base_seconds' => max(1, (int) env('LINKEDIN_JOBS_RESCHEDULE_SECONDS', 900)),
        'reschedule_max_seconds' => max(1, (int) env('LINKEDIN_JOBS_RESCHEDULE_MAX_SECONDS', 14400)),
    ],

    // Navegador sem janela. Padrão true: servidores e GitHub Actions não têm tela.
    // Só use false localmente, para o login manual da conta secundária.
    'headless' => filter_var(env('LINKEDIN_HEADLESS', true), FILTER_VALIDATE_BOOL),

    'secondary_account' => [
        'enabled' => filter_var(env('LINKEDIN_SECONDARY_ACCOUNT_ENABLED', true), FILTER_VALIDATE_BOOL),
        'profile' => env('LINKEDIN_SECONDARY_ACCOUNT_PROFILE', '.local/linkedin-secondary-profile'),
        'storage_state' => env('LINKEDIN_STORAGE_STATE_PATH', '.local/linkedin-storage-state.json'),
        'timeout' => max(300, (int) env('LINKEDIN_SECONDARY_ACCOUNT_TIMEOUT', 900)),
        // Orçamento de tempo da pesquisa dentro do Node (segundos); menor que o timeout.
        'time_budget' => max(0, (int) env('LINKEDIN_POSTS_TIME_BUDGET_SECONDS', 480)),
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
