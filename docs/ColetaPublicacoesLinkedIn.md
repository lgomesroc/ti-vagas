# Integração de oportunidades descobertas no LinkedIn

## Estado da coleta

A coleta por mecanismos de busca públicos está ativa por agendamento quatro vezes ao dia (06h, 10h, 14h e 18h, horário de Brasília), além da execução manual pelo GitHub Actions.

A implementação atual consulta DuckDuckGo, Brave Search e Mojeek para descobrir URLs públicas de posts. Esses resultados são apenas descoberta: não garantem texto integral, comentários, data original nem indicador de publicação patrocinada.

## Regras de processamento

1. Considerar apenas publicações recentes com data recuperável; a data relativa de snippets não equivale a uma data original verificada pela fonte.
2. Rejeitar engagement bait identificado no texto do próprio post, por exemplo: "comente EU QUERO que envio o link por DM".
3. Não rejeitar um post apenas porque um comentário contém uma frase desse tipo. Comentários públicos devem ser analisados como fonte de informações úteis de candidatura, links e e-mails, desde que a fonte de entrada os forneça.
4. Exigir e-mail corporativo de candidatura; provedores pessoais conhecidos não são aceitos.
5. Deduplicar links contra vagas e candidatos já existentes antes da inspeção HTTP completa.
6. Bloquear agregadores e destinos não verificáveis.
7. Aplicar as regras de área, senioridade, experiência, localidade e modalidade do TI Vagas.
8. Gerar candidatos para revisão manual; nunca publicar o post de uma pessoa nem publicar automaticamente uma vaga.

## Publicações promovidas

Os buscadores atuais não fornecem metadado confiável que indique se o post original do LinkedIn foi promovido. Se uma fonte fornecer explicitamente `isPromoted=true`, o post será rejeitado. Quando o campo não existir, a implementação não inventa uma confirmação: a limitação permanece e a origem do resultado deve ser considerada apenas descoberta por buscador, não verificação de status patrocinado.

## Fonte completa

A coleta atual não lê páginas autenticadas nem comentários. Para obter conteúdo integral e comentários, é necessária uma integração cuja autorização e escopo permitam esse acesso. Os termos da API do LinkedIn proíbem armazenar ou exibir conteúdo obtido por scraping, inclusive quando obtido indiretamente por terceiros. Portanto, fornecedores que apenas revendem dados raspados não são uma alternativa compatível.

## Caminhos oficiais investigados

- Community Management API: exige análise/aprovação e organização legal registrada; não oferece pesquisa irrestrita de publicações de qualquer membro.
- `r_member_social`: permissão fechada; o LinkedIn informa que não está aceitando novas solicitações.
- Talent Solutions Job Posting API: voltada a parceiros ATS/distribuidores para publicação e gestão de vagas, não para pesquisar posts e comentários de recrutadores. O LinkedIn informa que não está aceitando novas parcerias para a Job Posting API, orientando interessados a Apply Connect.

A coleta de descoberta permanece agendada enquanto a integração completa é investigada. Não se deve apresentar snippets como se fossem conteúdo integral.
