# Integração de oportunidades descobertas no LinkedIn

## Estado atual

A descoberta automatizada de publicações está **desativada**. O TI Vagas não deve coletar publicações por scraping, automatizar sessões autenticadas ou usar contas pessoais/secundárias para contornar controles de acesso.

A etapa de processamento permanece preparada para validar dados recebidos por uma fonte autorizada, mas uma publicação só pode ser considerada quando o payload trouxer:

- data original verificável dentro da janela de coleta;
- indicador booleano confiável `isPromoted` informando que a publicação não é promovida;
- e-mail corporativo de candidatura no texto fornecido;
- link público de candidatura, quando divulgado, que será validado contra agregadores e deduplicado antes da inspeção completa;
- sinais de recrutamento e aderência às regras editoriais do TI Vagas.

Payload sem indicador de promoção é rejeitado por segurança. Publicações promovidas também são rejeitadas. E-mails de provedores pessoais conhecidos não atendem ao requisito de contato corporativo.

## Regras de processamento

1. Rejeitar publicações sem data original verificável ou fora da janela configurada.
2. Rejeitar chamadas de engajamento que condicionem link ou informações a comentários, palavras-chave ou mensagens privadas.
3. Exigir e-mail corporativo de candidatura. Link sozinho não substitui esse requisito.
4. Normalizar e comparar links com vagas e candidatos já conhecidos antes de consultar o destino.
5. Bloquear agregadores de vagas e destinos não verificáveis.
6. Aplicar área, senioridade, experiência, localidade e modalidade.
7. Gravar apenas candidatos para revisão; nunca publicar o post de uma pessoa no site nem publicar automaticamente uma vaga.

## Limitação de promoção

A integração de busca por snippets não fornece um indicador confiável de publicação patrocinada. Portanto, não é possível garantir esse filtro com os resultados atuais. A automação permanece desligada até existir uma fonte autorizada que forneça esse campo.

## Reativação

Somente reativar quando houver uma integração autorizada e documentada que entregue os campos acima. Não configurar scraping de páginas autenticadas, cookies, sessões ou automação de navegador para contornar restrições.
