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
            'xpinc' => 'XP Inc.',
            'elo' => 'Elo Serviços S.A.',
            'inter' => 'Banco Inter',
            'bancopan' => 'Banco PAN',
            'ifoodcarreiras' => 'iFood',
        ],
    ],

    'sensedia' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
    ],

    'workday' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'hostgator' => [
                'host' => 'web.wd1.myworkdayjobs.com',
                'tenant' => 'hostgator',
                'site' => 'HostGator',
                'locale' => 'pt-BR',
                'name' => 'HostGator',
            ],

            'natura' => [
                'host' => 'natura.wd501.myworkdayjobs.com',
                'tenant' => 'natura',
                'site' => 'NaturaCarreiras',
                'locale' => 'pt-BR',
                'name' => 'Natura',
            ],

            'pwc' => [
                'host' => 'pwc.wd3.myworkdayjobs.com',
                'tenant' => 'pwc',
                'site' => 'Global_Experienced_Careers',
                'locale' => 'pt-BR',
                'name' => 'PwC',
            ],

            'abcfitness' => [
                'host' => 'abcfinancial.wd5.myworkdayjobs.com',
                'tenant' => 'abcfinancial',
                'site' => 'ABCFinancialServices',
                'locale' => '',
                'name' => 'ABC Fitness',
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
            'bv' => [
                'name' => 'Banco BV',
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

    'teamtailor' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'abgi' => [
                'name' => 'ABGi Consulting',
                'feed_url' => 'https://jobs.abgi-consulting.com/jobs.rss',
            ],
            'amanco-wavin' => [
                'name' => 'Amanco Wavin',
                'feed_url' => 'https://careers.wavin.com/jobs.rss',
            ],
        ],
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
            'idwall' => [
                'name' => 'idwall',
            ],
            'rposolutions' => [
                'name' => 'RPO Solutions',
            ],
            'aarin' => [
                'name' => 'Aarin Techfin',
            ],
            'grupofour' => [
                'name' => 'Uhuu / Grupo Four',
            ],
            'amaro' => [
                'name' => 'AMARO',
            ],
            'mjv' => [
                'name' => 'MJV Technology & Innovation',
            ],
            'instacarro' => [
                'name' => 'InstaCarro',
            ],
            'bancopine' => [
                'name' => 'Banco Pine',
            ],
            'riachuelo' => [
                'name' => 'Riachuelo',
            ],
            'alun' => [
                'name' => 'FIAP / Alura / PM3',
            ],
            'avenue' => [
                'name' => 'Avenue',
            ],
            'dtidigital' => [
                'name' => 'dti digital',
            ],
            'trinca' => [
                'name' => 'TRINCA',
            ],
            'brq' => [
                'name' => 'BRQ Digital Solutions',
            ],
            'mottu' => [
                'name' => 'Mottu',
            ],
        ],
    ],

    'mindsight' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'guidance' => [
                'name' => 'Guidance',
                'url' => 'https://oportunidades.mindsight.com.br/guidance',
            ],
        ],
    ],

    'gupy' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),

        'global' => [
            'page_size' => 50,
            'max_pages' => 5,
            'initial_lookback_days' => 60,
            'overlap_minutes' => 60,
            'request_delay_ms' => 250,
            'terms' => [
                'desenvolvedor',
                'desenvolvedora',
                'developer',
                'programador',
                'programadora',
                'software engineer',
                'backend',
                'back-end',
                'frontend',
                'front-end',
                'full stack',
                'analista de suporte',
                'suporte técnico',
                'help desk',
                'service desk',
                'infraestrutura',
                'analista de redes',
                'devops',
                'cloud',
                'QA',
                'quality assurance',
                'cibersegurança',
                'segurança da informação',
                'NOC',
                'SOC',
            ],
        ],
    ],
    'oracle_hcm' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 1000,

        'companies' => [
            'uber' => [
                'base_url' => 'https://iaziqy.fa.ocs.oraclecloud.com',
                'site_number' => 'CX_1',
                'site_name' => 'UberCareers',
                'name' => 'Uber',
            ],

            'dell' => [
                'base_url' => 'https://iawmqy.fa.ocs.oraclecloud.com',
                'site_number' => 'careers',
                'site_name' => 'careers',
                'name' => 'Dell Technologies',
            ],

            'picpay' => [
                'base_url' => 'https://epdm.fa.la1.oraclecloud.com',
                'site_number' => 'PicPay',
                'site_name' => 'PicPay',
                'name' => 'PicPay',
            ],

            'oracle' => [
                'base_url' => 'https://eeho.fa.us2.oraclecloud.com',
                'site_number' => 'jobsearch',
                'site_name' => 'jobsearch',
                'name' => 'Oracle',
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
            'hostinger' => [
                'name' => 'Hostinger',
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

            'tpfengenharia' => [
                'name' => 'TPF Engenharia',
                'domain' => 'tpfengenharia.jobs.recrut.ai',
            ],

            'itrecruiter' => [
                'name' => 'iTRecruiter',
                'domain' => 'itrecruiter.jobs.recrut.ai',
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
            'adecco' => [
                'name' => 'Adecco',
                'url' => 'https://adecco.pandape.infojobs.com.br/',
            ],
            'catho' => [
                'name' => 'Catho',
                'url' => 'https://catho.pandape.infojobs.com.br/',
            ],
            'inklua' => [
                'name' => 'Inklua',
                'url' => 'https://inklua.pandape.infojobs.com.br/',
            ],
            'minsaitbrasil' => [
                'name' => 'Minsait Brasil',
                'url' => 'https://minsaitbrasil.pandape.infojobs.com.br/',
            ],
            '3am' => [
                'name' => '3AM IT Services',
                'url' => 'https://3amitservices.pandape.infojobs.com.br/',
            ],
            'aec' => [
                'name' => 'AeC',
                'url' => 'https://aec.pandape.infojobs.com.br/',
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
                'url' => 'https://atracaodetalentos.totals.app/vagasnetfive',
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

    'compleo' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'providerit' => [
                'name' => 'Provider IT',
            ],
        ],
    ],

    'jobconvo' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_pages' => 110,

        'companies' => [
            'adecco' => [
                'name' => 'Adecco',
                'url' => 'https://app.jobconvo.com/pt-br/careers/Adecco/7b3711ce-e38f-4f76-ab5c-1fbef972c147/',
                'career_page_id' => '7b3711ce-e38f-4f76-ab5c-1fbef972c147',
            ],
        ],
    ],

    'solides' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_pages' => 20,
        'page_size' => 25,

        'companies' => [
            'nethouserj' => [
                'name' => 'NHT FIBER LTDA',
                'slug' => 'nethouserj',
                'url' => 'https://nethouserj.vagas.solides.com.br',
            ],

            'magis5' => [
                'name' => 'Magis5',
                'slug' => 'recrutamentomagis5',
                'url' => 'https://recrutamentomagis5.vagas.solides.com.br',
            ],

            'cashway' => [
                'name' => 'CashWay',
                'slug' => 'cashway',
                'url' => 'https://cashway.vagas.solides.com.br',
            ],

            'sondotecnica' => [
                'name' => 'SONDOTECNICA ENGENHARIA DE SOLOS S A',
                'slug' => 'sondotecnica',
                'url' => 'https://sondotecnica.vagas.solides.com.br',
            ],
        ],
    ],

    'factorial' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 100,

        'companies' => [
            'ibrowse' => [
                'name' => 'Ibrowse Consultoria e Informatica Ltda',
                'url' => 'https://ibrowse.factorialhr.com.br',
            ],
        ],
    ],

    'quickin' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),

        'companies' => [
            'sinqia' => [
                'name' => 'Sinqia (Evertec Brasil)',
                'url' => 'https://jobs.quickin.io/sinqia/jobs',
            ],
            'paranabanco' => [
                'name' => 'Paraná Banco',
                'url' => 'https://jobs.quickin.io/paranabanco/jobs',
            ],
        ],
    ],

    'successfactors' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),

        'companies' => [
            'dafiti' => [
                'name' => 'Dafiti',
                'url' => 'https://career4.successfactors.com/career?company=Dafiti',
            ],
            'b3' => [
                'name' => 'B3',
                'url' => 'https://vagas.b3.com.br/viewalljobs/',
            ],
            'cpfl' => [
                'name' => 'CPFL Energia',
                'url' => 'https://vagas.cpfl.com.br/viewalljobs/',
            ],
            'taesa' => [
                'name' => 'TAESA',
                'url' => 'https://vagas.taesa.com.br/search/',
            ],
        ],
    ],

    'bizneo' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),

        'companies' => [
            'veloe' => [
                'name' => 'Veloe',
                'url' => 'https://vagas.veloe.com.br/',
            ],
        ],
    ],

    'talentbrand' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),

        'companies' => [
            'vr-beneficios' => [
                'name' => 'VR Benefícios',
                'url' => 'https://app.talentbrand.com.br/c/vr-beneficios',
            ],
        ],
    ],

    'jobylon' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'agiletv' => [
                'name' => 'AgileTV (Agile Content)',
                'company_id' => 2371,
                'url' => 'https://emp.jobylon.com/companies/2371-agiletv/',
            ],
        ],
    ],

    'plooral' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'lopes' => [
                'name' => 'Lopes Consultoria',
                'subdomain' => 'lopes',
            ],
        ],
    ],

    'vagas' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 200,

        'companies' => [
            'sgr' => [
                'name' => 'SGR',
                'url' => 'https://trabalheconosco.vagas.com.br/editoragloboeradio/oportunidades',
            ],
            'editora-globo' => [
                'name' => 'EDITORA GLOBO',
                'url' => 'https://trabalheconosco.vagas.com.br/editoragloboeradio/oportunidades',
            ],
        ],
    ],

    'nerdin' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_pages' => 45,
        'max_jobs' => 500,
    ],

    'rjempregos' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'base_url' => 'https://rjempregos.net',
        'max_pages' => 5,
        'max_jobs' => 50,
    ],

    'levty' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 500,
    ],

    'bradesco_csod' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 500,

        'companies' => [
            'bradesco' => [
                'name' => 'Bradesco',
                'url' => 'https://bradesco.csod.com/ux/ats/careersite/1/home?c=bradesco',
                'site_number' => 1,
            ],
            'bradesco-seguros' => [
                'name' => 'Bradesco Seguros',
                'url' => 'https://bradesco.csod.com/ux/ats/careersite/3/home?c=bradesco',
                'site_number' => 3,
            ],
        ],
    ],

    'ibm' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 500,
    ],

    'microsoft' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 300,
    ],

    'cwi' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
    ],

    'own_career_pages' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),

        'companies' => [
            'construcode' => [
                'name' => 'ConstruCode',
                'url' => 'https://site.construcode.com.br/quem-somos/',
            ],

            'talklink' => [
                'name' => 'Talklink',
                'url' => 'https://www.talklink.com.br/vagas',
            ],

            'eduqo' => [
                'name' => 'Eduqo',
                'url' => 'https://eduqo.com.br/talentos',
            ],

            'colmeia-cx' => [
                'name' => 'ColmeIA',
                'url' => 'https://colmeia.cx/carreiras/',
            ],

            'colmeia-tec' => [
                'name' => 'Colmeia Soluções em Tecnologia',
                'url' => 'https://colmeia.tec.br/trabalhe-conosco/',
            ],

            'colmeia-fortaleza' => [
                'name' => 'Colmeia',
                'url' => 'https://www.colmeia.com.br/contato/trabalhe-conosco/',
            ],
        ],
    ],

    'smartrecruiters' => [
        'ca_bundle' => env('VACANCY_CA_BUNDLE'),
        'max_jobs' => 500,

        'companies' => [
            // Use o identificador no endereço:
            // https://careers.smartrecruiters.com/{companyIdentifier}
        ],
    ],
];
