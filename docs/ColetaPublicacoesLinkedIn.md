# Integração de oportunidades descobertas no LinkedIn

## Fontes de descoberta

A busca pública utiliza:
- Bing RSS;
- DuckDuckGo (HTML público).

A descoberta por buscadores não equivale a uma API oficial do LinkedIn e não garante localizar todas as publicações, datas, conteúdo integral ou comentários.

## Conta secundária de pesquisa

A conta secundária destinada exclusivamente à pesquisa de suporte técnico será a única conta considerada na integração autenticada. A conta pessoal não será utilizada. Publicações promovidas/patrocinadas devem ser descartadas; a busca deve se limitar a posts com indícios de oportunidades de TI.

O acesso autenticado ocorre em um perfil persistente e separado do navegador, iniciado pelo próprio titular da conta secundária. A senha não é armazenada pelo projeto; a sessão fica em `.local/linkedin-secondary-profile/`, diretório ignorado pelo Git. O coletor consulta resultados e comentários visíveis, sem automatizar desafios/CAPTCHA, contornar bloqueios ou reutilizar a sessão da conta pessoal.

A conta secundária pesquisa exclusivamente vagas de suporte técnico, help desk e service desk. O comando `npm run linkedin:secondary:login` abre o navegador para o primeiro login manual. O comando `npm run linkedin:secondary:search` executa a pesquisa usando a sessão local.

## Regras de seleção e processamento

1. Pesquisar publicações com indícios de vagas de TI.
2. Ignorar publicações promovidas/patrocinadas.
3. Extrair links e e-mails divulgados na publicação e nos comentários públicos disponíveis.
4. Deduplicar o link contra vagas e candidatos já registrados antes de inspecionar o destino.
5. Exigir e-mail corporativo como contato de candidatura.
6. Rejeitar publicações que condicionem o envio do link/informações a comentários, palavras-chave ou contato privado por DM.
7. Não reproduzir o texto do post como se fosse conteúdo próprio da vaga.
8. Validar a oportunidade pelas regras de área, senioridade, localização e consistência do TI Vagas.
9. Não enviar candidaturas automaticamente.

Dados ausentes não devem ser presumidos. Publicações sem informação suficiente são descartadas.

## Limitação de requisições (HTTP 429) na coleta de LinkedIn Jobs

A coleta espaça as chamadas e reage a HTTP 429 sem tentar contornar o limite
(sem rotação de IP, proxies, troca de identidade ou cookies alternativos).

| Variável | Padrão | Função |
| --- | --- | --- |
| `LINKEDIN_REQUEST_DELAY_MS` | 5000 | Intervalo mínimo entre buscas |
| `LINKEDIN_PANEL_DELAY_MS` | 1500 | Intervalo mínimo entre aberturas de painel |
| `LINKEDIN_REQUEST_JITTER_MS` | 1000 | Variação aleatória somada aos intervalos |
| `LINKEDIN_RATE_LIMIT_MAX_RETRIES` | 3 | Novas tentativas após 429 |
| `LINKEDIN_RATE_LIMIT_BASE_BACKOFF_MS` | 30000 | Primeira espera; dobra a cada tentativa |
| `LINKEDIN_RATE_LIMIT_MAX_BACKOFF_MS` | 300000 | Teto do backoff e da espera aceita dentro do processo |
| `LINKEDIN_RATE_LIMIT_RESCHEDULE_SECONDS` | 900 | Atraso inicial do reagendamento do job |
| `LINKEDIN_RATE_LIMIT_RESCHEDULE_MAX_SECONDS` | 21600 | Teto do reagendamento |

- `Retry-After` (segundos ou data HTTP) é respeitado. Se pedir espera acima do teto, a coleta
  é interrompida de imediato e a espera fica a cargo do reagendamento do job.
- Persistindo o 429, o script sai com código 75; o Laravel lança `SourceRateLimitedException`.
- `FetchLinkedInJobsJob` (`php artisan linkedin:collect-jobs --queue`) devolve o job à fila com
  atraso crescente e não conta isso como falha; esgotados os reagendamentos, termina sem falha e
  a próxima execução agendada tenta de novo.
- Com o driver `database`, `DB_QUEUE_RETRY_AFTER` deve ser maior que o timeout do job (2100s).
