# Arquitetura e Fluxo de Dados

## Visão técnica

A aplicação utiliza PHP 8.3 e Laravel 13, com PostgreSQL como banco de dados, Blade para renderização das páginas e Vite/Tailwind CSS para os recursos de interface.

O código segue a organização convencional do Laravel, separando controladores HTTP, modelos, serviços de domínio, comandos de console, jobs e integrações externas.

## Camada HTTP

- `routes/web.php`: declara as rotas de boas-vindas, aceite, listagem, detalhes e atualização de localização.
- `app/Http/Controllers`: recebe as requisições e coordena a resposta.
- `app/Http/Middleware`: aplica verificações de aceite, reputação de rede, proteção contra parâmetros de localização adulterados e captura inicial de parâmetros UTM.
- `app/Http/Middleware/CaptureUtmParameters.php`: registra na sessão a primeira atribuição de campanha recebida, sanitizando e limitando os valores.
- `resources/views`: contém as páginas Blade e seus componentes visuais.

## Domínio de vagas

- `app/Models/Vacancy.php`: representa os anúncios armazenados e expõe o histórico de verificações de disponibilidade.
- `app/Models/VacancyAvailabilityCheck.php`: representa cada verificação HTTP realizada sobre o link de candidatura.
- `app/Models/LinkedInJobScan.php`: mantém o histórico de IDs de anúncios do LinkedIn Jobs que já receberam uma resposta na consulta detalhada.
- `app/Services/VacancyImporter.php`: normaliza e importa os anúncios recebidos.
- `app/Services/VacancyAvailabilityDetector.php`: identifica sinais de encerramento ou indisponibilidade nos dados recebidos das fontes.
- `app/Services/VacancyLinkChecker.php`: verifica periodicamente os endereços originais de candidatura, interpreta respostas HTTP e registra o resultado de cada verificação.
- `app/Services/VacancyLocationFilter.php`: aplica os critérios de localização e modalidade.
- `app/Services/VacancySourceFactory.php`: instancia as fontes configuradas e registra coletores globais, como InHire. A Gupy global é construída sob demanda pelo método `make('gupy-global')`.
- `app/Sources`: contém os adaptadores das plataformas de recrutamento, incluindo `GupyGlobalSource`, `InhireGlobalSource` e `AblerSource`.
- `app/Jobs/FetchVacancySourceJob.php`: executa a consulta de uma fonte em fila.

## Persistência

As migrations em `database/migrations` definem a estrutura do banco. A migration `2026_11_14_220000_add_vacancy_availability_monitoring.php` acrescenta o estado atual de disponibilidade à vaga e cria `vacancy_availability_checks`, que mantém o histórico das verificações. Os modelos Eloquent representam as entidades utilizadas pela aplicação. Dados territoriais auxiliares ficam em `database/data/municipios.csv`.

## Fluxo resumido de coleta

1. O comando `vacancies:collect` identifica as fontes habilitadas pela fábrica; a Gupy global é executada separadamente por `vacancies:collect-gupy`.
2. A fábrica cria os adaptadores correspondentes.
3. Jobs consultam as fontes e entregam os resultados ao importador.
4. O importador normaliza os campos, verifica duplicidade, elegibilidade e disponibilidade.
5. Os registros válidos são inseridos ou atualizados no banco, preservando a data original de publicação quando aplicável.
6. A coleta global da Gupy consulta a API pública de busca, aplica os termos e a janela incremental configurados e importa sob a identidade `gupy-global`. O coletor global da InHire descobre tenants conhecidos e os consulta sob a identidade `inhire-global`. A Abler é consultada por `AblerSource`, que percorre a listagem pública paginada e importa sob a identidade `abler-global`.
7. Os coletores LinkedIn mantêm histórico de consulta para não repetir o enriquecimento de posts e detalhes de anúncios já processados; a descrição completa de cada novo anúncio continua sendo consultada antes da aplicação dos filtros editoriais.
8. A listagem consulta os registros e aplica os critérios de exibição.
8. A rotina `vacancies:check-links` verifica em lotes os links de candidatura de vagas ativas ou suspeitas e grava o resultado no histórico de disponibilidade.

## Atribuição de campanhas

O middleware `CaptureUtmParameters`, registrado no grupo web em `bootstrap/app.php`, captura os parâmetros `utm_source`, `utm_medium`, `utm_campaign`, `utm_id`, `utm_content`, `utm_term` e `utm_source_platform`. A primeira atribuição válida é preservada na sessão durante a navegação; valores não textuais, vazios ou com marcação HTML são descartados, e cada valor é limitado a 100 caracteres. A integração com o Google Analytics 4 utiliza esses dados somente quando a pessoa visitante autoriza cookies não essenciais.

## Configuração

- `config/vacancy_sources.php`: fontes e parâmetros de integração.
- `config/geo_regions.php`: ajustes territoriais manuais.
- `config/location_guard.php`: parâmetros de proteção da localização.
- `config/network_security.php`: política de reputação de rede.
- `.env.example`: referência das variáveis de ambiente.
