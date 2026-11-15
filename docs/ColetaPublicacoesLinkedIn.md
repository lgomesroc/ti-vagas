# Coleta de publicações do LinkedIn

## Escopo

Esta integração pesquisa **publicações públicas** de recrutadores e páginas de empresas no LinkedIn. Ela não pesquisa LinkedIn Jobs e não usa login, senha ou cookies de uma conta pessoal.

A primeira implementação utiliza o Actor \`johnvc/linkedin-post-search-api\` na Apify. É um fornecedor externo, não uma API oficial do LinkedIn. Antes de habilitar em produção, confira os termos do fornecedor, a cobertura atual, o preço e a compatibilidade do uso com as regras aplicáveis.

## Fluxo

1. Pesquisar palavras-chave de recrutamento e cargos de TI publicados nas últimas 72 horas.
2. Manter publicações de perfis com sinais de recrutamento ou de páginas identificadas como empresa.
3. Rejeitar chamadas para comentar/digitar palavras para receber link, e-mail ou detalhes por DM.
4. Ler os comentários públicos retornados pelo fornecedor e extrair links e e-mails.
5. Seguir redirecionamentos de links públicos, bloquear domínios de agregadores e extrair texto/metadata da página final.
6. Aplicar filtros de área, senioridade, experiência, localização e modalidade.
7. Comparar URL, empresa, cargo, localidade, modalidade e similaridade da descrição com vagas existentes e candidatos já coletados.
8. Gravar candidatos na tabela \`linkedin_post_candidates\`.
9. **Não publicar automaticamente.** Candidatos aprovados pelos filtros ficam em \`pending_review\` para inspeção e aprovação manual.

Publicação sem link externo ou e-mail não é descartada apenas por isso. Se passar pelos filtros, permanece pendente para revisão; a publicação original é mantida como referência. Para aprovar, o operador precisa confirmar/completar empresa, cargo, descrição e data. Se não existir link de candidatura, a vaga publicada usará o link do post como referência.

## Configuração

1. Criar uma conta na Apify e gerar um API token.
2. Adicionar o segredo \`APIFY_API_TOKEN\` no GitHub Actions do repositório.
3. No ambiente local, copiar as variáveis abaixo para o \`.env\`:

\`\`\`dotenv
LINKEDIN_POSTS_ENABLED=true
LINKEDIN_POSTS_LOOKBACK_HOURS=72
APIFY_API_TOKEN=seu_token
APIFY_LINKEDIN_POSTS_ACTOR=johnvc~linkedin-post-search-api
APIFY_LINKEDIN_MAX_CHARGE_USD=0.15
APIFY_LINKEDIN_MAX_POSTS_PER_KEYWORD=8
APIFY_LINKEDIN_MAX_WAIT_SECONDS=300
\`\`\`

O workflow limita cada execução a US$ 0,15. Com quatro execuções diárias, o teto teórico inicial é US$ 0,60 por dia (US$ 18 em 30 dias), sujeito ao modelo de cobrança e ao limite efetivo do fornecedor. O limite não é uma estimativa de gasto real.

## Comandos

Aplicar a migração:

\`\`\`bash
php artisan migrate
\`\`\`

Executar uma coleta manual:

\`\`\`bash
php artisan linkedin:collect-posts
\`\`\`

Listar candidatos aguardando revisão:

\`\`\`bash
php artisan linkedin:review list
\`\`\`

Ver detalhes de um candidato, incluindo comentários, links e e-mails:

\`\`\`bash
php artisan linkedin:review show ID
\`\`\`

Completar dados que não foram extraídos automaticamente:

\`\`\`bash
php artisan linkedin:candidate-update ID --title="Desenvolvedor PHP Júnior" --company="Empresa" --location="Remoto Brasil" --work-model="Remoto" --description="Descrição confirmada"
\`\`\`

Aprovar e importar como vaga pública:

\`\`\`bash
php artisan linkedin:review approve ID
\`\`\`

Rejeitar:

\`\`\`bash
php artisan linkedin:review reject ID --reason="Motivo"
\`\`\`

## Agendamento

O workflow separado \`.github/workflows/linkedin-posts.yml\` agenda as execuções às 06:00, 10:00, 14:00 e 18:00 em \`America/Sao_Paulo\`. Ele não altera \`routes/console.php\` nem o workflow existente de coleta das fontes atuais.

O GitHub Actions executa workflows agendados somente quando o arquivo está presente na branch padrão do repositório. Portanto, o agendamento só ficará ativo depois que esta implementação for incorporada à branch padrão. Enquanto permanecer nesta branch de funcionalidade, é possível executar o workflow manualmente pelo GitHub Actions.

## Limitações conhecidas

- O fornecedor retorna somente os comentários públicos que consegue visualizar; o exemplo de integração informa até dez comentários por publicação. Não é uma leitura garantida de toda a discussão.
- Posts, comentários ou páginas que exigem autenticação, conteúdo dinâmico ou bloqueiam o acesso podem não fornecer texto completo. Esses candidatos precisam de revisão; falha de leitura não prova que a vaga é inválida.
- A lista de agregadores fica em \`config/linkedin.php\` e precisa ser mantida.
- A extração de cargo, empresa e localidade é heurística. A revisão humana continua obrigatória.
- Não há publicação automática na página institucional do LinkedIn nesta etapa.
- A integração não usa LinkedIn Jobs.
