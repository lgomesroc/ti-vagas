<?php

return [
    'greenhouse' => [
        'ca_bundle' => env('GREENHOUSE_CA_BUNDLE'),

        'companies' => [
            'vtex' => 'VTEX',
            'hotmartcareersbr' => 'Hotmart',
            'capco' => 'Capco',
            'quintoandar' => 'QuintoAndar',
            'ebanx' => 'EBANX',
            'sezzle' => 'Sezzle',
            'c6bank' => 'C6 Bank',
            'gympass' => 'Wellhub',
            'jusbrasil' => 'Jusbrasil',
            'systemiq' => 'Systemiq',
        ],
    ],

    'sensedia' => [
        'ca_bundle' => env('GREENHOUSE_CA_BUNDLE'),
    ],

    'workday' => [
        'ca_bundle' => env('GREENHOUSE_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'pwc' => [
                'host' => 'pwc.wd3.myworkdayjobs.com',
                'tenant' => 'pwc',
                'site' => 'Global_Experienced_Careers',
                'locale' => 'pt-BR',
                'name' => 'PwC',
            ],
        ],
    ],

    'lever' => [
        'ca_bundle' => env('GREENHOUSE_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'ciandt' => [
                'name' => 'CI&T',
                'region' => 'global',
            ],
        ],
    ],

    'ozy' => [
        'ca_bundle' => env('GREENHOUSE_CA_BUNDLE'),
        'max_jobs' => 200,
    ],

    'workable' => [
        'ca_bundle' => env('GREENHOUSE_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'recargapay' => [
                'name' => 'RecargaPay',
            ],
        ],
    ],
];
