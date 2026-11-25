# GitHub Actions e Workflows

Referência dos workflows versionados em `.github/workflows`. A branch que contém o YAML e a branch usada pelo `actions/checkout` podem ser diferentes; por isso, ambas são informadas.

## Inventário por branch

| Arquivo | Presença | Checkout | Agenda / evento | Função |
|---|---|---|---|---|
| `abler.yml` | main | main | 01h, 07h, 13h, 19h e manual | Coleta exclusivamente a fonte `abler-global` |
| `ci.yml` | main e developer | Branch do evento | Push e pull request para main/developer | CI PHP, testes PostgreSQL e build frontend |
| `database-failover.yml` | **Somente main** | Não faz checkout | A cada 5 min e manual | Sincroniza Neon/Prisma e altera DB_URL do Render |
| `gupy.yml` | main e developer | main | 00h, 06h, 12h, 18h e manual | Coleta global Gupy |
| `linkedin-jobs.yml` | main | main | 00h30, 06h30, 12h30, 18h30 e manual | Coleta LinkedIn Jobs |
| `linkedin-posts-ci.yml` | main | Branch do evento | Push, pull request em main/developer/feature e manual | CI offline de integração LinkedIn |
| `linkedin-posts.yml` | main e developer | github.ref | 00h, 12h e manual | Publicações de recrutamento, sem sessão secundária |
| `linkedin-secondary-search.yml` | main e developer | developer | 06h, 18h e manual | Publicações via conta secundária autenticada |
| `vacancies.yml` | main e developer | main | 00h, 06h, 12h, 18h; segunda 03h; manual | Scheduler Laravel, coleta geral e fila vacancies |

Os agendamentos usam `America/Sao_Paulo`. O GitHub pode iniciar execuções com pequeno atraso em relação ao horário configurado.

## 1. Coleta isolada Abler — `abler.yml`

Disponível em `main`, com quatro execuções diárias (01h, 07h, 13h e 19h) e disparo manual. Faz checkout explícito de `main`, resolve a `DB_URL` atualmente configurada no serviço Render usando `RENDER_API_KEY` e `RENDER_SERVICE_ID`, configura PHP 8.3 e Node.js 22, instala Composer/npm e Chromium e executa exclusivamente:

```bash
php artisan vacancies:collect-source abler-global
```

Não chama `vacancies:collect`, scheduler, atualização de listas de rede nem workers de fila. Usa `APP_KEY`, `RENDER_API_KEY` e `RENDER_SERVICE_ID` como secrets e grava no banco atualmente ativo do Render; não é um dry run. O grupo `ti-vagas-database-operations` serializa essa gravação com o failover e as coletas que usam o mesmo grupo.

O workflow não corrige bloqueios impostos pela própria API da Abler. Um HTTP 403 como `API_PROXY_DIRECT_ACCESS_BLOCKED` significa que a chamada direta foi recusada pelo proxy da plataforma; a execução manual serve para confirmar o comportamento no runner do GitHub, não para contornar a política de acesso.

## 2. CI principal — `ci.yml`

Dispara em push e pull request direcionados a `developer` ou `main`. Possui dois jobs:

**Validar PHP**
- Ubuntu, PHP 8.3 e PostgreSQL 18 descartável;
- banco de testes `ti_vagas_test`;
- `composer validate --no-check-publish`;
- instalação das dependências Composer;
- `php -l` nos arquivos de `app`, `bootstrap`, `config`, `database` e `routes`;
- `php artisan test --compact`.

**Compilar frontend**
- Node.js 22;
- instalação npm;
- `npm run build`.

O banco é efêmero e isolado. O CI não utiliza Neon, Prisma ou dados de produção.

## 3. Failover PostgreSQL — `database-failover.yml`

Este workflow está **somente na branch main**. É agendado a cada cinco minutos e também aceita execução manual. Roda em container `postgres:18`, com timeout de 30 minutos, e usa a API Render.

Secrets obrigatórios: `NEON_DATABASE_URL`, `PRISMA_DATABASE_URL`, `RENDER_API_KEY` e `RENDER_SERVICE_ID`.

O workflow lê a variável de serviço `DB_URL` do Render e compara seu valor com as duas URLs cadastradas nos secrets. A partir do banco atualmente ativo e da disponibilidade do Neon, executa:

| Condição | Ação |
|---|---|
| Render usa Neon e Neon está saudável | Copia Neon → Prisma para atualizar o standby |
| Render usa Neon e Neon está indisponível, Prisma responde | Troca Render DB_URL para Prisma e solicita deploy |
| Render usa Prisma e Neon recuperou | Copia Prisma → Neon; depois troca Render DB_URL para Neon e solicita deploy |
| Render usa Prisma e Neon continua indisponível | Mantém Prisma ativo |
| DB_URL não corresponde aos secrets ou os bancos necessários não respondem | Interrompe sem alterar a URL |

A cópia usa `pg_dump --format=custom --no-owner --no-privileges` e `pg_restore --exit-on-error --single-transaction --clean --if-exists --no-owner --no-privileges`. O banco de destino é substituído. Após a troca de URL, o workflow solicita um deploy no Render, espera o estado `Live` e verifica o endpoint `/up`.

O token Render precisa conseguir consultar o serviço, ler/escrever variáveis de ambiente e executar deploy. `DB_URL` deve estar definida como variável do próprio serviço Render, pois o workflow procura essa chave na lista de variáveis do serviço.

**Concorrência varia entre branches:** em `main`, `vacancies.yml` também usa `ti-vagas-database-operations`, compartilhando o bloqueio com o failover. Em `developer`, `vacancies.yml` usa `ti-vagas-vacancies`, portanto não compartilha o bloqueio com o failover. Em ambas as branches, `gupy.yml` e os workflows LinkedIn usam grupos próprios e não compartilham o bloqueio com o failover. Portanto, nem mesmo em `main` existe bloqueio global entre failover e todas as coletas. Além disso, os workflows de coleta configuram `DB_URL` diretamente com `NEON_DATABASE_URL`; não consultam a variável `DB_URL` do Render. Assim, mudar o banco usado pela aplicação no Render não redireciona automaticamente os jobs do GitHub Actions. Essa diferença precisa ser considerada na operação do failover.

## 4. Coleta global Gupy — `gupy.yml`

Agenda quatro execuções diárias (00h, 06h, 12h e 18h) e aceita disparo manual. Faz checkout explícito de `main`, configura PHP 8.3, instala Composer e executa:

```bash
php artisan vacancies:collect-gupy
```

Usa `APP_KEY` e `NEON_DATABASE_URL` como secrets. O comando instancia `GupyGlobalSource`, consulta a API global pública da Gupy e importa os resultados. Não há execução individual de cada empresa Gupy nesse fluxo.

Arquivos relacionados:
- `app/Console/Commands/CollectGupyVacancies.php`
- `app/Sources/GupyGlobalSource.php`
- `config/vacancy_sources.php`
- `app/Services/VacancyImporter.php`
- `app/Services/VacancySourceFactory.php`

## 5. Automação geral — `vacancies.yml`

Agenda o Laravel quatro vezes ao dia e às segundas-feiras às 03h; também aceita execução manual. Faz checkout de `main`, instala PHP 8.3 e Composer, e executa `php artisan schedule:run`.

No disparo manual, executa adicionalmente:
- `php artisan vacancies:collect`;
- `php artisan network:update-lists` (etapa com `continue-on-error: true));
- processamento da fila `vacancies`.

O worker usa `--stop-when-empty --tries=3 --timeout=0 --sleep=3`. Depois de cada ciclo, consulta `php artisan vacancies:queue-size`; se ainda houver jobs pendentes, aguarda cinco segundos e reinicia o worker. Isso permite continuar o processamento quando jobs são liberados para backoff.

Arquivos relacionados:
- `routes/console.php`;
- `app/Console/Commands/CollectVacancies.php`;
- `app/Jobs/FetchVacancySourceJob.php`;
- comando `vacancies:queue-size`;
- `config/vacancy_sources.php`.

## 6. LinkedIn Jobs — `linkedin-jobs.yml`

Agenda 00h30, 06h30, 12h30 e 18h30, além de execução manual. Faz checkout explícito de `main`, resolve a `DB_URL` atualmente configurada no serviço Render, configura PHP 8.3 e Node.js 22, instala Composer/npm e Chromium, aplica migrações, executa `php artisan linkedin:collect-jobs` e mantém um worker da fila `linkedin` ativo por até 40 minutos para processar a coleta e reagendamentos por HTTP 429.

Configura `LINKEDIN_JOBS_ENABLED=true` e janela de 168 horas. A conexão usa a `DB_URL` resolvida do serviço Render. O grupo de concorrência é `ti-vagas-database-operations`.

Arquivos relacionados:
- `app/Console/Commands/CollectLinkedInJobs.php`;
- `app/Jobs/FetchLinkedInJobsJob.php`;
- `app/Sources/LinkedInJobsSource.php`;
- `scripts/linkedin/jobs.mjs`;
- `scripts/linkedin/rate-limit.mjs`;
- `config/linkedin.php`.

O tratamento de HTTP 429, variáveis de backoff e reagendamento está detalhado em [Coleta de Oportunidades no LinkedIn](ColetaPublicacoesLinkedIn.md).

## 7. Publicações de recrutamento — `linkedin-posts.yml`

Agenda 00h e 12h, além de execução manual. Faz checkout de `github.ref`, configura PHP 8.3 e Node.js 22, instala Composer e npm, instala Chromium pelo Playwright, aplica migrações e executa `php artisan linkedin:collect-posts` (modo padrão `all`).

O workflow define janela de 72 horas, navegador headless e `LINKEDIN_SECONDARY_ACCOUNT_ENABLED=false`. Portanto, a etapa que restauraria o arquivo de sessão é ignorada; essa execução não usa a sessão autenticada da conta secundária.

Arquivos relacionados:
- `app/Console/Commands/CollectLinkedInPosts.php`;
- `app/Services/LinkedInPostSearchClient.php`;
- `app/Services/LinkedInPublicPostFetcher.php`;
- `app/Services/LinkedInPostCandidateProcessor.php`;
- `app/Services/LinkedInPostAutoPublisher.php`;
- `config/linkedin.php`.

## 8. Conta secundária LinkedIn — `linkedin-secondary-search.yml`

Agenda 06h e 18h, além de execução manual. Faz checkout de `developer`, configura PHP 8.3, Node.js 22 e Chromium, instala dependências e exige o secret `LINKEDIN_STORAGE_STATE_B64`. O conteúdo é decodificado para `.local/linkedin-storage-state.json`, com permissão restrita, e não deve ser versionado.

Aplica migrações e executa:

```bash
php artisan linkedin:collect-posts --mode=bot
```

A conta secundária é habilitada nesse workflow por `LINKEDIN_SECONDARY_ACCOUNT_ENABLED=true`.

Arquivos relacionados:
- `scripts/linkedin/secondary-account.mjs`;
- `app/Services/LinkedInPostSearchClient.php`;
- `app/Services/LinkedInPublicPostFetcher.php`;
- `app/Services/LinkedInPostCandidateProcessor.php`;
- `app/Services/LinkedInPostAutoPublisher.php`;
- `config/linkedin.php`.

## 9. CI específico LinkedIn — `linkedin-posts-ci.yml`

Dispara em push e pull request direcionados a `main`, `developer` e `feature/linkedin-recruiter-posts`, além de execução manual. Executa dois jobs isolados:
- Node.js 22, verificação de sintaxe e testes automatizados offline com endpoints HTTP locais simulados e Chromium;
- PostgreSQL 18 descartável, Composer, sintaxe PHP e suíte PHP `CI`;
- PHP 8.3 e PostgreSQL 18 descartável;
- validação e instalação Composer;
- sintaxe PHP em `app`, `config`, `database` e `tests`;
- `php artisan test --compact`.

## Grupos de concorrência configurados

| Grupo | Workflow |
|---|---|
| `ti-vagas-database-operations` | Failover PostgreSQL, `vacancies.yml`, `linkedin-jobs.yml`, `abler.yml` e `inhire.yml` em `main` |
| `ti-vagas-vacancies` | `vacancies.yml` em `developer` |
| `ti-vagas-gupy-global` | Gupy global |
| `ti-vagas-linkedin-jobs` | LinkedIn Jobs |
| `ti-vagas-linkedin-posts` | Publicações |
| `ti-vagas-linkedin-secondary` | Conta secundária |

Um grupo só serializa execuções que compartilham exatamente o mesmo nome. O CI não participa desses grupos. Em `main`, o failover e a automação geral compartilham grupo; em `developer`, não. Gupy e LinkedIn continuam em grupos independentes nas duas branches. Não interpretar os grupos como trava global de banco.

## Secrets e dados sensíveis

Os workflows referenciam secrets do GitHub Actions; os valores não devem aparecer na documentação, código, issues públicas ou logs.

| Secret | Uso |
|---|---|
| `APP_KEY` | Inicialização da aplicação Laravel nos jobs |
| `NEON_DATABASE_URL` | Banco usado pela coleta isolada Abler e demais coletas que apontam diretamente para Neon |
| `NEON_DATABASE_URL` | Banco acessado diretamente pelas coletas |
| `PRISMA_DATABASE_URL` | Banco standby no failover |
| `RENDER_API_KEY` | Autorização na API Render |
| `RENDER_SERVICE_ID` | Identificação do serviço Render |
| `LINKEDIN_STORAGE_STATE_B64` | Estado de sessão da conta secundária (cookies, não login/senha). Gere com `npm run linkedin:secondary:login` e depois `npm run linkedin:secondary:export` (`-- --gh` envia direto). Refazer quando expirar. |

## Procedimento de manutenção

Ao adicionar, remover ou alterar um workflow:
1. Atualizar este inventário com gatilhos, timezone, checkout, jobs, secrets e grupos de concorrência;
2. Conferir qual branch contém o YAML e qual branch o checkout utiliza;
3. Atualizar `docs/AutomacaoETestes.md` se houver alteração em agendamentos, comandos ou testes;
4. Atualizar `docs/EstruturaDoProjeto.md` quando a árvore mudar;
5. Verificar se operações que acessam ou restauram o banco compartilham uma estratégia de concorrência coerente.
