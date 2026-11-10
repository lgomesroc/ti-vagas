<?php

/*
|--------------------------------------------------------------------------
| Regionalização das vagas
|--------------------------------------------------------------------------
|
| A região de um visitante é definida em camadas:
|
|   1. Se o município pertence a uma Região Metropolitana (RM) ou a uma
|      RIDE, a região é essa unidade.
|   2. Caso contrário, é a Região Geográfica Intermediária do IBGE (2017),
|      sem os municípios que pertencem a alguma RM ou RIDE.
|
| Os dados abaixo corrigem ou complementam o que é importado da API do IBGE
| pelo comando `php artisan geo:import-ibge`. Cada unidade abaixo substitui
| a RM de mesmo nome importada do IBGE (campo `supersedes`).
|
| Os nomes dos municípios são cruzados com a tabela `municipalities` pelo
| nome normalizado e pela UF, então hífens, apóstrofos e acentos não importam.
|
*/

return [
    'ibge_base_url' => env(
        'IBGE_API_BASE_URL',
        'https://servicodados.ibge.gov.br/api'
    ),

    'ca_bundle' => env('VACANCY_CA_BUNDLE'),

    'units' => [

        // Região Metropolitana do Rio de Janeiro (22 municípios).
        [
            'code' => 'RM-RIO-DE-JANEIRO',
            'type' => 'RM',
            'name' => 'Região Metropolitana do Rio de Janeiro',
            'supersedes' => ['rio de janeiro'],
            'members' => [
                'RJ' => [
                    'Belford Roxo',
                    'Cachoeiras de Macacu',
                    'Duque de Caxias',
                    'Guapimirim',
                    'Itaboraí',
                    'Itaguaí',
                    'Japeri',
                    'Magé',
                    'Maricá',
                    'Mesquita',
                    'Nilópolis',
                    'Niterói',
                    'Nova Iguaçu',
                    'Paracambi',
                    'Petrópolis',
                    'Queimados',
                    'Rio Bonito',
                    'Rio de Janeiro',
                    'São Gonçalo',
                    'São João de Meriti',
                    'Seropédica',
                    'Tanguá',
                ],
            ],
        ],

        // Região Metropolitana de São Paulo (39 municípios).
        [
            'code' => 'RM-SAO-PAULO',
            'type' => 'RM',
            'name' => 'Região Metropolitana de São Paulo',
            'supersedes' => ['sao paulo'],
            'members' => [
                'SP' => [
                    'Arujá',
                    'Barueri',
                    'Biritiba-Mirim',
                    'Caieiras',
                    'Cajamar',
                    'Carapicuíba',
                    'Cotia',
                    'Diadema',
                    'Embu das Artes',
                    'Embu-Guaçu',
                    'Ferraz de Vasconcelos',
                    'Francisco Morato',
                    'Franco da Rocha',
                    'Guararema',
                    'Guarulhos',
                    'Itapecerica da Serra',
                    'Itapevi',
                    'Itaquaquecetuba',
                    'Jandira',
                    'Juquitiba',
                    'Mairiporã',
                    'Mauá',
                    'Mogi das Cruzes',
                    'Osasco',
                    'Pirapora do Bom Jesus',
                    'Poá',
                    'Ribeirão Pires',
                    'Rio Grande da Serra',
                    'Salesópolis',
                    'Santa Isabel',
                    'Santana de Parnaíba',
                    'Santo André',
                    'São Bernardo do Campo',
                    'São Caetano do Sul',
                    'São Lourenço da Serra',
                    'São Paulo',
                    'Suzano',
                    'Taboão da Serra',
                    'Vargem Grande Paulista',
                ],
            ],
        ],

        // Região Metropolitana de Campinas (20 municípios).
        [
            'code' => 'RM-CAMPINAS',
            'type' => 'RM',
            'name' => 'Região Metropolitana de Campinas',
            'supersedes' => ['campinas'],
            'members' => [
                'SP' => [
                    'Americana',
                    'Artur Nogueira',
                    'Campinas',
                    'Cosmópolis',
                    'Engenheiro Coelho',
                    'Holambra',
                    'Hortolândia',
                    'Indaiatuba',
                    'Itatiba',
                    'Jaguariúna',
                    'Monte Mor',
                    'Morungaba',
                    'Nova Odessa',
                    'Paulínia',
                    'Pedreira',
                    'Santa Bárbara d\'Oeste',
                    'Santo Antônio de Posse',
                    'Sumaré',
                    'Valinhos',
                    'Vinhedo',
                ],
            ],
        ],

        // RIDE do Distrito Federal e Entorno (Decreto 7.469/2011,
        // redação dada pelo Decreto 11.911/2024).
        [
            'code' => 'RIDE-DF',
            'type' => 'RIDE',
            'name' => 'RIDE do Distrito Federal e Entorno',
            'supersedes' => [],
            'members' => [
                'DF' => ['Brasília'],
                'GO' => [
                    'Abadiânia',
                    'Água Fria de Goiás',
                    'Águas Lindas de Goiás',
                    'Alexânia',
                    'Alto Paraíso de Goiás',
                    'Alvorada do Norte',
                    'Barro Alto',
                    'Cabeceiras',
                    'Cavalcante',
                    'Cidade Ocidental',
                    'Cocalzinho de Goiás',
                    'Corumbá de Goiás',
                    'Cristalina',
                    'Flores de Goiás',
                    'Formosa',
                    'Goianésia',
                    'Luziânia',
                    'Mimoso de Goiás',
                    'Niquelândia',
                    'Novo Gama',
                    'Padre Bernardo',
                    'Pirenópolis',
                    'Planaltina',
                    'Santo Antônio do Descoberto',
                    'São João d\'Aliança',
                    'Simolândia',
                    'Valparaíso de Goiás',
                    'Vila Boa',
                    'Vila Propício',
                ],
                'MG' => [
                    'Arinos',
                    'Buritis',
                    'Cabeceira Grande',
                    'Unaí',
                ],
            ],
        ],

        // RIDE da Grande Teresina (Decreto 10.129/2019).
        [
            'code' => 'RIDE-GRANDE-TERESINA',
            'type' => 'RIDE',
            'name' => 'RIDE da Grande Teresina',
            'supersedes' => [],
            'members' => [
                'PI' => [
                    'Altos',
                    'Beneditinos',
                    'Coivaras',
                    'Curralinhos',
                    'Demerval Lobão',
                    'José de Freitas',
                    'Lagoa Alegre',
                    'Lagoa do Piauí',
                    'Miguel Leão',
                    'Monsenhor Gil',
                    'Teresina',
                    'União',
                ],
                'MA' => ['Timon'],
            ],
        ],

        // RIDE do Polo Petrolina/PE e Juazeiro/BA (Decreto 10.296/2020).
        [
            'code' => 'RIDE-PETROLINA-JUAZEIRO',
            'type' => 'RIDE',
            'name' => 'RIDE do Polo Petrolina e Juazeiro',
            'supersedes' => [],
            'members' => [
                'PE' => [
                    'Petrolina',
                    'Lagoa Grande',
                    'Orocó',
                    'Santa Maria da Boa Vista',
                ],
                'BA' => [
                    'Juazeiro',
                    'Casa Nova',
                    'Curaçá',
                    'Sobradinho',
                ],
            ],
        ],
    ],
];
