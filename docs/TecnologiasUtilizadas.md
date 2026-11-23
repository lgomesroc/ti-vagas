# Tecnologias utilizadas

Esta página resume a stack identificada no projeto. A versão efetivamente instalada deve ser conferida nos arquivos de dependências e na configuração do ambiente.

| Tecnologia | Responsabilidade |
|---|---|
| PHP 8.3 | Linguagem principal do backend |
| Laravel 13 | Framework da aplicação |
| PostgreSQL | Banco de dados relacional |
| Blade | Renderização das páginas |
| Tailwind CSS | Estilização da interface |
| Vite | Compilação dos recursos frontend |
| Node.js | Ferramentas e scripts auxiliares, incluindo coletores que utilizam navegador |
| Playwright | Automação de navegador nos fluxos que dependem de páginas renderizadas |
| Laravel Scheduler | Agendamento das rotinas |
| Laravel Queue | Processamento assíncrono de jobs |
| PHPUnit | Testes automatizados |
| Git | Controle de versão |
| GitHub Actions | Integração contínua e automação operacional |

## Arquivos de referência

- `composer.json` e `composer.lock`: dependências PHP.
- `package.json` e `package-lock.json`: dependências Node.js e scripts.
- `vite.config.js`: configuração de build frontend.
- `phpunit.xml`: configuração da suíte de testes.
- `.github/workflows/`: workflows de CI e automação.

Não atualize versões apenas com base neste resumo. Verifique compatibilidade, lockfiles e execução da suíte de testes antes de integrar mudanças.
