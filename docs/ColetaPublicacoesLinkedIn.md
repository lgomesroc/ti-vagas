# Coleta de Oportunidades no LinkedIn

O projeto mantém fluxos distintos para publicações de recrutamento e para anúncios do LinkedIn Jobs. Eles não devem ser confundidos: cada fluxo possui fonte, filtros, persistência e automação próprios.

## 1. Publicações de recrutamento

### Descoberta por buscadores públicos

O serviço `LinkedInPostSearchClient` pesquisa URLs públicas de publicações do LinkedIn utilizando:

- Bing RSS;
- DuckDuckGo HTML.

A consulta é limitada a URLs de posts do LinkedIn e às palavras-chave definidas em `config/linkedin.php`. A janela padrão é de 72 horas. Os resultados dos provedores são combinados e deduplicados pela URL. O workflow `linkedin-posts.yml` executa o modo `all` com a conta secundária desabilitada, portanto usa os buscadores públicos; a rotina de conta secundária é executada separadamente por `linkedin-secondary-search.yml`. A existência do workflow e do código não comprova, por si só, que cada execução agendada tenha sido concluída com sucesso.

O modo `duckduckgo` consulta somente DuckDuckGo. O modo `all` combina Bing, DuckDuckGo e, quando habilitada e disponível, a conta secundária. O modo `bot` utiliza a conta secundária.

### Conta secundária e comentários

A conta secundária utiliza um perfil/sessão separado, com Playwright e Chromium. A sessão é fornecida pelo ambiente; não se deve usar a conta pessoal do titular. A automação consulta apenas conteúdo que esteja visível para essa conta e não deve contornar CAPTCHA, desafios, bloqueios ou controles de acesso.

O coletor pode expandir comentários visíveis e procurar neles links e informações públicas úteis para candidatura. Antes de expandir comentários, a conta secundária compara a publicação com os IDs e URLs já armazenados e ignora as conhecidas. A presença de comentário no banco depende do que foi efetivamente retornado pela sessão e pela página naquele momento; não há garantia de acesso a todos os comentários.

A configuração de perfil, estado de sessão, timeout e habilitação está em `config/linkedin.php`. Os arquivos de sessão ficam em diretórios locais ignorados pelo Git ou são fornecidos por secrets no workflow. Nunca versione cookies, credenciais ou arquivos de sessão.

### Processamento e publicação

O comando é:

```bash
php artisan linkedin:collect-posts
php artisan linkedin:collect-posts --mode=duckduckgo
php artisan linkedin:collect-posts --mode=bot
```

O fluxo executa as seguintes etapas:

1. Descobre publicações por um ou mais provedores;
2. Descarta candidatos cujo `post_id` ou URL já estejam registrados;
3. Reserva o novo post em `linkedin_post_candidates` com estado `processing`, usando os índices únicos para impedir que provedores ou execuções concorrentes processem a mesma publicação;
4. Só então tenta enriquecer a publicação por meio de `LinkedInPublicPostFetcher`, quando o conteúdo público está acessível;
5. Processa o texto, links, e-mails e comentários disponíveis;
6. Aplica regras editoriais e verificações de duplicidade;
7. Persiste o resultado final. Falhas durante o processamento são registradas como rejeitadas para que o post não seja consultado novamente;
8. Executa `LinkedInPostAutoPublisher` para publicar os candidatos pendentes que forem aprovados pelas regras automáticas.

**A publicação pode ser automática.** Não se deve descrever o fluxo atual como uma fila que exige aprovação manual de cada candidato. A automação não envia candidaturas em nome dos usuários.

### Regras editoriais relevantes

O processador considera, entre outros pontos:

- Indícios de recrutamento e oportunidade de trabalho em tecnologia;
- Data e janela de publicação;
- Área e senioridade compatíveis com o escopo do portal;
- Identificação de cargo sem usar o texto inteiro do post como título quando é possível extrair o cargo;
- Links e informações de candidatura;
- Deduplicação contra vagas e candidatos já conhecidos;
- Rejeição de publicações que condicionem o envio de links ou informações a comentários, palavras-chave ou contato privado por DM, tanto no texto principal quanto em comentários públicos disponíveis;
- Comentários comuns, sem exigência de engajamento ou contato privado, não são rejeitados apenas por existirem;
- E-mails pessoais/gratuitos não são aceitos como contato de candidatura nem armazenados como e-mails válidos. Se houver um link válido, ele continua sendo avaliado; se o e-mail pessoal for o único canal, a publicação é rejeitada;
- Rejeição de destinos agregadores ou links que não atendam aos critérios configurados.

A análise de comentários só pode utilizar comentários realmente disponibilizados pela sessão autenticada. A ausência de um dado não deve ser interpretada como confirmação de que ele não existe na publicação original.

**Regra de consulta única:** depois que um post é reservado e submetido à consulta detalhada, o registro permanece no banco independentemente do resultado (`pending_publication`, `published`, `duplicate`, `rejected` ou falha registrada). Nas execuções seguintes, ele não volta a ser enriquecido ou processado. A descoberta nos buscadores ainda pode retornar o link novamente, pois essa etapa é necessária para encontrar publicações novas; o bloqueio ocorre antes da consulta individual/enriquecimento. Publicações que ficam com estado `processing` por encerramento abrupto também não são repetidas automaticamente.

### Workflows de publicações

- `linkedin-posts.yml`: coleta regular no modo `all`; no workflow versionado, `LINKEDIN_SECONDARY_ACCOUNT_ENABLED=false`, portanto a execução agendada utiliza os buscadores públicos.
- `linkedin-secondary-search.yml`: execução específica do modo `bot`, com a conta secundária habilitada e o estado de sessão fornecido por secret.
- `linkedin-posts-ci.yml`: valida scripts Node, sintaxe PHP e testes automatizados relacionados.

Os workflows possuem agendamentos próprios em `America/Sao_Paulo` e também podem ser executados manualmente.

## 2. LinkedIn Jobs — anúncios de vagas

O projeto possui um coletor separado para anúncios publicados na área LinkedIn Jobs. Ele consulta páginas públicas de visitantes, sem login, por meio dos endpoints públicos utilizados pelo site. A implementação fica em `scripts/linkedin/jobs.mjs`, integrada ao Laravel por `LinkedInJobsSource`.

Comando:

```bash
php artisan linkedin:collect-jobs
php artisan linkedin:collect-jobs --sync
```

A execução padrão envia um job para a fila `linkedin`; a opção `--sync` executa diretamente. O workflow `linkedin-jobs.yml` despacha e processa a fila geral. O workflow `linkedin-jobs-brq.yml` executa uma busca adicional restrita à BRQ, com os mesmos critérios editoriais e histórico de anúncios consultados.

O coletor pesquisa termos de desenvolvimento, suporte e infraestrutura, com localização configurável (padrão: Brasil), janela de até 168 horas e paginação limitada. Os termos podem ser substituídos por execução via `LINKEDIN_JOBS_SEARCH_TERMS` (separados por ponto e vírgula) e a empresa pode ser restringida por `LINKEDIN_JOBS_COMPANY_FILTER`; sem essas variáveis, mantém-se a busca geral. Primeiro reúne e deduplica os cartões; depois remove os IDs já registrados no histórico de consultas ou já importados como `linkedin-jobs:*`. Somente os IDs ainda não consultados recebem uma requisição de detalhe. A descrição completa continua sendo obtida antes de qualquer decisão editorial: nenhum filtro de senioridade, área ou experiência foi antecipado para o cartão. Os anúncios extraídos passam pelo importador e pelos critérios editoriais gerais do TI Vagas.

### Tratamento de HTTP 429

O coletor respeita limites do serviço externo e não tenta contorná-los por rotação de IP, proxies ou troca de identidade.

| Variável | Padrão | Função |
| --- | --- | --- |
| `LINKEDIN_JOBS_DELAY_MS` | 2500 | Intervalo mínimo entre requisições |
| `LINKEDIN_JOBS_JITTER_MS` | 500 | Variação adicional do intervalo |
| `LINKEDIN_JOBS_MAX_RETRIES` | 3 | Tentativas após HTTP 429 |
| `LINKEDIN_JOBS_BACKOFF_BASE_MS` | 30000 | Espera inicial do backoff |
| `LINKEDIN_JOBS_BACKOFF_MAX_MS` | 300000 | Limite do backoff no processo |
| `LINKEDIN_JOBS_RESCHEDULE_SECONDS` | 900 | Atraso inicial para reagendar o job |
| `LINKEDIN_JOBS_RESCHEDULE_MAX_SECONDS` | 14400 | Limite do reagendamento |

- O cabeçalho `Retry-After` é respeitado.
- Persistindo o HTTP 429, o script registra o progresso e encerra com código 75, sem importar resultados parciais.
- O Laravel converte a situação em `SourceRateLimitedException` e reagenda o job com atraso crescente.
- O job é único (`ShouldBeUnique`) para evitar despachos repetidos enquanto uma execução está pendente ou reagendada.
- Os IDs com resposta HTTP da consulta detalhada são gravados em `linkedin_job_scans`, inclusive quando a descrição está ausente ou a resposta HTTP não é bem-sucedida. Uma resposta HTTP 429 e falhas sem resposta não são marcadas como consulta concluída.
- O histórico também considera anúncios já importados antes da implantação da tabela, evitando uma nova consulta de detalhe para esses IDs.
- Na execução síncrona, a limitação é apresentada como aviso e nenhuma vaga é importada.

Os testes do mecanismo Node podem ser executados com:

```bash
npm run test:linkedin
```

## 3. Limitações e responsabilidade

Os mecanismos de busca e as páginas públicas não garantem cobertura integral das publicações ou vagas do LinkedIn. Resultados podem estar ausentes, incompletos, atrasados ou indisponíveis. A interface pode mudar e os serviços podem limitar requisições.

A existência de uma oportunidade no portal não garante que a candidatura continue aberta. O usuário deve conferir os detalhes e concluir a candidatura diretamente no canal oficial indicado pela empresa.

Não implementar mecanismos para burlar limites, autenticação, desafios ou controles de acesso. Mudanças nos fluxos devem preservar as condições aplicáveis aos serviços externos e os testes correspondentes.
