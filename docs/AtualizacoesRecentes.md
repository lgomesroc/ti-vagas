# Atualizações Recentes do Projeto

Este documento registra alterações relevantes incorporadas à branch `main`. O histórico não substitui os documentos técnicos específicos nem representa garantia de disponibilidade contínua das plataformas externas.

## 1. Atualizações de 27/11/2026

### Aplicativo Progressivo (PWA)

O portal passou a poder ser instalado como aplicativo em celulares, tablets e computadores.

- Manifesto `public/manifest.webmanifest`, ícones em `public/icons/` e metadados no layout;
- Service worker `public/sw.js` com cache somente de arquivos estáticos; páginas HTML nunca são armazenadas, preservando sessão, CSRF, consentimento e regionalização;
- Página `public/offline.html` exibida em navegações sem conexão;
- Botão "Instalar app" no cabeçalho, exibido apenas quando o navegador oferece a instalação;
- Cabeçalhos do manifesto e do service worker em `public/.htaccess`;
- Testes em `tests/Feature/PwaTest.php`.

Nenhum arquivo ou pasta existente foi removido. Detalhes em [Aplicativo Progressivo (PWA)](AplicativoPWA.md).

#### Ajustes do service worker do PWA

- Somente respostas com status exatamente 200 são gravadas (antes, qualquer 2xx; respostas 206 causavam erro na Cache API);
- Falha de rede sem cópia em cache passa a responder 503, em vez de erro no `respondWith`;
- Navegação sem conexão com `offline.html` ausente do cache responde com página mínima 503;
- Cache separado para `/build/assets/`, limitado a 60 arquivos;
- Instalação com falha no pré-cache apaga o cache incompleto e mantém a versão anterior; a ativação remove todos os caches que não pertencem à versão atual;
- Registro com `updateViaCache: 'none'`;
- Novo teste de comportamento real no Chromium (`npm run test:pwa`), incluído no CI;
- Seção própria do PWA no `README.md` e árvore de `docs/EstruturaDoProjeto.md` regenerada.

### Reativação do workflow de publicações de recrutamento do LinkedIn

O arquivo `.github/workflows/linkedin-posts.yml` foi restaurado como workflow operacional na branch `main`.

- Agenda diária às 00h e 12h, com timezone `America/Sao_Paulo`;
- Disparo manual por `workflow_dispatch`;
- `LINKEDIN_POSTS_ENABLED=true`;
- Janela de consulta de 168 horas;
- Restauração do estado de sessão por secret;
- Conta secundária desabilitada nesse workflow, permanecendo em fluxo separado.

O workflow de CI offline continua separado em `linkedin-posts-ci.yml`. A execução operacional não deve ser confundida com testes automatizados.

### Atualização do inventário da documentação

Os documentos de arquitetura, funcionalidades, instalação, tecnologias, automação, LinkedIn, workflows e estrutura foram revisados com base no conteúdo da branch `main`. A árvore de arquivos foi regenerada a partir da árvore Git dessa branch.

## 2. Atualizações recentes de integrações e testes

### InHire — tenants adicionais

O coletor global `InhireGlobalSource` inclui tenants identificados para consulta, entre eles Venturus e Programmers. A coleta utiliza identidade global e combina tenants configurados com identificadores descobertos em dados já armazenados.

A inclusão de um tenant no código não comprova que a empresa tenha vagas disponíveis nem que cada consulta externa tenha sucesso. O workflow isolado `inhire.yml` executa `vacancies:collect-inhire`.

### Gupy — YDUQS Tech

A empresa YDUQS Tech foi adicionada à configuração legada de empresas Gupy. A coleta operacional atual, entretanto, utiliza o mecanismo global `GupyGlobalSource`; a presença de um cadastro individual não representa um workflow individual para a empresa.

### Teste de data original da Sólides

O fixture do teste de coleta Sólides passou a construir a data de publicação explicitamente em UTC (`now('UTC')`), evitando diferença de dia causada pelo timezone local no teste.

## 3. Coletores e fluxos atuais

- **Gupy:** coleta global pela API pública, com comando e workflow próprios.
- **InHire:** coletor global com lista inicial de tenants e descoberta complementar; também possui workflow de coleta isolada.
- **Abler:** coleta global por navegação com Playwright e workflow próprio.
- **ProgramaThor:** adaptador próprio, com janela inicial de 60 dias e limites de consulta.
- **NortJobs:** adaptador próprio, com diagnóstico das etapas de catálogo, detalhes, filtros editoriais e localização.
- **LinkedIn Jobs:** fluxo independente com fila, histórico de IDs consultados, limites de requisição e workflows geral e dedicados a BRQ, CAST e GFT.
- **Publicações de recrutamento do LinkedIn:** descoberta pública, enriquecimento, validação editorial e publicação automática dos candidatos aprovados; conta secundária em workflow separado.

A lista efetivamente habilitada e os parâmetros de cada integração devem ser consultados em `config/vacancy_sources.php`, `config/linkedin.php` e nos workflows. A existência de uma classe não significa, por si só, que esteja habilitada na coleta geral.

## 4. Critérios de interpretação

- Um adaptador cadastrado não comprova que a API ou o portal externo esteja respondendo.
- Um workflow ativo não comprova que todas as execuções agendadas tenham ocorrido.
- Um run verde comprova a conclusão técnica daquele run, não a cobertura total da fonte.
- Um anúncio importado pode estar encerrado ou ter informações incompletas.
- Os filtros editoriais continuam sendo aplicados pelo TI Vagas, independentemente das classificações ou filtros apresentados pela fonte externa.

## 5. Documentos relacionados

- [Arquitetura e Fluxo de Dados](ArquiteturaEFluxoDeDados.md)
- [Coleta e Integração de Vagas](ColetaEIntegracaoDeVagas.md)
- [Coleta de Oportunidades no LinkedIn](ColetaPublicacoesLinkedIn.md)
- [Automação e Testes](AutomacaoETestes.md)
- [Workflows do GitHub Actions](WorkflowsGitHubActions.md)
- [Estrutura do Projeto](EstruturaDoProjeto.md)
