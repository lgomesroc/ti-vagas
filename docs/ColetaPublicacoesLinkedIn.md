# Coleta de publicações públicas do LinkedIn

## Escopo

A coleta procura publicações públicas de recrutadores e empresas que estejam indexadas pelo DuckDuckGo. Não pesquisa LinkedIn Jobs, não usa login, senha, cookies pessoais nem serviço pago.

A descoberta é feita por palavras-chave e restrita a URLs de posts do LinkedIn. O mecanismo de busca não garante cobertura integral, nem acesso a publicações recentes ainda não indexadas.

## Fluxo

1. Consultar resultados públicos indexados para as palavras-chave de TI.
2. Manter URLs de publicações do LinkedIn, descartando perfis, páginas de vagas e outros destinos.
3. Usar somente datas relativas identificáveis e dentro das últimas 72 horas. Sem data verificável, o resultado não entra no banco.
4. Aplicar filtros de recrutamento, área, senioridade, experiência, localização e modalidade.
5. Procurar links e e-mails no texto disponível; se houver link, seguir redirecionamentos, bloquear agregadores e extrair conteúdo público.
6. Deduplicar contra vagas existentes e candidatos coletados.
7. Gravar candidatos em \`linkedin_post_candidates\`, sem publicação automática.

## Limitações importantes

- O DuckDuckGo indexa apenas parte do conteúdo público. A coleta pode retornar poucos resultados ou nenhum.
- A pesquisa pública não fornece comentários completos de forma confiável. Nesta implementação, comentários não são obtidos. Portanto, uma publicação que dependa de link/e-mail escondido em comentários pode passar despercebida; não se deve presumir que a ausência de link no resultado significa ausência no post original.
- Autor, headline e data exata nem sempre aparecem nos resultados. Sem data legível, o resultado é descartado para não inventar data.
- A extração de cargo, empresa e localidade é heurística e exige revisão manual.
- Não há contorno de autenticação, CAPTCHA, bloqueios ou controles do LinkedIn.

## Configuração local

No \`.env\`:

\`\`\`dotenv
LINKEDIN_POSTS_ENABLED=true
LINKEDIN_POSTS_LOOKBACK_HOURS=72
\`\`\`

Não há token nem custo de API.

## Migração e teste manual

\`\`\`bash
php artisan migrate
php artisan linkedin:collect-posts
php artisan linkedin:review list
\`\`\`

Para inspecionar um candidato:

\`\`\`bash
php artisan linkedin:review show ID
\`\`\`

Aprovação e rejeição permanecem manuais:

\`\`\`bash
php artisan linkedin:review approve ID
php artisan linkedin:review reject ID --reason="Motivo"
\`\`\`

## Agendamento local

O Laravel Scheduler executa o comando LinkedIn às 10:00 e 14:00 em \`America/Sao_Paulo\`. As fontes tradicionais permanecem com seu agendamento atual.

Para manter o scheduler ativo em desenvolvimento, deixe este processo rodando em um terminal:

\`\`\`bash
php artisan schedule:work
\`\`\`

Em produção Linux, configure o cron padrão do Laravel para executar \`php artisan schedule:run\` a cada minuto. O workflow GitHub Actions desta integração fica apenas com execução manual e não dispara coletas agendadas.
