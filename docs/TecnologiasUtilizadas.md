# Tecnologias Utilizadas

Esta página descreve as tecnologias identificadas na branch `main`. As versões exatas devem ser conferidas nos arquivos de dependências e no ambiente de execução.

## Stack principal

| Tecnologia | Responsabilidade |
|---|---|
| PHP 8.3 | Linguagem principal do backend |
| Laravel 13 | Framework HTTP, serviços, comandos, filas e scheduler |
| PostgreSQL | Banco de dados relacional |
| Eloquent ORM | Persistência e consultas |
| Blade | Renderização das páginas no servidor |
| Tailwind CSS 4 | Estilização da interface |
| Vite 8 | Build e desenvolvimento dos recursos frontend |
| PWA (Web App Manifest e Service Worker) | Instalação como aplicativo e página offline; ver [Aplicativo Progressivo (PWA)](AplicativoPWA.md) |
| Node.js 22 | Scripts auxiliares e automações JavaScript |
| Playwright | Automação de navegador para integrações que dependem de páginas renderizadas |
| Laravel Scheduler | Agendamento de rotinas |
| Laravel Queue | Processamento assíncrono de fontes e jobs |
| PHPUnit 12 | Testes PHP |
| Node.js Test Runner | Testes automatizados dos scripts JavaScript |
| GitHub Actions | Integração contínua, coleta e rotinas operacionais |
| Render | Hospedagem da aplicação web |

## Organização dos recursos

- **Backend:** `app/Http`, `app/Services`, `app/Jobs`, `app/Console` e `app/Sources`.
- **Configuração:** `config/`, `.env.example` e `render.yaml`.
- **Interface:** `resources/views`, `resources/css` e `resources/js`.
- **Persistência:** `database/migrations`, `database/seeders` e `database/data`.
- **Automação:** `.github/workflows` e `scripts/`.
- **Testes:** `tests/Feature`, `tests/Unit` e testes Node em `scripts/linkedin`.

## Arquivos de referência

- `composer.json` e `composer.lock`: dependências PHP e scripts Composer.
- `package.json` e `package-lock.json`: dependências Node.js e scripts npm.
- `vite.config.js`: configuração do Vite.
- `phpunit.xml`: suítes e variáveis de ambiente dos testes PHP.
- `.env.example` e `.env.testing.example`: modelos de configuração.
- `.github/workflows/`: CI e automações.
- `Dockerfile` e `render.yaml`: build e configuração de hospedagem.

## Observações

Playwright é utilizado pelos coletores que precisam navegar por páginas renderizadas; não é requisito para a renderização normal das páginas Blade.

Não atualize versões apenas com base neste resumo. Verifique compatibilidade, lockfiles, ambiente de produção e execução dos testes antes de integrar mudanças.
