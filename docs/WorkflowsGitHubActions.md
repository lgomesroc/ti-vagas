# Workflows do GitHub Actions

Inventário conferido diretamente nos arquivos versionados em `.github/workflows/` da branch `main`. Os horários abaixo estão apresentados em horário de Brasília quando o YAML declara `America/Sao_Paulo`. Quando não há `timezone`, o GitHub interpreta o cron em UTC.

## Inventário

| Arquivo | Gatilho/agenda | Checkout | Finalidade |
|---|---|---|---|
| `abler.yml` | 01h, 07h, 13h, 19h; manual | `main` | Coleta global Abler |
| `ci.yml` | Push e pull request em `main` | Branch do evento | CI PHP, PostgreSQL de teste, Node e build frontend |
| `database-failover.yml` | A cada 5 minutos; manual | Não faz checkout | Sincronização Neon/Prisma e failover do Render |
| `gupy.yml` | 00h, 06h, 12h, 18h; manual | `main` | Coleta global Gupy |
| `inhire.yml` | 02h, 08h, 14h, 20h; manual | `main` | Coleta global InHire isolada |
| `linkedin-jobs.yml` | 00h30, 06h30, 12h30, 18h30; manual | `main` | LinkedIn Jobs geral |
| `linkedin-jobs-brq.yml` | 03h30, 09h30, 15h30, 21h30; manual | `main` | LinkedIn Jobs BRQ |
| `linkedin-jobs-cast.yml` | 04h30, 10h30, 16h30, 22h30; manual | `main` | LinkedIn Jobs CAST Group |
| `linkedin-jobs-gft.yml` | 04h30, 10h30, 16h30, 22h30; manual | `main` | LinkedIn Jobs GFT Technologies |
| `linkedin-posts-ci.yml` | Push, pull request e manual | Branch do evento | CI offline dos coletores LinkedIn |
| `linkedin-posts.yml` | 00h, 12h; manual | `github.ref` | Coleta de publicações de recrutamento |
| `linkedin-secondary-search.yml` | Manual | Branch padrão do evento | Pesquisa com conta secundária |
| `linkedin-session-monitor.yml` | 09h15 UTC diariamente; manual | `main` | Verificação da sessão secundária |
| `vacancies.yml` | 00h, 06h, 12h, 18h; segunda 03h; manual | `main` | Scheduler, coleta geral e fila de vagas |

Os workflows com timezone explícito utilizam `America/Sao_Paulo`. O cron de `linkedin-session-monitor.yml` não declara timezone e corresponde a 06h15 em Brasília (UTC−3). O cron de failover também não declara timezone, mas roda a cada cinco minutos independentemente da diferença de fuso.

O GitHub Actions não garante início exato no minuto do cron. Execuções agendadas podem atrasar em períodos de maior demanda.

## 1. CI principal — `ci.yml`

Dispara em push e pull request direcionados a `main`. Possui dois jobs:

**PHP**
- Ubuntu, PHP 8.3 e PostgreSQL 18 descartável;
- banco `ti_vagas_test`;
- validação do Composer;
- instalação das dependências;
- verificação de sintaxe PHP;
- execução de `php artisan test --compact --display-warnings --testsuite=CI`.

**Frontend e scripts**
- Node.js 22;
- instalação com `npm ci`;
- instalação de Chromium para os testes locais simulados;
- verificação de sintaxe dos scripts LinkedIn;
- execução de `npm run test:linkedin`;
- compilação com `npm run build`.

O CI não utiliza o banco de produção nem exige sessão autenticada do LinkedIn.

## 2. Failover PostgreSQL — `database-failover.yml`

Está presente em `main`, com agenda a cada cinco minutos e disparo manual. Utiliza os secrets `NEON_DATABASE_URL`, `PRISMA_DATABASE_URL`, `RENDER_API_KEY` e `RENDER_SERVICE_ID`.

O workflow identifica o banco ativo pela variável `DB_URL` do serviço Render, verifica a disponibilidade e sincroniza o standby. Se necessário, altera `DB_URL`, solicita deploy e verifica o endpoint `/up`.

A sincronização utiliza dump/restauração PostgreSQL. O conteúdo do banco de destino é substituído; portanto, a operação não deve ser descrita como cópia incremental sem impacto.

## 3. Coleta geral — `vacancies.yml`

Executa `php artisan schedule:run` quatro vezes ao dia e também às segundas-feiras às 03h, conforme os crons configurados em `America/Sao_Paulo`.

No disparo manual, executa também:
- `php artisan vacancies:collect`;
- `php artisan network:update-lists` (etapa configurada com `continue-on-error`);
- processamento da fila `vacancies`.

O worker é reiniciado enquanto `vacancies:queue-size` indicar jobs pendentes ou em backoff. O workflow desabilita explicitamente as coletas LinkedIn no ambiente da automação geral.

## 4. Coletores globais

### Gupy — `gupy.yml`

Executa `php artisan vacancies:collect-gupy`, que utiliza `GupyGlobalSource` e a API global pública. Não executa uma consulta individual por empresa. O workflow resolve a `DB_URL` atualmente ativa no Render antes de gravar.

### InHire — `inhire.yml`

Executa `php artisan vacancies:collect-inhire`, que instancia `InhireGlobalSource`, consulta tenants conhecidos/descobertos e importa os anúncios sob a identidade global. O workflow resolve a `DB_URL` ativa do Render e compartilha o grupo de concorrência das operações de banco.

### Abler — `abler.yml`

Executa exclusivamente `php artisan vacancies:collect-source abler-global`. Instala Node.js, dependências npm e Chromium para a automação de navegador. Resolve a `DB_URL` ativa do Render antes da coleta.

## 5. LinkedIn Jobs

Os workflows gerais e dedicados executam `php artisan linkedin:collect-jobs`, aplicam as migrações pendentes, despacham o job e mantêm um worker da fila `linkedin` para processar a coleta e reagendamentos por HTTP 429.

- `linkedin-jobs.yml`: busca geral.
- `linkedin-jobs-brq.yml`: filtro de empresa BRQ.
- `linkedin-jobs-cast.yml`: filtro de empresa CAST.
- `linkedin-jobs-gft.yml`: filtro de empresa GFT.

Os quatro workflows fazem checkout de `main`, resolvem a `DB_URL` ativa do Render e compartilham `ti-vagas-database-operations`. CAST e GFT têm cron no mesmo minuto; o grupo serializa as operações de banco.

## 6. Publicações de recrutamento

### `linkedin-posts.yml`

Coleta regular, com agenda às 00h e 12h de Brasília e disparo manual. Usa `LINKEDIN_POSTS_ENABLED=true`, janela de 168 horas e restaura o estado de sessão fornecido por `LINKEDIN_STORAGE_STATE_B64`. A variável `LINKEDIN_SECONDARY_ACCOUNT_ENABLED=false` mantém desabilitada a busca adicional pela conta secundária nesse fluxo.

O workflow faz checkout da referência do evento (`github.ref`), resolve a `DB_URL` do Render e executa `php artisan linkedin:collect-posts`.

### `linkedin-secondary-search.yml`

É disparado manualmente. Restaura e valida a sessão secundária, verifica a autenticação no feed e executa pesquisa de publicações. Não cadastra resultados no banco operacional por esse workflow; sua finalidade é pesquisar e validar a sessão.

### `linkedin-session-monitor.yml`

Executa diariamente às 09h15 UTC (06h15 em Brasília) e manualmente. Restaura o estado de sessão do secret e verifica se a conta continua autenticada.

### `linkedin-posts-ci.yml`

Dispara em push e pull request para `main`, `developer` e `feature/linkedin-recruiter-posts`, além de execução manual. Testa scripts Node com endpoints locais simulados e testes PHP de integração LinkedIn em PostgreSQL descartável. Não utiliza sessão autenticada nem consulta o LinkedIn real.

## 7. Secrets e variáveis operacionais

Os nomes efetivamente utilizados pelos workflows incluem, conforme cada fluxo:

- `APP_KEY`;
- `RENDER_API_KEY`;
- `RENDER_SERVICE_ID`;
- `LINKEDIN_STORAGE_STATE_B64`;
- `NEON_DATABASE_URL`;
- `PRISMA_DATABASE_URL`.

Nem todo workflow utiliza todos os secrets. Consulte o YAML específico antes de configurar ou rotacionar credenciais. Nunca registre os valores dos secrets em logs, documentação ou arquivos versionados.

## 8. Concorrência

Os workflows de operação de banco — failover, coleta geral, Gupy, InHire, Abler e LinkedIn Jobs (geral e filtros por empresa) — compartilham o grupo `ti-vagas-database-operations`, com `cancel-in-progress: false`.

Outros fluxos usam grupos independentes:
- Publicações: `ti-vagas-linkedin-posts`;
- CI LinkedIn: `linkedin-integration-ci-${github.ref}`;
- Conta secundária: `linkedin-secondary-search`;
- Monitor da sessão: `linkedin-secondary-session-monitor`.

A concorrência serializa apenas workflows que compartilham o mesmo grupo. Não representa um bloqueio global de qualquer processo externo que acesse o banco.

## 9. Diagnóstico de execução agendada

Quando um workflow não iniciar no horário esperado:

1. Confirme que o arquivo está na branch padrão e que o cron está no YAML dessa branch.
2. Verifique a aba **Actions** e o histórico do workflow, distinguindo ausência de execução, execução na fila, execução atrasada e falha.
3. Confira se o workflow não foi desativado por inatividade do repositório.
4. Confira se o cron usa `timezone: America/Sao_Paulo` ou UTC.
5. Verifique se outra execução está ocupando o mesmo grupo de concorrência.
6. Abra o run e identifique a etapa que falhou; uma falha no início não significa que o cron não disparou.

Agendamento não equivale a garantia de pontualidade ou sucesso. A conclusão deve ser confirmada no histórico de execuções.
