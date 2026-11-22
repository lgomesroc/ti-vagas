# Atualizações Recentes do Projeto

Este documento registra as principais alterações recentes incorporadas à branch `developer`, com foco nas integrações de oportunidades, no fluxo de descoberta de publicações do LinkedIn e na organização da documentação.

## 1. Coleta de oportunidades no LinkedIn

O projeto mantém dois fluxos separados:

- **Publicações de recrutamento:** descoberta por Bing RSS e DuckDuckGo, com possibilidade de execução adicional por conta secundária em workflow próprio. Quando habilitada, a conta secundária consulta conteúdo e comentários visíveis. O processador aplica as regras editoriais e o `LinkedInPostAutoPublisher` pode cadastrar automaticamente os candidatos aprovados.
- **LinkedIn Jobs:** coleta de anúncios por páginas públicas, sem login, usando `scripts/linkedin/jobs.mjs`. O coletor aplica termos de desenvolvimento, suporte e infraestrutura e importa os resultados pelo fluxo comum.

A descoberta pública não garante acesso integral a todas as publicações, datas, links ou comentários. O conteúdo de comentários depende do que a conta secundária consegue visualizar; o workflow regular de publicações está configurado com a conta secundária desabilitada, enquanto `linkedin-secondary-search.yml` executa o modo `bot` com sessão própria.

A coleta de LinkedIn Jobs passou a tratar HTTP 429 com intervalo mínimo entre requisições, jitter, backoff exponencial e respeito ao cabeçalho `Retry-After`. Persistindo a limitação, interrompe a execução sem importar resultados parciais e reagenda o job. Não utiliza rotação de IP, proxies ou troca de identidade para contornar limites externos.

Detalhes: [Coleta de Oportunidades no LinkedIn](ColetaPublicacoesLinkedIn.md).

## 2. Gupy: transição para coleta global

A coleta da Gupy deixou de executar uma consulta individual para cada empresa. O workflow `.github/workflows/gupy.yml` chama `php artisan vacancies:collect-gupy`, que utiliza `GupyGlobalSource` e a API pública global de busca.

Os termos, paginação e janela incremental ficam no bloco `vacancy_sources.gupy.global` de `config/vacancy_sources.php`. O resultado é importado sob a identidade `gupy-global`. Os cadastros individuais antigos e a classe `GupySource` permanecem como legado, mas não são usados pelo fluxo atual da fábrica.

A busca global não garante cobertura integral de todas as vagas da plataforma; depende dos termos configurados, paginação, janela de consulta e dados disponibilizados pela API.

## 3. Validação de publicações com chamadas a DM

As regras de validação foram ampliadas para rejeitar publicações que direcionem candidatos a mensagens privadas para obter informações essenciais sobre a oportunidade ou a candidatura.

A validação considera expressões relacionadas a chamar, enviar mensagem ou entrar em contato por DM, Direct, inbox ou mensagem privada. O objetivo é evitar anúncios em que o link ou os detalhes da candidatura dependam de contato privado.

A regra de engajamento artificial é aplicada ao texto do post e também a cada comentário público disponível. Comentários comuns, sem exigência de palavra-chave, link por DM ou contato privado, não são rejeitados apenas por existirem. A coleta utiliza o conteúdo efetivamente fornecido por uma fonte compatível.

## 4. Organização e documentação do repositório

A documentação foi ampliada e a árvore do projeto foi atualizada a partir da branch `developer`.

O arquivo [Estrutura do Projeto](EstruturaDoProjeto.md) apresenta os diretórios e arquivos versionados. Em cada nível, as pastas aparecem primeiro em ordem alfabética e, em seguida, os arquivos também em ordem alfabética.

A documentação técnica por assunto está relacionada no [README principal](../README.md).

## 5. Referências de implementação

| Assunto | Arquivos principais |
|---|---|
| Descoberta no LinkedIn | `app/Services/LinkedInPostSearchClient.php` |
| Processamento e validação de candidatos | `app/Services/LinkedInPostCandidateProcessor.php` |
| Busca agendada | `app/Console/Commands/CollectLinkedInPosts.php` e `routes/console.php` |
| Enriquecimento de páginas públicas | `app/Services/LinkedInPublicPostFetcher.php` |
| Configuração do LinkedIn | `config/linkedin.php` |
| Fontes de recrutamento | `config/vacancy_sources.php` |
| Testes | `tests/Feature` e `tests/Unit` |

## 6. Como interpretar este documento

Este é um resumo de alterações recentes, não uma substituição dos documentos técnicos específicos. Para regras detalhadas de elegibilidade, localização, segurança, instalação, automação e coleta, consulte os documentos correspondentes na pasta `docs/`.

A existência de uma integração no código não garante que o serviço externo esteja sempre disponível, que todos os anúncios sejam encontrados ou que uma candidatura permaneça aberta. A disponibilidade real depende das fontes externas, da configuração de ambiente e da execução bem-sucedida das rotinas.

## 7. Atualizações de integração e processamento — 22/11/2026

### Gupy: coleta global

A coleta da Gupy deixou de executar uma consulta independente por empresa. O workflow dedicado chama `vacancies:collect-gupy`, que utiliza `GupyGlobalSource` e a API pública global de busca. Os termos de pesquisa e os limites de paginação ficam em `vacancy_sources.gupy.global`. A lista de empresas e a classe `GupySource` ainda presentes no repositório são legado e não participam da coleta executada pela fábrica.

### InHire: descoberta global de tenants

A fábrica passou a registrar um único coletor `inhire-global`. Ele combina tenants iniciais conhecidos com tenants identificados em vagas já armazenadas, fontes antigas e publicações/candidatos do LinkedIn, inclusive links guardados em comentários. A consulta por tenant é mantida internamente, mas a importação usa uma identidade global e evita buscar novamente detalhes de IDs já conhecidos.

### LinkedIn Jobs: respeito a limites de requisição

O coletor passou a aplicar intervalo mínimo entre requisições, jitter, backoff exponencial e respeito ao cabeçalho `Retry-After`. Quando o HTTP 429 persiste, a execução é interrompida sem importar os resultados parciais; o job Laravel é reagendado com atraso crescente. Não são utilizados proxies, rotação de IP ou troca de identidade para contornar o limite.

### Filtros e normalização

- O filtro editorial passou a rejeitar funções operacionais de programação que não correspondem ao escopo de desenvolvimento de software do portal.
- Foram reforçados os critérios para não aceitar oportunidades de nível pleno como se fossem júnior.
- O processador de publicações do LinkedIn passou a extrair títulos concisos de anúncios em uma única linha, evitando armazenar o texto inteiro da publicação como título quando há uma função identificável.

### Correções do aceite dos termos e da primeira localização

- **Issue #46:** foi identificada uma quebra de linha antes de `<?php` em `config/vacancy_sources.php`. A saída prematura impedia o envio correto dos cabeçalhos HTTP, inclusive redirecionamentos e cookies. A linha foi removida.
- **Issue #47:** o fluxo inicial de localização foi ajustado para permitir que uma sessão em modo `limited` faça a primeira solicitação. A sessão só passa a `available` depois que coordenadas válidas são resolvidas para um município; recusas e dados inválidos mantêm o modo limitado.

As duas Issues foram encerradas após as correções. A documentação não fixa uma contagem de testes: a validação deve ser consultada na execução de CI correspondente à versão publicada.


## 8. Revisão da automação GitHub Actions — 22/11/2026

Foi criado o documento [Workflows do GitHub Actions](WorkflowsGitHubActions.md), com inventário de todos os workflows, gatilhos, agendamentos, timezone, branch usada no checkout, secrets, jobs, filas e grupos de concorrência. A auditoria confirmou sete arquivos de workflow em `developer` e oito em `main`.

A revisão identificou uma diferença importante entre branches: `database-failover.yml` existe somente em `main`, enquanto os outros sete workflows estão presentes em `developer`. Também foram registrados os checkouts explícitos: Gupy e automação geral executam código de `main`; LinkedIn Jobs e conta secundária usam `developer`; publicações regulares usam a referência do evento.

A documentação agora distingue os grupos de concorrência independentes. O grupo do failover não é compartilhado com todos os workflows de coleta, e as rotinas de coleta usam diretamente o secret `NEON_DATABASE_URL`, não a variável dinâmica `DB_URL` do Render. Isso é uma limitação operacional relevante durante a troca de banco e não deve ser descrita como sincronização global automática.

O documento [Automação e Testes](AutomacaoETestes.md) foi reescrito para refletir o scheduler Laravel, as filas, os comandos e a relação com os workflows. O README passou a apontar para o novo inventário.


## 9. Consulta única de publicações e anúncios do LinkedIn — 22/11/2026

### Publicações de recrutamento

- A publicação é reservada em `linkedin_post_candidates` antes de abrir a página pública para enriquecimento.
- IDs e URLs já registrados são ignorados em execuções posteriores, independentemente do status final, inclusive quando rejeitados ou duplicados.
- A conta secundária recebe a lista de posts conhecidos e pula a expansão dos comentários antes de coletar detalhes.
- Falhas durante a consulta são registradas como rejeitadas, evitando uma nova tentativa automática da mesma publicação. Um processo interrompido abruptamente pode deixar o status `processing`; esse estado também não é reconsultado automaticamente.

### LinkedIn Jobs

- O coletor reúne os cartões e deduplica os IDs antes de iniciar as consultas detalhadas.
- IDs presentes no histórico `linkedin_job_scans` ou já importados como `linkedin-jobs:*` são ignorados antes da requisição da descrição.
- A descrição completa continua sendo consultada para cada ID novo. Nenhum filtro editorial de senioridade, experiência ou área foi antecipado para o cartão.
- Uma resposta HTTP de detalhe é registrada mesmo se a descrição estiver ausente ou a resposta for HTTP 4xx/5xx. HTTP 429 e falhas sem resposta não são registrados como consulta concluída.

### Validação de e-mails e comentários

- Provedores pessoais/gratuitos (por exemplo, Gmail, Outlook, Hotmail, Yahoo e Proton) não são considerados canais de candidatura e não são armazenados como e-mails válidos.
- Se houver link de candidatura, ele continua sendo avaliado mesmo que a publicação também contenha e-mail pessoal.
- Se o e-mail pessoal for o único canal disponível, o post é rejeitado.
- A verificação de chamadas de engajamento artificial também analisa comentários públicos; comentários comuns permanecem permitidos.

Os filtros editoriais das vagas e os critérios de elegibilidade existentes não foram antecipados nem afrouxados.


## 10. Integração global da Abler — 22/11/2026

Foi incluído o coletor `AblerSource`, que consulta a listagem pública JSON do portal de candidatos em `/api/candidate/v2/vacancies`. A integração é global: não exige cadastro individual de empresas ou credenciais por empresa.

- A fonte é registrada na `VacancySourceFactory` com a identidade `abler-global`.
- A paginação usa os metadados `meta.next` e `meta.last`.
- Os campos recebidos são normalizados para o contrato comum de vagas, incluindo empresa, descrição, localização, modalidade, URL e data original.
- A configuração fica em `vacancy_sources.abler`, com janela inicial de 60 dias e limites de páginas/registros por execução.
- Foram adicionados testes com respostas HTTP simuladas em `tests/Feature/AblerSourceTest.php`.

A implementação foi registrada na branch `developer`. Os testes automatizados e uma coleta real ainda precisam ser executados para confirmar compatibilidade contínua com a resposta atual da API. A existência do coletor não comprova, por si só, que anúncios estejam sendo importados em produção.
