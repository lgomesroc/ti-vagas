# Aplicativo Progressivo (PWA)

O TI Vagas pode ser instalado como aplicativo no celular, tablet ou computador por meio da tecnologia **PWA (Progressive Web App)**. A instalação cria um ícone na tela inicial ou no menu do sistema e abre o portal em janela própria, sem a barra de endereços do navegador.

O PWA **não altera** as regras do portal: os termos, o consentimento de cookies, a validação de localização no servidor, a reputação de rede e os filtros editoriais continuam sendo aplicados a cada acesso, exatamente como no navegador.

## 1. O que foi implementado

| Recurso | Descrição |
|---|---|
| Manifesto | `public/manifest.webmanifest` com nome, cores, ícones, escopo, modo `standalone` e atalho para a listagem. |
| Ícones | PNG 192×192 e 512×512 (uso geral), versões *maskable* para Android e `apple-touch-icon` 180×180 para iOS, todos derivados de `public/favicon.svg`. |
| Service worker | `public/sw.js`, registrado no escopo `/`, com cache apenas de arquivos estáticos e página offline. |
| Página offline | `public/offline.html`, estática, exibida quando uma navegação falha por falta de conexão. |
| Botão "Instalar app" | Exibido no cabeçalho somente quando o navegador oferece a instalação (`beforeinstallprompt`). |
| Metadados | `<link rel="manifest">`, `theme-color`, `apple-touch-icon` e metatags `apple-mobile-web-app-*` no layout. |
| Configuração do Apache | Tipo `application/manifest+json` e cabeçalhos de cache em `public/.htaccess`. |
| Testes | `tests/Feature/PwaTest.php`. |

## 2. Arquivos envolvidos

Todos os arquivos existentes foram preservados; a funcionalidade foi adicionada sem excluir arquivos ou pastas.

**Novos arquivos**

```text
public/
├── icons/
│   ├── apple-touch-icon.png
│   ├── icon-192.png
│   ├── icon-512.png
│   ├── icon-maskable-192.png
│   ├── icon-maskable-512.png
│   └── icon-maskable.svg
├── manifest.webmanifest
├── offline.html
└── sw.js
resources/views/partials/
├── pwa-head.blade.php
└── pwa-register.blade.php
tests/Feature/PwaTest.php
docs/AplicativoPWA.md
```

**Arquivos alterados (somente acréscimos)**

- `resources/views/layouts/app.blade.php` — inclui `partials.pwa-head` no `<head>`, o botão "Instalar app" no cabeçalho (agrupado com o link existente em `.header-actions`), os estilos do botão e `partials.pwa-register` antes de `</body>`.
- `public/.htaccess` — bloco ao final com o tipo MIME do manifesto e os cabeçalhos de cache. As regras de reescrita originais não foram modificadas.
- `README.md`, `docs/AtualizacoesRecentes.md`, `docs/FuncionalidadesDoPortal.md` e `docs/EstruturaDoProjeto.md` — referências a este documento.

A página `resources/views/network/denied.blade.php` (acesso bloqueado por política de rede) é independente do layout e, de propósito, não recebe os recursos do PWA.

## 3. Estratégia de cache do service worker

A principal decisão de projeto é **nunca guardar páginas HTML em cache**. Cada página do TI Vagas depende de dados que pertencem à sessão e ao momento do acesso:

- token CSRF dos formulários de aceite, cookies e localização (uma cópia antiga geraria erro 419);
- aceite dos termos e escolha de cookies não essenciais;
- região validada no servidor, que define quais vagas presenciais e híbridas aparecem;
- disponibilidade das vagas, que muda com a coleta e a verificação de links.

Por isso, o service worker aplica as regras abaixo:

| Requisição | Tratamento |
|---|---|
| Método diferente de `GET` (POST de aceite, cookies e localização) | Não interceptada. |
| Outra origem (Google Analytics, AdSense, fontes externas) | Não interceptada. |
| Navegação (abrir uma página) | Sempre pela rede, **sem gravar** a resposta. Sem conexão, responde com `offline.html`. |
| `/build/assets/*` (arquivos do Vite com hash no nome) | *Cache first*: o conteúdo de um arquivo com hash nunca muda. |
| `/icons/*`, `/favicon.svg`, `/favicon.ico`, `/manifest.webmanifest`, `/offline.html` | *Stale-while-revalidate*: responde do cache e atualiza em segundo plano. |
| Qualquer outra requisição | Comportamento padrão do navegador. |

Somente respostas `200` da própria origem (`response.type === 'basic'`) são gravadas.

### Pré-cache

Na instalação, o service worker grava no cache `ti-vagas-static-v1` a página offline, o favicon, o manifesto e os ícones (`PRECACHE_URLS`). As requisições usam `cache: 'reload'` para não reaproveitar cópias antigas do cache HTTP.

### Ciclo de vida e atualização

- `install`: faz o pré-cache e chama `skipWaiting()`. Como nenhuma página fica em cache, ativar a nova versão de imediato não deixa abas com conteúdo misturado.
- `activate`: remove caches antigos com prefixo `ti-vagas-` e assume o controle das abas abertas (`clients.claim()`).
- `message`: aceita `{ type: 'SKIP_WAITING' }` para uso futuro.

**Para publicar mudanças nos arquivos pré-armazenados** (por exemplo, novos ícones ou novo texto da página offline), altere a constante `CACHE_VERSION` em `public/sw.js` (ex.: `'v1'` → `'v2'`). O navegador detecta a mudança no `sw.js`, instala a nova versão e descarta o cache anterior.

## 4. Registro e instalação

O registro acontece em `resources/views/partials/pwa-register.blade.php`, após o evento `load`, para não competir com o carregamento da página:

- só ocorre em contexto seguro (`https` ou `localhost`) e em navegadores com suporte a service worker;
- uma falha no registro apenas gera um aviso no console e não afeta o uso do portal;
- o service worker é servido a partir da raiz (`/sw.js`), o que permite o escopo `/` sem configuração adicional.

### Botão "Instalar app"

O botão `#pwa-install-button` começa com o atributo `hidden`. Ele aparece apenas quando o navegador dispara `beforeinstallprompt` (Chrome, Edge e navegadores baseados no Chromium, inclusive no Android). Ao clicar, o diálogo nativo de instalação é exibido. Depois da instalação (`appinstalled`) ou da resposta do visitante, o botão volta a ficar oculto.

Como o `.button` do layout define `display: inline-block`, a regra `.pwa-install-button[hidden] { display: none; }` garante que o botão permaneça realmente oculto.

### Como instalar

| Plataforma | Passos |
|---|---|
| Android (Chrome) | Botão "Instalar app" no cabeçalho, ou menu ⋮ → "Instalar app" / "Adicionar à tela inicial". |
| Computador (Chrome/Edge) | Botão "Instalar app" no cabeçalho, ou ícone de instalação na barra de endereços. |
| iPhone/iPad (Safari) | Botão Compartilhar → "Adicionar à Tela de Início". O Safari não dispara `beforeinstallprompt`, por isso o botão do cabeçalho não aparece. |

Para desinstalar, remova o aplicativo como qualquer outro do sistema. Os dados da sessão seguem as mesmas regras dos cookies do navegador.

## 5. Funcionamento sem conexão

O TI Vagas não oferece navegação offline das vagas. Elas são consultadas em tempo real e dependem da região validada, então guardá-las no aparelho mostraria informações desatualizadas ou de outra região.

Sem conexão, qualquer navegação exibe `offline.html`, que:

- informa que não há conexão e por que as vagas não ficam salvas;
- oferece o botão "Tentar novamente";
- recarrega automaticamente a página quando o evento `online` é disparado;
- não contém formulários, token CSRF ou dados de sessão.

O endereço da aba é preservado, então a nova tentativa abre a mesma página que o visitante tentou acessar.

## 6. Manifesto

Principais campos de `public/manifest.webmanifest`:

| Campo | Valor |
|---|---|
| `id` / `start_url` / `scope` | `/` |
| `name` | TI Vagas — Oportunidades em Tecnologia |
| `short_name` | TI Vagas |
| `display` | `standalone` (com `display_override`: `standalone`, `minimal-ui`, `browser`) |
| `lang` | `pt-BR` |
| `theme_color` | `#172554` (mesma cor do cabeçalho e da metatag `theme-color`) |
| `background_color` | `#f5f7fa` (mesma cor de fundo do layout) |
| `shortcuts` | "Encontrar oportunidades" → `/` |

O `start_url` aponta para `/`. Se o visitante ainda não aceitou os termos, o middleware `welcome.accepted` o redireciona para `/boas-vindas`, como em qualquer acesso pelo navegador. Não foram adicionados parâmetros UTM ao `start_url`, para não alterar a atribuição de campanhas registrada por `CaptureUtmParameters`.

## 7. Servidor (Apache)

Os arquivos do PWA ficam em `public/` e são entregues diretamente pelo Apache (regra `RewriteCond %{REQUEST_FILENAME} !-f`), sem passar pelos middlewares do Laravel. O bloco acrescentado em `public/.htaccess` define:

- `AddType application/manifest+json .webmanifest`;
- `sw.js` com `Cache-Control: no-cache, no-store, must-revalidate` e `Service-Worker-Allowed: /`, para que o navegador sempre encontre a versão mais recente;
- `manifest.webmanifest` com cache de 1 hora;
- `offline.html` com `Cache-Control: no-cache`.

As diretivas estão dentro de `<IfModule mod_mime.c>` e `<IfModule mod_headers.c>`. O `Dockerfile` já habilita `mod_headers` (`a2enmod rewrite headers`) e usa `AllowOverride All`, portanto nenhuma alteração no `Dockerfile` foi necessária.

O PWA exige HTTPS em produção, o que já é garantido pelo middleware `ForceHttps` e pela hospedagem.

## 8. Privacidade e consentimento

- O service worker não coleta, não envia e não armazena dados pessoais.
- O cache contém apenas arquivos públicos (ícones, manifesto, favicon e página offline).
- Scripts do Google Analytics e do AdSense não são interceptados e continuam carregando somente após o consentimento para cookies não essenciais.
- Localização, sessão e aceite dos termos continuam exclusivamente no servidor e nos cookies do navegador.

## 9. Testes

`tests/Feature/PwaTest.php` verifica:

- campos obrigatórios do manifesto, incluindo ícones 192/512 e ícone *maskable*;
- existência de todos os ícones referenciados e as dimensões reais dos PNG;
- que o service worker trata apenas `GET` da mesma origem, usa a página offline e **não grava** navegações no cache;
- que todos os arquivos de `PRECACHE_URLS` existem;
- que a página offline é estática, sem formulários ou CSRF;
- que o layout inclui o manifesto, o `apple-touch-icon`, o `theme-color`, o registro do service worker e o botão de instalação inicialmente oculto;
- que o `.htaccess` define o tipo do manifesto e os cabeçalhos do `sw.js`, preservando a regra do front controller.

```bash
php artisan test --filter=PwaTest
```

Também foi feita uma validação manual com Chromium (Playwright): registro e ativação do service worker, conteúdo do cache, exibição do botão de instalação e resposta offline em uma navegação sem rede.

### Verificação manual no navegador

1. Abra o portal em `https` (ou `http://localhost`).
2. DevTools → **Application** → **Manifest**: confira nome, ícones e ausência de erros de instalação.
3. **Application** → **Service workers**: `sw.js` deve estar *activated and is running*.
4. **Application** → **Cache storage** → `ti-vagas-static-v1`: apenas arquivos estáticos.
5. **Network** → marque **Offline** e navegue: deve aparecer "Você está sem conexão".
6. Opcional: execute o **Lighthouse** na categoria PWA.

## 10. Limitações

- Não há notificações *push* nem sincronização em segundo plano.
- As vagas não ficam disponíveis offline (decisão intencional, ver seção 5).
- A disponibilidade do botão de instalação depende do navegador; no Safari, a instalação é feita pelo menu Compartilhar.
- Navegadores sem suporte a service worker continuam usando o portal normalmente, sem os recursos do PWA.

## Documentação relacionada

- [Funcionalidades do Portal](FuncionalidadesDoPortal.md)
- [Consentimento e Atribuição de Campanhas](ConsentimentoEAtribuicao.md)
- [Segurança de Rede e Localização](SegurancaDeRedeELocalizacao.md)
- [Tecnologias Utilizadas](TecnologiasUtilizadas.md)
- [Atualizações Recentes](AtualizacoesRecentes.md)
