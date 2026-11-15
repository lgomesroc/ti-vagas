# Arquitetura e Fluxo de Dados

## Visão técnica

A aplicação utiliza PHP 8.3 e Laravel 13, com PostgreSQL como banco de dados, Blade para renderização das páginas e Vite/Tailwind CSS para os recursos de interface.

O código segue a organização convencional do Laravel, separando controladores HTTP, modelos, serviços de domínio, comandos de console, jobs e integrações externas.

## Camada HTTP

- `routes/web.php`: declara as rotas de boas-vindas, aceite, listagem, detalhes e atualização de localização.
- `app/Http/Controllers`: recebe as requisições e coordena a resposta.
- `app/Http/Middleware`: aplica verificações de aceite, reputação de rede e proteção contra parâmetros de localização adulterados.
- `resources/views`: contém as páginas Blade e seus componentes visuais.

## Domínio de vagas

- `app/Models/Vacancy.php`: representa os anúncios armazenados.
- `app/Services/VacancyImporter.php`: normaliza e importa os anúncios recebidos.
- `app/Services/VacancyAvailabilityDetector.php`: identifica sinais de encerramento ou indisponibilidade.
- `app/Services/VacancyLocationFilter.php`: aplica os critérios de localização e modalidade.
- `app/Services/VacancySourceFactory.php`: instancia as fontes configuradas.
- `app/Sources`: contém os adaptadores das plataformas de recrutamento.
- `app/Jobs/FetchVacancySourceJob.php`: executa a consulta de uma fonte em fila.

## Persistência

As migrations em `database/migrations` definem a estrutura do banco. Os modelos Eloquent fazem a representação das entidades utilizadas pela aplicação. Dados territoriais auxiliares ficam em `database/data/municipios.csv`.

## Fluxo resumido de coleta

1. O comando `vacancies:collect` identifica as fontes habilitadas.
2. A fábrica cria os adaptadores correspondentes.
3. Jobs consultam as fontes e entregam os resultados ao importador.
4. O importador normaliza os campos, verifica duplicidade, elegibilidade e disponibilidade.
5. Os registros válidos são inseridos ou atualizados no banco, preservando a data original de publicação quando aplicável.
6. A listagem consulta os registros e aplica os critérios de exibição.

## Configuração

- `config/vacancy_sources.php`: fontes e parâmetros de integração.
- `config/geo_regions.php`: ajustes territoriais manuais.
- `config/location_guard.php`: parâmetros de proteção da localização.
- `config/network_security.php`: política de reputação de rede.
- `.env.example`: referência das variáveis de ambiente.
