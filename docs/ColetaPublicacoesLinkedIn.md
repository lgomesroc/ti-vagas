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

## Coleta pública de LinkedIn Jobs: limitação de requisições (HTTP 429)

A coleta (`scripts/linkedin/jobs.mjs`) espaça as chamadas e reage ao HTTP 429 sem tentar
contornar o limite: não há rotação de IP, proxies, troca de identidade ou cookies alternativos.

| Variável | Padrão | Função |
| --- | --- | --- |
| `LINKEDIN_JOBS_DELAY_MS` | 2500 | Intervalo mínimo entre quaisquer requisições (mínimo 500) |
| `LINKEDIN_JOBS_JITTER_MS` | 500 | Variação aleatória somada ao intervalo |
| `LINKEDIN_JOBS_MAX_RETRIES` | 3 | Novas tentativas após 429 |
| `LINKEDIN_JOBS_BACKOFF_BASE_MS` | 30000 | Primeira espera; dobra a cada tentativa |
| `LINKEDIN_JOBS_BACKOFF_MAX_MS` | 300000 | Teto do backoff e da espera aceita dentro do processo |
| `LINKEDIN_JOBS_RESCHEDULE_SECONDS` | 900 | Atraso inicial do reagendamento do job |
| `LINKEDIN_JOBS_RESCHEDULE_MAX_SECONDS` | 14400 | Teto do reagendamento |

- O cabeçalho `Retry-After` (segundos ou data HTTP) é respeitado. Se pedir espera acima do teto,
  a coleta é interrompida de imediato, sem aguardar dentro do processo.
- Persistindo o 429 (inclusive na etapa do `geoId`), o script registra o motivo e o progresso
  (pesquisas concluídas, cartões únicos, detalhes consultados, descrições extraídas) e sai com
  código 75. O Laravel converte isso em `SourceRateLimitedException`; nada é importado.
- `FetchLinkedInJobsJob` devolve o job à fila com atraso crescente (`release`) e não conta isso
  como falha. Esgotados os 5 reagendamentos, termina sem falha e a próxima execução agendada tenta
  de novo. Erros reais continuam falhando na primeira ocorrência.
- O job é único (`ShouldBeUnique`): enquanto houver um pendente ou reagendado, um novo despacho é
  descartado, para não repetir a coleta logo após uma limitação.
- Em `linkedin:collect-jobs --sync` a limitação é exibida como aviso (código 0), sem importar vagas.
- No workflow `linkedin-jobs.yml`, o worker usa `--stop-when-empty`: um job reagendado permanece
  na fila e é processado pelo worker da próxima execução agendada.

Testes: `npm run test:linkedin` (módulo de ritmo/backoff e execução real do script contra um
servidor local que responde 429) e `tests/Feature/LinkedInJobsRateLimitTest.php`.
