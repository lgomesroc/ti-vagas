<?php

return [
    'ca_bundle' => env('VACANCY_CA_BUNDLE'),
    'max_pages' => 3,
    'max_jobs' => 120,
    'request_delay_ms' => 150,

    'sources' => [
        'nort' => [
            'url' => 'https://nortjobs.com/pt-BR/vagas?seniority=entry',
        ],
        'vagas-dev-nort' => [
            'url' => 'https://vagasdev.com/vagas-junior?country=BR',
        ],
    ],
];
