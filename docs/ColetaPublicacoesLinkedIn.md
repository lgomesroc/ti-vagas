# Coleta de publicações públicas do LinkedIn

## Escopo

A coleta pesquisa publicações públicas indexadas no LinkedIn por três mecanismos independentes/complementares: DuckDuckGo, Brave Search e Mojeek. Não pesquisa LinkedIn Jobs, não usa login, senha ou cookies pessoais do LinkedIn.

O DuckDuckGo é consultado pela página HTML pública de resultados. Brave Search e Mojeek são consultados por suas APIs oficiais. A integração não usa Bing nem Yahoo.

A descoberta é feita por palavras-chave e restrita a URLs de posts do LinkedIn. Os resultados dos três provedores são combinados e deduplicados pela URL da publicação. O mecanismo de busca não garante cobertura integral, nem acesso a publicações recentes ainda não indexadas.

## Configuração

No arquivo `.env`:

```dotenv
LINKEDIN_POSTS_ENABLED=true
LINKEDIN_POSTS_LOOKBACK_HOURS=72
BRAVE_SEARCH_API_KEY=
MOJEEK_SEARCH_API_KEY=
```

- DuckDuckGo: não exige chave nesta integração.
- Brave Search: exige uma chave da API Web Search.
- Mojeek: exige uma chave da Search API.

Sem a chave de um provedor, ele é ignorado e os demais continuam funcionando. Para habilitar todos os três, configure as duas chaves. Em GitHub Actions, cadastre-as como secrets `BRAVE_SEARCH_API_KEY` e `MOJEEK_SEARCH_API_KEY`.

As chaves devem permanecer em variáveis de ambiente/secrets; nunca devem ser commitadas no repositório.

## Fluxo

1. Consultar os três buscadores para cada palavra-chave configurada.
2. Manter URLs de publicações do LinkedIn, descartando perfis, páginas de vagas e outros destinos.
3. Extrair datas relativas explícitas dos títulos/trechos e manter somente resultados dentro da janela configurada. Sem data verificável, o resultado não entra no banco.
4. Combinar e deduplicar os resultados pela URL, registrando o provedor de origem e os provedores adicionais que também encontraram o post.
5. Aplicar filtros de recrutamento, área, senioridade, experiência, localização e modalidade.
6. Procurar links e e-mails no texto disponível; se houver link, seguir redirecionamentos, bloquear agregadores e extrair conteúdo público.
7. Deduplicar contra vagas existentes e candidatos coletados.
8. Gravar candidatos em `linkedin_post_candidates`, sem publicação automática.

## Limitações importantes

- Os buscadores indexam apenas parte do conteúdo público. A coleta pode retornar poucos resultados ou nenhum.
- A API de busca retorna títulos, URLs e trechos; não equivale à leitura integral da publicação original.
- A pesquisa pública não fornece comentários completos de forma confiável. Nesta implementação, comentários não são obtidos. Portanto, uma publicação que dependa de link/e-mail escondido em comentários pode passar despercebida; não se deve presumir que a ausência de link no resultado significa ausência no post original.
- Autor, headline e data exata nem sempre aparecem nos resultados. Sem data legível no trecho, o resultado é descartado para não inventar data.
- Datas fornecidas pelos buscadores podem representar atualização ou rastreamento da página, não necessariamente a publicação original. Por isso, a integração não as trata como data de publicação confirmada.
- A extração de cargo, empresa e localidade é heurística e exige revisão manual.
- Não há contorno de autenticação, CAPTCHA, bloqueios ou controles do LinkedIn.

## Migração e teste manual

```bash
php artisan migrate
php artisan test --filter=LinkedInPostSearchClientTest
php artisan linkedin:collect-posts
php artisan linkedin:review list
```

Para inspecionar um candidato:

```bash
php artisan linkedin:review show ID
```

Aprovação e rejeição permanecem manuais:

```bash
php artisan linkedin:review approve ID
php artisan linkedin:review reject ID --reason="Motivo"
```

## Agendamento local

O Laravel Scheduler executa o comando LinkedIn às 10:00 e 14:00 em `America/Sao_Paulo`. As fontes tradicionais permanecem com seu agendamento atual.

Para manter o scheduler ativo em desenvolvimento, deixe este processo rodando em um terminal:

```bash
php artisan schedule:work
```

Em produção Linux, configure o cron padrão do Laravel para executar `php artisan schedule:run` a cada minuto. O workflow GitHub Actions desta integração também pode executar a coleta conforme o agendamento configurado no próprio arquivo.
