# Atualizações Recentes do Projeto

Este documento registra as principais alterações recentes incorporadas à branch `developer`, com foco nas integrações de oportunidades, no fluxo de descoberta de publicações do LinkedIn e na organização da documentação.

## 1. Descoberta de oportunidades no LinkedIn

Foi implementado um fluxo para descobrir publicações públicas relacionadas a recrutamento e oportunidades em tecnologia.

### Descoberta

O serviço `LinkedInPostSearchClient` consulta:

- DuckDuckGo, pela página pública de resultados;
- Brave Search, quando a chave da API está configurada;
- Mojeek, quando a chave da API está configurada.

As consultas usam palavras-chave de recrutamento e tecnologia e restringem os resultados a URLs de publicações do LinkedIn. Os resultados são combinados e deduplicados pela URL. A janela padrão de análise é de 72 horas.

A coleta é executada pelo comando:

```bash
php artisan linkedin:collect-posts
```

O agendamento está configurado quatro vezes ao dia, às 06h, 10h, 14h e 18h, no fuso `America/Sao_Paulo`. A execução depende de `LINKEDIN_POSTS_ENABLED=true`; por padrão, a configuração é desabilitada.

### Validação dos candidatos

O processador aplica verificações antes de encaminhar uma publicação para revisão:

- data recuperável dentro da janela configurada;
- sinais de recrutamento;
- rejeição de chamadas de engajamento que condicionem informações, links ou candidatura a comentários, palavras-chave ou contato privado por DM;
- exigência de e-mail corporativo de candidatura, rejeitando provedores pessoais conhecidos;
- identificação de links repetidos em vagas e candidatos já registrados;
- inspeção de URLs e bloqueio de agregadores ou destinos considerados inválidos;
- aplicação das regras editoriais de área, senioridade, experiência e localização.

Os registros são armazenados como candidatos. Mesmo os que passam pelos filtros automáticos permanecem em revisão manual: não há publicação automática de vagas no portal.

### Limitações técnicas importantes

Os buscadores fornecem resultados de descoberta, não uma cópia integral e confiável da publicação original. Snippets podem omitir texto, links, e-mails, data exata, comentários e informações sobre promoção/patrocínio.

Existe a classe `LinkedInPublicPostFetcher`, preparada para tentar enriquecer uma página de post que esteja publicamente acessível sem sessão. Ela não usa login, cookies pessoais, contorno de desafios ou repetição de tentativas após bloqueios. **Entretanto, a execução atual do comando de coleta não injeta esse serviço no fluxo do processador.** Portanto, não se deve afirmar que a coleta agendada atualmente lê o conteúdo integral ou os comentários dos posts.

O processador aceita comentários quando eles são fornecidos no payload, mas o cliente de busca atual inicia os candidatos com uma lista vazia de comentários. A análise de comentários depende de uma fonte de entrada compatível e ainda não está integrada ao comando agendado.

Da mesma forma, o descarte de publicações promovidas ocorre quando a fonte informa explicitamente `isPromoted=true`. Os buscadores atuais não fornecem esse indicador de forma confiável; sua ausência não comprova que a publicação não seja patrocinada.

Não utilizar sessões autenticadas, cookies pessoais, automação de navegador para contornar controles de acesso ou fornecedores que revendam conteúdo obtido por scraping em desacordo com as condições aplicáveis. Uma integração mais completa depende de uma fonte autorizada e de escopo compatível.

## 2. Novas fontes Gupy

Foram incorporadas fontes independentes à configuração do coletor:

- Drogaria Globo;
- Skill Consultoria;
- Smart NX;
- C&A;
- Viceri.

Os cadastros preservam a identificação de cada empresa e sua respectiva página de carreiras. A Drogaria Globo é uma fonte separada da empresa Globo; não se deve confundir os dois cadastros.

Os detalhes e as particularidades dos filtros dessas fontes estão em [Fontes Gupy Recentes](FontesGupyRecentes.md). A configuração efetiva permanece em `config/vacancy_sources.php`.

## 3. Validação de publicações com chamadas a DM

As regras de validação foram ampliadas para rejeitar publicações que direcionem candidatos a mensagens privadas para obter informações essenciais sobre a oportunidade ou a candidatura.

A validação considera expressões relacionadas a chamar, enviar mensagem ou entrar em contato por DM, Direct, inbox ou mensagem privada. O objetivo é evitar anúncios em que o link ou os detalhes da candidatura dependam de contato privado.

A regra é aplicada ao texto do próprio post. Uma frase isolada em comentário não deve, por si só, invalidar a publicação. Comentários podem conter informações públicas úteis, mas só podem ser avaliados quando forem efetivamente fornecidos por uma fonte compatível.

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

