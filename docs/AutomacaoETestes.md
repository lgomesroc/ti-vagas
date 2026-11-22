# Automação e Testes

A automação do projeto é dividida entre o agendador Laravel, jobs de fila e GitHub Actions. Os workflows possuem checkouts explícitos: algumas rotinas usam `main`, enquanto outras executam o código de `developer`. Essa distinção é operacionalmente importante e está detalhada em [Workflows do GitHub Actions](WorkflowsGitHubActions.md).

## Agendamento Laravel

As tarefas abaixo estão declaradas em `routes/console.php` e são disparadas pelo workflow `vacancies.yml`, que faz checkout de `main` e executa `php artisan schedule:run`.

| Comando | Frequência | Responsabilidade |
|---|---|---|
| `vacancies:collect` | A cada 6 horas | Despacha a coleta das fontes registradas na fábrica, exceto Gupy global |
| `linkedin:collect-posts --mode=bot` | Diariamente às 00h e 12h | Pesquisa via conta secundária |
| `linkedin:collect-posts --mode=duckduckgo` | Diariamente às 06h e 18h | Pesquisa pública via DuckDuckGo |
| `network:update-lists` | A cada 6 horas | Atualiza listas de reputação de rede |
| `vacancies:check-links --limit=500` | A cada 6 horas | Verifica links de candidatura |
| `vacancies:prune-expired` | Segunda-feira às 03h, semanas ISO pares | Remove vagas expiradas conforme a política de retenção |

As tarefas usam `withoutOverlapping()`. Isso evita sobreposição da mesma tarefa Laravel, mas não equivale a um bloqueio global entre todos os workflows do GitHub Actions.

A Gupy global é executada separadamente por `vacancies:collect-gupy`, no workflow dedicado `gupy.yml`.

## Workflows do GitHub Actions

A lista completa, com branch de checkout, cron, secrets, jobs, concorrência, serviços externos e comandos executados, está em [Workflows do GitHub Actions](WorkflowsGitHubActions.md).

Na branch `developer` estão os seguintes arquivos:
- `ci.yml`: CI geral PHP, PostgreSQL de teste e build frontend;
- `gupy.yml`: coleta global Gupy, fazendo checkout de `main`;
- `linkedin-jobs.yml`: coleta LinkedIn Jobs, fazendo checkout de `developer`;
- `linkedin-posts-ci.yml`: testes de integração LinkedIn;
- `linkedin-posts.yml`: coleta pública de publicações, usando a referência do evento;
- `linkedin-secondary-search.yml`: coleta via conta secundária, fazendo checkout de `developer`;
- `vacancies.yml`: scheduler e coleta geral, fazendo checkout de `main`.

O workflow `database-failover.yml` está **somente na branch `main`**. Ele sincroniza Neon e Prisma e altera a variável `DB_URL` no Render. Não aparece na árvore de `developer`.

## Filas

- A fila `vacancies` processa jobs `FetchVacancySourceJob`, com três tentativas e backoff de 30 e 120 segundos.
- A fila `linkedin` processa `FetchLinkedInJobsJob`. O job tem unicidade, middleware contra sobreposição e tratamento específico de HTTP 429.
- A coleta global da Gupy é executada pelo comando dedicado e não é despachada como uma fonte individual na fila geral.

No workflow `vacancies.yml`, o worker processa a fila `vacancies` em ciclos, consultando `vacancies:queue-size` entre os ciclos para continuar quando há jobs pendentes ou em backoff. No workflow `linkedin-jobs.yml`, a fila `linkedin` é processada após o despacho do comando.

## Banco de testes

Os testes automatizados não utilizam o banco de produção:

- **Local:** o `phpunit.xml` configura PostgreSQL e o banco exclusivo `ti_vagas_test`. As credenciais ficam no arquivo local `.env.testing`, criado a partir de `.env.testing.example`.
- **GitHub Actions:** os jobs de CI iniciam um serviço PostgreSQL 18 descartável com o banco `ti_vagas_test`.

## Comandos úteis

```bash
php artisan test
php artisan vacancies:collect
php artisan vacancies:collect --sync
php artisan vacancies:collect-gupy
php artisan vacancies:check-links --limit=500
php artisan vacancies:prune-expired
php artisan network:update-lists
php artisan network:check <ip>
php artisan linkedin:collect-jobs
php artisan linkedin:collect-jobs --sync
php artisan linkedin:collect-posts
php artisan linkedin:collect-posts --mode=duckduckgo
php artisan linkedin:collect-posts --mode=bot
npm run test:linkedin
npm run build
```

A opção `--sync` de `linkedin:collect-jobs` executa a consulta imediatamente, sem despachar job para a fila. Sem essa opção, o comando despacha o job para `linkedin`. O comando geral `vacancies:collect --sync` também permite executar as fontes da fábrica diretamente, sem fila.

## Tratamento de HTTP 429 no LinkedIn Jobs

O coletor aplica intervalo mínimo entre requisições, jitter, backoff exponencial e respeito ao cabeçalho `Retry-After`. Se a limitação persistir, interrompe a execução sem importar resultados parciais. O Laravel reagenda o job com atraso crescente; esgotados os reagendamentos, a próxima execução agendada tentará novamente. Não há rotação de IP, proxy ou troca de identidade para contornar limites.

Os parâmetros são configuráveis por variáveis `LINKEDIN_JOBS_*`. Consulte [Coleta de Oportunidades no LinkedIn](ColetaPublicacoesLinkedIn.md).

## Failover PostgreSQL

O workflow de failover Neon ↔ Prisma está em `main`, não em `developer`. Ele copia o banco ativo para o standby e, quando necessário, atualiza `DB_URL` do serviço Render, solicita deploy e verifica `/up`. A restauração substitui o conteúdo do banco de destino.

**Atenção:** os grupos de concorrência do failover e das coletas são diferentes. Além disso, os workflows de coleta configuram sua própria `DB_URL` com o secret `NEON_DATABASE_URL`, em vez de ler a variável atual do Render. Portanto, a configuração existente não assegura, por si só, que todas as coletas parem de acessar Neon durante um failover. Não interpretar a concorrência configurada como uma trava global. Os detalhes e as condições estão documentados em [Workflows do GitHub Actions](WorkflowsGitHubActions.md).

## Organização dos testes

- `tests/Feature`: testes de integração e comportamento HTTP, fontes, importação, regionalização, localização, reputação de rede, atribuição UTM e verificação de links.
- `tests/Unit`: testes unitários de regras e serviços.
- `tests/Concerns`: recursos compartilhados pelos testes.
- `scripts/linkedin`: scripts Node e testes dos coletores LinkedIn.

Ao alterar regras de negócio, atualizar os testes correspondentes e conferir o resultado do CI da branch e do commit que serão publicados.
