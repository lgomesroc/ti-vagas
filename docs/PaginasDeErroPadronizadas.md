# Páginas de Erro Padronizadas

## Objetivo

O TI Vagas apresenta páginas de erro próprias para orientar o visitante quando uma solicitação não pode ser concluída. A interface informa o problema em linguagem simples, oferece um caminho de retorno e não expõe detalhes internos da aplicação.

A implementação utiliza o mecanismo nativo de tratamento de exceções e renderização de views do Laravel. Não adiciona dependências, serviços externos ou rotas públicas específicas para os erros.

## Princípios adotados

- **Escopo do produto:** só existem páginas específicas para situações pertinentes ao funcionamento do TI Vagas.
- **Interface independente:** as views de erro não estendem `layouts.app`. Assim, não dependem do fluxo de aceite, sessão, localização, consultas ao banco, Analytics, AdSense ou scripts da aplicação.
- **Identidade visual consistente:** layout responsivo, acessível e com CSS local.
- **Privacidade e segurança:** não exibir stack trace, mensagens de exceção, SQL, caminhos internos, variáveis de ambiente, tokens ou credenciais.
- **Configuração de produção:** manter `APP_DEBUG=false` no ambiente de produção. Com o modo de depuração ativado, o Laravel pode apresentar páginas detalhadas de exceção em vez das páginas amigáveis. O `APP_DEBUG=true` do ambiente local não deve ser copiado para produção.
- **Status preservado:** cada página é renderizada com o código HTTP original; a página não transforma uma falha em resposta `200 OK`.
- **Negociação de conteúdo preservada:** requisições que esperam JSON continuam sob o tratamento JSON configurado em `bootstrap/app.php`; as páginas HTML não substituem respostas de API.
- **Sem dependências adicionais:** não foi instalado pacote para tratar páginas de erro.

## Arquivos

As views ficam em `resources/views/errors/`:

| Arquivo | Finalidade |
|---|---|
| `layout.blade.php` | Estrutura visual compartilhada, metadados, acessibilidade e estilos locais. |
| `400.blade.php` | Solicitação inválida. |
| `404.blade.php` | Página ou recurso não encontrado. |
| `405.blade.php` | Método HTTP não permitido. |
| `419.blade.php` | Sessão ou token CSRF expirado. |
| `429.blade.php` | Limite de requisições atingido. |
| `500.blade.php` | Falha interna inesperada. |
| `503.blade.php` | Serviço temporariamente indisponível. |
| `4xx.blade.php` | Fallback para demais erros de cliente sem página específica. |
| `5xx.blade.php` | Fallback para demais erros de servidor sem página específica. |

Não foram criadas páginas próprias para 401, 403 ou 402: autenticação, autorização de usuários e cobrança não fazem parte do fluxo do portal. Os demais códigos 4xx/5xx são cobertos pelos fallbacks quando a resposta passa pelo Laravel.

## Matriz de tratamento

| Código | Tratamento | Mensagem / comportamento |
|---|---|---|
| 400 | Página específica | Solicitação inválida. |
| 404 | Página específica | Endereço ou recurso não encontrado. |
| 405 | Página específica | Método HTTP não permitido. |
| 419 | Página específica | Sessão ou token CSRF expirado. |
| 429 | Página específica | Muitas solicitações em pouco tempo. |
| 500 | Página específica | Falha interna inesperada. |
| 503 | Página específica | Serviço temporariamente indisponível. |
| Outros 4xx | `4xx.blade.php` | Mensagem genérica de solicitação não concluída. |
| Outros 5xx | `5xx.blade.php` | Mensagem genérica de instabilidade. |

### Observações importantes sobre o fluxo atual

- O middleware `ForceHttps` recusa métodos diferentes de GET/HEAD quando a requisição chega por HTTP. Essa resposta é um 400 produzido diretamente pelo middleware, e por isso ele renderiza explicitamente `errors.400` com status 400.
- O Laravel pode redirecionar erros de validação de formulários HTML de volta à página anterior, em vez de apresentar uma página 422. Esse comportamento normal não deve ser substituído globalmente. Quando um 422 for efetivamente renderizado como erro HTML, o fallback 4xx será utilizado.
- O limite de requisições continua sendo aplicado pelos middlewares existentes. A view 429 altera somente a apresentação HTML, sem remover nem relaxar o controle de tráfego.
- Os fallbacks são aplicados pelo mecanismo de exceções do Laravel quando a requisição chega à aplicação e a resposta é renderizada como HTML.

## Limite entre aplicação e infraestrutura

Uma página Blade só pode ser renderizada se a requisição chegar ao Laravel. Erros como 502, 503 ou 504 podem ser produzidos pelo proxy, balanceador ou plataforma de hospedagem antes de o PHP receber a requisição.

Nesses casos, as views do repositório não conseguem substituir a página apresentada. A personalização de erros gerados na borda depende dos recursos de página de erro da própria hospedagem ou de uma camada de proxy/CDN controlada pelo projeto. Não se deve afirmar que a view Laravel cobre falhas geradas externamente.

Quando o Laravel recebe e renderiza um erro 502 ou 504, o fallback 5xx é utilizado. O 503 possui uma view própria para os casos tratados pela aplicação.

## Layout e conteúdo

O layout de erro é intencionalmente independente de `resources/views/layouts/app.blade.php`. O layout principal contém integrações e dependências que não são necessárias para comunicar uma falha e podem não estar disponíveis durante um erro.

As páginas:
- usam o idioma `pt-BR`;
- incluem viewport para dispositivos móveis;
- utilizam `noindex, nofollow`;
- oferecem um link para a rota pública `welcome`;
- não carregam Analytics, AdSense, manifesto, service worker ou scripts de terceiros;
- não exibem conteúdo técnico da exceção.

A página de erro não deve executar consultas ao banco nem depender de sessão ou de cookies para ser renderizada.

## Testes automatizados

O arquivo `tests/Feature/ErrorPagesTest.php` verifica:

1. Renderização do 400 produzido pelo middleware HTTPS, preservando o status;
2. Página 404 para rota inexistente;
3. Página 405 para método não permitido;
4. Páginas específicas 419, 429, 500 e 503;
5. Fallbacks 4xx e 5xx para códigos sem view específica;
6. Ausência de mensagens internas e detalhes técnicos na resposta 500.

Execute somente os testes relacionados às páginas de erro:

```bash
php artisan test --compact --filter=ErrorPagesTest
php artisan test --compact --filter=ForceHttpsTest
```

Os testes não devem depender de serviços externos. A suíte completa pode ser executada separadamente conforme o procedimento geral documentado em [Automação e Testes](AutomacaoETestes.md).

## Manutenção

Ao adicionar um novo código HTTP:

1. Verifique se o fluxo realmente pode produzir esse status no TI Vagas.
2. Prefira o fallback 4xx ou 5xx se uma mensagem específica não trouxer benefício real.
3. Crie uma view individual apenas quando houver orientação própria e útil ao visitante.
4. Preserve o status HTTP e o comportamento JSON.
5. Inclua ou atualize testes para o novo comportamento.
6. Não exponha detalhes técnicos e não faça a view depender do layout principal.
7. Atualize esta documentação e o índice no README quando a estrutura mudar.
