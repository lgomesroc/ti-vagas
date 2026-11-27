# Coleta de Oportunidades no LinkedIn

O projeto mantém fluxos distintos para publicações de recrutamento e anúncios do LinkedIn Jobs. Eles possuem descoberta, processamento, persistência e workflows próprios.

## 1. Publicações de recrutamento

### Descoberta pública

O serviço `LinkedInPostSearchClient` pesquisa URLs públicas de publicações utilizando provedores de busca, incluindo Bing RSS e DuckDuckGo HTML. A janela padrão de busca é configurada em `config/linkedin.php`; o workflow regular utiliza 168 horas.

Os resultados são combinados e deduplicados por URL. A descoberta depende do que os buscadores indexam e não garante acesso a todas as publicações, datas, links ou comentários.

### Conta secundária

A conta secundária utiliza Playwright, Chromium e um estado de sessão fornecido pelo ambiente. O fluxo específico está em `linkedin-secondary-search.yml`, com disparo manual. A sessão também é verificada pelo workflow `linkedin-session-monitor.yml`.

A automação só consulta conteúdo que esteja visível para a conta. Não deve contornar CAPTCHA, desafios, bloqueios ou controles de acesso. Nunca versione cookies, credenciais ou arquivos de sessão.

### Processamento e publicação

Comandos:

```bash
php artisan linkedin:collect-posts
php artisan linkedin:collect-posts --mode=duckduckgo
php artisan linkedin:collect-posts --mode=bot
```

O fluxo:

1. Descobre publicações por um ou mais provedores;
2. Ignora IDs ou URLs já registrados;
3. Reserva o novo post em `linkedin_post_candidates` com estado `processing`;
4. Tenta enriquecer a publicação por meio de `LinkedInPublicPostFetcher`, quando o conteúdo público está acessível;
5. Processa texto, links, e-mails e comentários disponíveis;
6. Aplica critérios editoriais e verificações de duplicidade;
7. Persiste o resultado;
8. O `LinkedInPostAutoPublisher` publica automaticamente os candidatos que forem aprovados pelas regras.

A publicação aprovada pode ocorrer automaticamente. O fluxo não envia candidaturas em nome dos visitantes.

### Regras editoriais relevantes

O processador considera, entre outros critérios:

- Indícios de recrutamento e oportunidade em tecnologia;
- Data e janela de publicação;
- Área, senioridade e experiência compatíveis com o escopo;
- Título identificável da oportunidade;
- Links e informações de candidatura;
- Duplicidade com vagas e candidatos conhecidos;
- Rejeição de posts ou comentários que condicionem informações ou links a palavras-chave, engajamento artificial ou contato privado por DM;
- E-mails pessoais/gratuitos não são aceitos como canal de candidatura nem armazenados como contatos válidos;
- Um link de candidatura válido continua sendo avaliado mesmo quando o post também contém e-mail pessoal;
- Se o e-mail pessoal for o único canal, a publicação é rejeitada.

E-mails corporativos podem ser utilizados como canal de candidatura quando passam pelas regras de validação. Links de candidatura publicados no texto ou em comentários também são avaliados.

### Regra de consulta única

Depois que um post é reservado e submetido ao enriquecimento, o registro permanece em `linkedin_post_candidates`, independentemente do resultado (`pending_publication`, `published`, `duplicate`, `rejected` ou falha registrada). Execuções seguintes não repetem o enriquecimento do mesmo post. Um processo interrompido abruptamente pode deixar o estado `processing`, que não é reconsultado automaticamente.

### Workflows de publicações

| Workflow | Gatilho | Comportamento |
|---|---|---|
| `linkedin-posts.yml` | 00h e 12h, mais manual | Coleta regular; janela de 168 horas; conta secundária desabilitada no YAML |
| `linkedin-secondary-search.yml` | Manual | Restaura e valida sessão secundária e pesquisa publicações no modo bot |
| `linkedin-session-monitor.yml` | Diário às 06h15 de Brasília e manual | Verifica a sessão secundária; cron definido em UTC |
| `linkedin-posts-ci.yml` | Push, pull request e manual | Testes Node e PHP offline; não coleta publicações reais |

O workflow regular `linkedin-posts.yml` está ativo em `main`, com `LINKEDIN_POSTS_ENABLED=true`, e restaura o estado de sessão fornecido pelo secret. A variável `LINKEDIN_SECONDARY_ACCOUNT_ENABLED=false` mantém desabilitada a pesquisa adicional pela conta secundária nesse workflow. O modo bot é executado separadamente.

## 2. LinkedIn Jobs — anúncios de vagas

O LinkedIn Jobs possui coletor separado, implementado em `app/Sources/LinkedInJobsSource.php` e `scripts/linkedin/jobs.mjs`. A consulta utiliza páginas/endpoints públicos acessíveis sem login.

Comandos:

```bash
php artisan linkedin:collect-jobs
php artisan linkedin:collect-jobs --sync
```

A execução padrão despacha `FetchLinkedInJobsJob` para a fila `linkedin`; a opção `--sync` executa a consulta diretamente.

O coletor pesquisa termos de desenvolvimento, suporte e infraestrutura, com janela configurável de até 168 horas. Primeiro reúne e deduplica cartões; depois remove IDs já registrados em `linkedin_job_scans` ou já importados como `linkedin-jobs:*`. Somente IDs ainda não consultados recebem requisição de detalhe. A descrição completa é obtida antes da decisão editorial.

### Workflows LinkedIn Jobs

| Workflow | Empresa/filtro | Agenda em Brasília |
|---|---|---|
| `linkedin-jobs.yml` | Busca geral | 00h30, 06h30, 12h30 e 18h30 |
| `linkedin-jobs-brq.yml` | BRQ | 03h30, 09h30, 15h30 e 21h30 |
| `linkedin-jobs-cast.yml` | CAST Group | 04h30, 10h30, 16h30 e 22h30 |
| `linkedin-jobs-gft.yml` | GFT Technologies | 04h30, 10h30, 16h30 e 22h30 |

Todos aceitam execução manual, fazem checkout de `main`, resolvem o banco ativo do serviço Render e processam a fila `linkedin`. Os fluxos dedicados aplicam filtro de empresa, mas continuam sujeitos aos filtros editoriais e à deduplicação centrais.

### Tratamento de HTTP 429

O coletor respeita limites do serviço externo e não tenta contorná-los por rotação de IP, proxies ou troca de identidade.

| Variável | Padrão | Função |
|---|---:|---|
| `LINKEDIN_JOBS_DELAY_MS` | 2500 | Intervalo mínimo entre requisições |
| `LINKEDIN_JOBS_JITTER_MS` | 500 | Variação adicional do intervalo |
| `LINKEDIN_JOBS_MAX_RETRIES` | 3 | Tentativas após HTTP 429 |
| `LINKEDIN_JOBS_BACKOFF_BASE_MS` | 30000 | Espera inicial do backoff |
| `LINKEDIN_JOBS_BACKOFF_MAX_MS` | 300000 | Limite do backoff no processo |
| `LINKEDIN_JOBS_RESCHEDULE_SECONDS` | 900 | Atraso inicial para reagendar o job |
| `LINKEDIN_JOBS_RESCHEDULE_MAX_SECONDS` | 14400 | Limite do reagendamento |

- O cabeçalho `Retry-After` é respeitado.
- Persistindo o HTTP 429, o script encerra sem importar resultados parciais.
- O Laravel converte a situação em `SourceRateLimitedException` e reagenda o job.
- O job único evita despachos repetidos enquanto uma execução está pendente ou reagendada.
- Respostas HTTP de detalhe são registradas em `linkedin_job_scans`; HTTP 429 e falhas sem resposta não são marcados como consulta concluída.

A descrição completa dos anúncios novos continua sendo consultada antes dos filtros de senioridade, área ou experiência. Não se deve antecipar a decisão editorial somente com base no cartão.

## 3. Limitações operacionais

- Buscadores públicos não indexam necessariamente todas as publicações.
- A sessão secundária pode expirar ou perder acesso.
- Comentários só podem ser analisados quando efetivamente disponibilizados.
- Limites e mudanças do LinkedIn podem interromper coletas.
- Um workflow verde comprova a execução técnica daquele run, não a cobertura integral das vagas publicadas.

Para agenda, concorrência, CI e configuração dos demais workflows, consulte [Workflows do GitHub Actions](WorkflowsGitHubActions.md).
