# Integração de oportunidades descobertas no LinkedIn

## Coleta e limites técnicos

A descoberta de URLs de publicações é feita por mecanismos de busca públicos:
- DuckDuckGo (HTML público);
- Brave Search (API oficial, se a chave estiver configurada);
- Mojeek (API, se a chave estiver configurada).

O enriquecedor tenta acessar a página pública sem sessão autenticada. Ele não usa login, senha, cookies de conta ou sessão de usuário. Se o LinkedIn exigir autenticação, bloquear o acesso ou limitar requisições, o conteúdo integral e os comentários não estarão disponíveis.

Os mecanismos de busca não garantem a descoberta de todas as publicações nem a data original, o conteúdo completo ou comentários. Snippets não devem ser tratados como confirmação integral de uma vaga.

## Fluxo automatizado

1. Descobrir publicações recentes por palavras-chave.
2. Tentar obter conteúdo público sem autenticação.
3. Analisar texto e comentários que estejam efetivamente disponíveis.
4. Rejeitar publicações fora da área, senioridade ou janela temporal.
5. Rejeitar pedidos para comentar palavras-chave ou depender de DM privada para receber instruções de candidatura.
6. Extrair e inspecionar links e e-mails disponíveis.
7. Deduplicar contra vagas e publicações já processadas.
8. Enviar candidatos completos ao importador de vagas.
9. Aprovar e cadastrar automaticamente quando todos os campos obrigatórios passam pela validação.
10. Rejeitar automaticamente registros incompletos, inválidos ou que falhem na validação final.

Não existe fila de aprovação manual como etapa operacional. O comando legado de revisão manual foi desativado.

## Condições para cadastro

O cadastro automático exige cargo, empresa, descrição, data de publicação e URL válida de candidatura ou da publicação. O importador central ainda exige os campos e regras de consistência próprios do TI Vagas. Duplicidades são descartadas.

A ausência de informação não é completada por suposição. Se os dados não forem suficientes, o registro é rejeitado automaticamente, não publicado como vaga.

## Publicações promovidas

Os buscadores não fornecem metadado confiável para confirmar se a publicação original foi promovida. Se a fonte de entrada informar explicitamente isPromoted=true, a publicação é rejeitada. Sem esse metadado, o sistema não pode afirmar que verificou o status patrocinado.

## Conformidade e acesso

Esta implementação não faz login no LinkedIn e não tenta contornar bloqueios, desafios, controles de acesso ou limites de requisição. O uso de conteúdo deve respeitar os termos e as permissões aplicáveis. A descoberta via buscadores não equivale a uma API oficial do LinkedIn nem garante permissão para armazenar ou republicar conteúdo.
