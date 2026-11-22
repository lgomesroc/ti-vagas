# Automação e Testes

## Agendamento Laravel

As rotinas agendadas são declaradas em `routes/console.php`:

- `vacancies:collect`: consulta as fontes registradas na fábrica a cada seis horas, no fuso `America/Sao_Paulo`. A Gupy global não faz parte desse comando.
- `linkedin:collect-posts --mode=bot`: executa diariamente às 00h e 12h.
- `linkedin:collect-posts --mode=duckduckgo`: executa diariamente às 06h e 18h.
- `network:update-lists`: atualiza as listas de reputação de rede a cada seis horas.
- `vacancies:check-links --limit=500`: verifica até 500 links de candidatura a cada seis horas.
- `vacancies:prune-expired`: executa às segundas-feiras às 03h, em semanas ISO pares.

As tarefas usam `withoutOverlapping()` para evitar execuções simultâneas da mesma rotina.

## GitHub Actions

Os workflows versionados em `.github/workflows` na branch `developer` são:

| Workflow | Responsabilidade |
|---|---|
| `ci.yml` | Valida Composer, sintaxe PHP, testes automatizados e compilação do frontend em push e pull request para `developer` e `main`. |
| `gupy.yml` | Executa exclusivamente `php artisan vacancies:collect-gupy`, a coleta global da Gupy, às 00h, 06h, 12h e 18h. |
| `vacancies.yml` | Executa o agendador Laravel, permite disparo manual da coleta geral e processa a fila `vacancies`. |
| `linkedin-jobs.yml` | Coleta vagas públicas do LinkedIn Jobs e processa a fila `linkedin`; possui execução agendada e manual. |
| `linkedin-posts.yml` | Executa a coleta e validação de publicações de recrutamento do LinkedIn. |
| `linkedin-secondary-search.yml` | Executa a pesquisa da conta secundária do LinkedIn, com sessão própria fornecida por secret. |
| `linkedin-posts-ci.yml` | Valida sintaxe, testes Node dos coletores LinkedIn, sintaxe PHP e testes de integração. |

Os workflows de coleta utilizam secrets para credenciais e conexão ao PostgreSQL. Não coloque URLs de banco, chaves ou estado de sessão em arquivos versionados.

### Concorrência de operações no banco

O workflow `vacancies.yml` usa o grupo de concorrência `ti-vagas-database-operations`, com `cancel-in-progress: false`. Operações que compartilham esse grupo aguardam umas pelas outras em vez de executar simultaneamente. Workflows com grupos independentes não são automaticamente serializados por essa configuração.

## Banco de testes

Os testes automatizados não utilizam o banco de produção:

- **Execução local:** o `phpunit.xml` força a conexão PostgreSQL e o banco exclusivo `ti_vagas_test`. As credenciais locais ficam em `.env.testing`, criado a partir de `.env.testing.example`.
- **GitHub Actions:** os jobs de teste iniciam um serviço PostgreSQL descartável (`postgres:18`) com banco `ti_vagas_test`. A execução não depende do Neon nem dos dados reais.

## Comandos úteis

```bash
php artisan test
php artisan vacancies:collect
php artisan vacancies:collect-gupy
php artisan vacancies:check-links --limit=500
php artisan vacancies:prune-expired
php artisan network:update-lists
php artisan network:check <ip>
php artisan linkedin:collect-jobs
php artisan linkedin:collect-jobs --sync
php artisan linkedin:collect-posts
```

A opção `--sync` executa a coleta de LinkedIn Jobs imediatamente, sem fila. Na coleta normal, o comando despacha um job para a fila `linkedin`.

## Tratamento de HTTP 429 no LinkedIn Jobs

O coletor de LinkedIn Jobs respeita o limite informado pelo serviço externo. As requisições são espaçadas; quando recebe HTTP 429, aplica backoff exponencial e respeita `Retry-After`. Se a limitação persistir, o script encerra com um código específico, sem importar os resultados daquela execução. O Laravel converte a situação em `SourceRateLimitedException` e o job é reagendado com atraso crescente. Não há rotação de IP, proxy ou troca de identidade para contornar o limite.

Os parâmetros são configuráveis por variáveis `LINKEDIN_JOBS_*`; consulte `docs/ColetaPublicacoesLinkedIn.md` e `scripts/linkedin/jobs.mjs`.

## Organização dos testes

- `tests/Feature`: testes de integração e comportamento HTTP, fontes, importação, regionalização, localização, reputação de rede, atribuição UTM e verificação de links.
- `tests/Unit`: testes unitários de regras e serviços.
- `tests/Concerns`: recursos compartilhados pelos testes.
- `scripts/linkedin`: testes Node e scripts auxiliares dos coletores LinkedIn.

## Critério de manutenção

Ao alterar uma regra de negócio, atualize ou acrescente testes que cubram o comportamento esperado e os casos-limite. A documentação descreve o comportamento observado no código; a execução mais recente do CI e a suíte local são as referências para validar regressões.
