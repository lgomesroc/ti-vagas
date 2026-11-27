# Automação e Testes

A automação do TI Vagas combina Laravel Scheduler, filas Laravel e GitHub Actions. A branch de referência operacional é `main`. Os workflows versionados, gatilhos, horários, secrets e grupos de concorrência estão detalhados em [Workflows do GitHub Actions](WorkflowsGitHubActions.md).

## Scheduler Laravel

As tarefas estão declaradas em `routes/console.php`. O workflow `vacancies.yml` executa `php artisan schedule:run` nos horários programados.

| Comando | Frequência | Responsabilidade |
|---|---|---|
| `vacancies:collect` | A cada 6 horas | Despacha as fontes da fábrica para a fila `vacancies` |
| `network:update-lists` | A cada 6 horas | Atualiza listas de reputação de rede |
| `vacancies:check-links --limit=500` | A cada 6 horas | Verifica links de candidatura |
| `vacancies:prune-expired` | Segunda-feira às 03h, em semanas ISO pares | Remove anúncios expirados conforme a política de retenção |

As tarefas usam `withoutOverlapping()`. Esse mecanismo impede sobreposição da mesma tarefa Laravel, mas não substitui os grupos de concorrência dos workflows GitHub.

A Gupy e a InHire também possuem workflows dedicados. A Gupy executa `vacancies:collect-gupy`; a InHire executa `vacancies:collect-inhire`. A coleta geral também inclui o coletor global InHire registrado na fábrica; o workflow isolado permite executá-lo separadamente.

## Filas

- A fila `vacancies` processa `FetchVacancySourceJob`, responsável por executar adaptadores de fontes.
- A fila `linkedin` processa `FetchLinkedInJobsJob`, com unicidade, controle de sobreposição e tratamento específico de HTTP 429.
- A coleta global da Gupy é executada pelo comando dedicado e não é despachada como fonte individual na fila geral.
- A coleta isolada InHire usa lock para impedir duas execuções simultâneas do coletor global.
- Publicações do LinkedIn são registradas em `linkedin_post_candidates` antes do enriquecimento, evitando reprocessar o mesmo post.
- Anúncios do LinkedIn Jobs já consultados são registrados em `linkedin_job_scans`.

O workflow `vacancies.yml` processa a fila `vacancies` em ciclos e consulta `vacancies:queue-size` para reiniciar o worker enquanto houver jobs pendentes ou em backoff. Os workflows de LinkedIn Jobs despacham a coleta e executam um worker para a fila `linkedin`.

## Workflows operacionais em main

| Workflow | Agenda em horário de Brasília | Função |
|---|---|---|
| `vacancies.yml` | 00h, 06h, 12h e 18h; segunda às 03h | Scheduler, coleta geral e fila `vacancies` |
| `gupy.yml` | 00h, 06h, 12h e 18h | Coleta global Gupy |
| `inhire.yml` | 02h, 08h, 14h e 20h | Coleta global InHire isolada |
| `abler.yml` | 01h, 07h, 13h e 19h | Coleta global Abler |
| `linkedin-jobs.yml` | 00h30, 06h30, 12h30 e 18h30 | Busca geral LinkedIn Jobs |
| `linkedin-jobs-brq.yml` | 03h30, 09h30, 15h30 e 21h30 | Busca LinkedIn Jobs dedicada à BRQ |
| `linkedin-jobs-cast.yml` | 04h30, 10h30, 16h30 e 22h30 | Busca LinkedIn Jobs dedicada à CAST Group |
| `linkedin-jobs-gft.yml` | 04h30, 10h30, 16h30 e 22h30 | Busca LinkedIn Jobs dedicada à GFT Technologies |
| `linkedin-posts.yml` | 00h e 12h | Publicações públicas de recrutamento no LinkedIn |
| `linkedin-session-monitor.yml` | 06h15 | Verifica a validade da sessão secundária; cron configurado em UTC |
| `database-failover.yml` | A cada 5 minutos | Verifica a saúde e sincronização dos bancos Neon/Prisma |

Os horários com fuso explícito utilizam `America/Sao_Paulo`. O monitor de sessão e o failover usam cron sem campo `timezone`, portanto o GitHub interpreta esses horários em UTC. Workflows agendados podem iniciar com atraso; o cron não garante execução exatamente no minuto programado.

Os horários de CAST e GFT coincidem. Os workflows compartilham o grupo de concorrência de operações do banco, portanto suas execuções são serializadas quando disputam esse grupo.

## CI e testes

- `ci.yml`: executa em push e pull request para `main`; valida Composer e sintaxe PHP, executa a suíte PHP no PostgreSQL descartável e compila/testa o frontend.
- `linkedin-posts-ci.yml`: executa em push e pull request para `main`, `developer` e `feature/linkedin-recruiter-posts`, além de disparo manual. Executa testes Node offline e testes PHP de integração LinkedIn sem sessão autenticada.
- `linkedin-posts.yml` é coletor operacional, não é o workflow de CI.
- `linkedin-secondary-search.yml` é manual e executa validação/pesquisa com sessão secundária.
- `linkedin-session-monitor.yml` verifica a sessão por agendamento e também pode ser disparado manualmente.

## Banco de testes

Os testes não devem acessar o banco operacional.

- **Local:** `phpunit.xml` força PostgreSQL e banco `ti_vagas_test`; as credenciais locais ficam em `.env.testing`, criado a partir de `.env.testing.example`.
- **GitHub Actions:** os jobs de CI iniciam PostgreSQL descartável com o banco `ti_vagas_test`.

## Comandos úteis

```bash
php artisan test
php artisan vacancies:collect
php artisan vacancies:collect --sync
php artisan vacancies:collect-gupy
php artisan vacancies:collect-inhire
php artisan vacancies:collect-source abler-global
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
npm run test:pwa
npm run build
```

Os comandos de coleta podem gravar no banco configurado. Para executar localmente, confirme primeiro as variáveis de ambiente e o destino da conexão.

## Tratamento de HTTP 429 no LinkedIn Jobs

O coletor aplica intervalo mínimo entre requisições, jitter, backoff exponencial e respeito ao cabeçalho `Retry-After`. Persistindo a limitação, interrompe a execução sem importar resultados parciais. O Laravel reagenda o job com atraso crescente. Não há rotação de IP, proxy ou troca de identidade para contornar limites.

## Failover PostgreSQL

O workflow `database-failover.yml` está em `main`. Ele sincroniza Neon e Prisma e pode alterar `DB_URL` no serviço Render. A operação substitui os dados do banco de destino durante a sincronização; não é uma cópia incremental sem impacto.

Os workflows operacionais de coleta que compartilham o grupo `ti-vagas-database-operations` são serializados com o failover. Os fluxos de LinkedIn Posts, conta secundária, monitor de sessão e CI possuem grupos independentes. Não interprete o grupo de concorrência como bloqueio global de toda e qualquer conexão ao banco.

## Organização dos testes

- `tests/Feature`: comportamento HTTP, fontes, importação, localização, segurança, consentimento e disponibilidade.
- `tests/Unit`: filtros, normalização, deduplicação, políticas e regras de domínio.
- `scripts/linkedin/*.test.mjs`: testes Node para scripts de coleta, sessão e limites.

Os testes dos coletores LinkedIn utilizam endpoints locais simulados. Eles não comprovam autenticação ou disponibilidade do LinkedIn em produção.
