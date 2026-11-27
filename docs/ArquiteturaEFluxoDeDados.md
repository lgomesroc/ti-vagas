# Arquitetura e Fluxo de Dados

## Visão técnica

A aplicação utiliza PHP 8.3 e Laravel 13, PostgreSQL como banco relacional, Blade para renderização das páginas e Vite/Tailwind CSS para os recursos de interface.

O código segue a organização convencional do Laravel, separando camada HTTP, modelos, serviços de domínio, comandos, jobs e adaptadores de fontes externas.

## Camada HTTP

- `routes/web.php`: rotas de boas-vindas, aceite de termos e cookies, listagem, detalhes e atualização/desativação da localização.
- `app/Http/Controllers`: recebe requisições e coordena as respostas.
- `app/Http/Middleware/EnsureWelcomeAccepted.php`: verifica o aceite dos termos, mantém o modo inicial de localização e controla o consentimento de cookies não essenciais.
- `app/Http/Middleware/RejectLocationOverrides.php`: rejeita tentativas de substituir a localização validada por parâmetros manipulados.
- `app/Http/Middleware/EvaluateNetworkReputation.php`: aplica a política de reputação de rede.
- `app/Http/Middleware/CaptureUtmParameters.php`: registra a primeira atribuição de campanha válida na sessão.
- `app/Http/Middleware/ForceHttps.php`: força HTTPS conforme a configuração do ambiente.
- `resources/views`: páginas Blade e componentes visuais.

## Domínio de vagas

- `app/Models/Vacancy.php`: representa anúncios importados e seu estado de disponibilidade.
- `app/Models/VacancyAvailabilityCheck.php`: representa uma verificação individual de link de candidatura.
- `app/Models/LinkedInJobScan.php`: mantém o histórico de IDs do LinkedIn Jobs que receberam consulta detalhada.
- `app/Models/LinkedInPostCandidate.php`: mantém candidatos identificados em publicações de recrutamento.
- `app/Services/VacancyImporter.php`: normaliza, valida, deduplica e importa anúncios.
- `app/Services/VacancyEditorialFilter.php`: aplica critérios editoriais de área, senioridade e experiência.
- `app/Services/VacancyAvailabilityDetector.php`: identifica sinais de encerramento nos dados recebidos das fontes.
- `app/Services/VacancyLinkChecker.php`: consulta URLs originais, interpreta respostas HTTP e registra histórico.
- `app/Services/VacancyLocationFilter.php`: aplica critérios de localização e modalidade.
- `app/Services/VacancySourceFactory.php`: instancia os adaptadores habilitados e os coletores globais.
- `app/Sources`: implementações de plataformas ATS, páginas de carreiras e fontes públicas.
- `app/Jobs/FetchVacancySourceJob.php`: executa uma fonte em fila.
- `app/Jobs/FetchLinkedInJobsJob.php`: executa a coleta de anúncios do LinkedIn Jobs.

## Localização e geografia

A localização do visitante é obtida pelo navegador e enviada ao servidor para validação. O servidor resolve coordenadas para município e região, utilizando os serviços em `app/Services/Geo`, o diretório de municípios e as configurações em `config/geo_regions.php` e `config/location_guard.php`.

A sessão começa no modo limitado, que permite somente vagas remotas nacionais. O modo regional só é habilitado após a validação da posição. A região não é liberada por estimativa de IP nem por parâmetros fornecidos livremente na URL ou no corpo da requisição.

A base municipal de coordenadas está em `database/data/municipios.csv`; os dados oficiais da DTB 2025 utilizados na importação estão em `ibge/extraido/`.

## Persistência

As migrations em `database/migrations` definem a estrutura do banco. Entre as tabelas relevantes estão vagas, municípios, estado de importação geográfica, verificações de disponibilidade, candidatos de publicações do LinkedIn e histórico de consultas de LinkedIn Jobs.

O PostgreSQL também pode armazenar filas e cache, conforme a configuração do ambiente. As variáveis de conexão e os drivers são definidos em `config/database.php`, `config/queue.php`, `config/cache.php` e nos arquivos de ambiente.

## Fluxo de coleta e importação

1. O scheduler ou um workflow do GitHub Actions inicia o comando de coleta.
2. `VacancySourceFactory` instancia as fontes habilitadas em `config/vacancy_sources.php`.
3. `vacancies:collect` despacha um `FetchVacancySourceJob` por fonte para a fila `vacancies`; com `--sync`, executa diretamente.
4. Cada adaptador consulta sua plataforma, normaliza os dados disponíveis e retorna anúncios no contrato comum.
5. O importador verifica duplicidade, elegibilidade editorial, janela de publicação e critérios de localização.
6. Os anúncios válidos são inseridos ou atualizados, preservando a data original de publicação quando fornecida.
7. A listagem consulta os registros e aplica os critérios de exibição da região e da modalidade.

### Coletores globais e dedicados

- **Gupy:** `GupyGlobalSource`, executado pelo comando `vacancies:collect-gupy` e workflow próprio.
- **InHire:** `InhireGlobalSource`, executado pela coleta geral e também isoladamente por `vacancies:collect-inhire`. A descoberta combina tenants conhecidos com identificadores encontrados em dados já armazenados.
- **Abler:** `AblerSource`, com automação de navegador em `scripts/abler/collect.mjs` e workflow próprio.
- **ProgramaThor:** adaptador `ProgramathorSource`, com janela inicial configurada de 60 dias e limites de paginação/consulta.
- **NortJobs:** adaptador `NortJobsSource`, com diagnóstico de cartões, detalhes, elegibilidade e localização.
- **LinkedIn Jobs:** fluxo separado, com job de fila, script Node/Playwright e histórico de consultas detalhadas.
- **Publicações de recrutamento do LinkedIn:** descoberta, enriquecimento, validação editorial e publicação automática dos candidatos aprovados.

A existência de uma integração no código não garante que a fonte externa esteja acessível em todas as execuções nem que todos os anúncios sejam encontrados.

## Verificação de disponibilidade

A rotina `vacancies:check-links` consulta em lotes os links originais de candidatura de vagas ativas ou suspeitas. O resultado atual é armazenado na vaga e cada tentativa é registrada em `vacancy_availability_checks`. Respostas inconclusivas são tratadas como suspeitas, não como encerramento definitivo.

## Atribuição e consentimento

O middleware `CaptureUtmParameters`, registrado no grupo web em `bootstrap/app.php`, captura parâmetros UTM válidos e preserva a primeira atribuição na sessão. Google Analytics 4 e Google AdSense só são carregados quando a pessoa visitante autoriza cookies não essenciais.

## Configuração

- `config/vacancy_sources.php`: fontes e parâmetros de integração.
- `config/linkedin.php`: comportamento dos coletores LinkedIn.
- `config/geo_regions.php`: regiões e ajustes territoriais.
- `config/location_guard.php`: validação e proteção da localização.
- `config/network_security.php`: política de reputação de rede.
- `.env.example`: referência das variáveis de ambiente.
