
<?php

return [
    'greenhouse' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),

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
            'stone' => 'Stone',
            'blip-global' => 'Blip',
            'inter' => 'Banco Inter',
        ],
    ],

    'sensedia' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
    ],

    'workday' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
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
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'ciandt' => [
                'name' => 'CI&T',
                'region' => 'global',
            ],
            'swile' => [
                'name' => 'Swile',
                'region' => 'global',
            ],
            'tryjeeves' => [
                'name' => 'Jeeves',
                'region' => 'global',
            ],
            'Ubiminds' => [
                'name' => 'Ubiminds',
                'region' => 'global',
            ],
            'oowlish' => [
                'name' => 'Oowlish',
                'region' => 'global',
            ],
            'Flex' => [
                'name' => 'Flex',
                'region' => 'global',
            ],
            'bluelightconsulting' => [
                'name' => 'Bluelight Consulting',
                'region' => 'global',
            ],
        ],
    ],

    'ozy' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,
    ],

    'workable' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'recargapay' => [
                'name' => 'RecargaPay',
            ],
        ],
    ],

    'inhire' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'orizon' => [
                'name' => 'Orizon',
            ],
            'brivia' => [
                'name' => 'Brivia',
            ],
        ],
    ],

    'gupy' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'montreal' => [
                'name' => 'Montreal',
                'url' => 'https://montreal.gupy.io/',
            ],

            'claro' => [
                'name' => 'Claro',
                'url' => 'https://vempraclaro.gupy.io/',
            ],

            'azzas' => [
                'name' => 'AZZAS 2154',
                'url' => 'https://azzasfashion.gupy.io/',
            ],

            'cepel' => [
                'name' => 'CEPEL',
                'url' => 'https://cepel.gupy.io/',
            ],
        ],
    ],

    'oracle_hcm' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 1000,

        'companies' => [
            'uber' => [
                'site_number' => 'CX_1',
                'site_name' => 'UberCareers',
                'name' => 'Uber',
            ],
        ],
    ],

    'radartec' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,
    ],

    'derevo' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
    ],

    'ashby' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'nubank' => [
                'name' => 'Nubank',
            ],
            'truelogic' => [
                'name' => 'Truelogic',
            ],
            'latamcent' => [
                'name' => 'LatamCent',
            ],
            'constructor' => [
                'name' => 'Constructor',
            ],
            'openai' => [
                'name' => 'OpenAI',
            ],
        ],
    ],

    'geekhunter' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 50,
    ],

    'recrutai' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'grupobamaq' => [
                'name' => 'Grupo Bamaq',
                'domain' => 'grupobamaq.jobs.recrut.ai',
            ],
        ],
    ],

    'pandape' => [
        'ca_bundle' => env(
            'PANDAPE_CA_BUNDLE',
            env('VACANCY_CA_BUNDLE')
        ),
        'max_pages' => 100,

        'companies' => [
            'fiesc' => [
                'name' => 'FIESC',
                'url' => 'https://fiesc.pandape.infojobs.com.br/',
            ],
            'sonda' => [
                'name' => 'SONDA IT',
                'url' => 'https://sondait.pandape.infojobs.com.br/',
            ],
        ],
    ],

    'zoho_recruit' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'spassu' => [
                'name' => 'Spassu',
                'page_name' => 'Careers',
                'remote_country' => 'BR',
            ],
        ],
    ],

    'totvs_ats' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 500,

        'companies' => [
            'vempratotvs' => [
                'name' => 'TOTVS',
                'url' => 'https://atracaodetalentos.totvs.app/vempratotvs/extended',
            ],

            'elotech' => [
                'name' => 'Elotech',
                'url' => 'https://atracaodetalentos.totvs.app/elotech/extended',
            ],

            'vagasnetfive' => [
                'name' => 'Netfive',
                'url' => 'https://atracaodetalentos.totvs.app/vagasnetfive',
            ],

            'vagasolor' => [
                'name' => 'Solor Tecnologia',
                'url' => 'https://atracaodetalentos.totvs.app/vagasolor',
            ],

            'performait' => [
                'name' => 'PERFORMA_IT',
                'url' => 'https://atracaodetalentos.totvs.app/performait/extended',
            ],

            'v8carreira' => [
                'name' => 'V8.TECH',
                'url' => 'https://atracaodetalentos.totvs.app/v8carreira',
            ],
        ],
    ],
];
