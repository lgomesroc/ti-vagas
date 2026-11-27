# Instalação e Configuração

## Requisitos

- PHP 8.3 ou superior;
- Composer;
- Node.js 22 ou superior;
- npm;
- PostgreSQL;
- Git.

Algumas integrações que utilizam páginas renderizadas também precisam do Chromium instalado pelo Playwright.

## Clonar o repositório

```bash
git clone https://github.com/lgomesroc/ti-vagas.git
cd ti-vagas
```

## Instalar dependências

```bash
composer install
npm ci
```

## Configurar o ambiente

```bash
cp .env.example .env
php artisan key:generate
```

Configure no arquivo `.env` a conexão PostgreSQL e as demais opções necessárias. Use `.env.example` como referência. Não versione credenciais, tokens, cookies ou secrets.

Crie o banco de desenvolvimento e execute as migrations:

```bash
php artisan migrate
```

## Importar dados geográficos

O projeto mantém a base municipal de coordenadas em `database/data/municipios.csv` e os arquivos oficiais da DTB 2025 em `ibge/extraido/`. A rotina PHP lê a planilha ODS versionada e utiliza a extensão PHP `zip`.

Com o banco configurado, execute:

```bash
php artisan geo:import-ibge
php artisan vacancies:resolve-municipalities
```

A primeira rotina carrega os municípios e os dados de regiões geográficas, aplicando os ajustes configurados em `config/geo_regions.php`. A segunda tenta resolver os municípios das vagas já importadas. Execute a importação geográfica quando preparar um banco novo ou quando precisar reconstruir essa base.

O script PowerShell `scripts/ibge/import-dtb-2025.ps1` é um utilitário de exportação auxiliar. O comando `geo:import-ibge` utiliza os arquivos versionados do projeto; não é necessário executar o script PowerShell como etapa obrigatória da instalação.

## Compilar os recursos frontend

```bash
npm run build
```

Durante o desenvolvimento frontend, pode-se utilizar:

```bash
npm run dev
```

## Executar localmente

```bash
php artisan serve
```

Por padrão, a aplicação fica disponível em `http://127.0.0.1:8000`. O localhost é tratado pelos navegadores modernos como contexto seguro para recursos de localização.

## Banco exclusivo para testes locais

Os testes automatizados **não devem utilizar o banco definido para a aplicação**. O `phpunit.xml` força a conexão PostgreSQL e o banco `ti_vagas_test`, evitando acesso acidental ao banco de desenvolvimento ou produção.

Crie um banco exclusivo:

```sql
CREATE DATABASE ti_vagas_test;
```

Copie o modelo de ambiente de testes:

```powershell
Copy-Item .env.testing.example .env.testing
```

No `.env.testing`, ajuste `DB_HOST`, `DB_PORT`, `DB_USERNAME` e `DB_PASSWORD` conforme a instalação local. O arquivo é ignorado pelo Git. Não informe nele URLs ou credenciais do Neon, Prisma ou banco de produção.

Execute os testes:

```bash
php artisan test
```

Para reproduzir a suíte executada pelo CI principal:

```bash
php artisan test --compact --display-warnings --testsuite=CI
```

## Coleta manual

Os comandos operacionais consultam fontes externas e podem gravar registros no banco configurado. Execute-os somente quando essa alteração de dados for intencional.

```bash
php artisan vacancies:collect
php artisan vacancies:collect --sync
php artisan vacancies:collect-gupy
php artisan vacancies:collect-inhire
php artisan vacancies:collect-source abler-global
```

A coleta geral despacha jobs para a fila. A opção `--sync` executa as fontes da fábrica diretamente. Gupy, InHire e Abler também possuem comandos dedicados.

## Cuidados

- Confirme sempre o banco selecionado antes de executar comandos de coleta ou migrations.
- Não utilize o banco de produção para testes.
- Não compartilhe arquivos `.env`, tokens, cookies ou estados de sessão.
- Para detalhes de filas, scheduler e workflows, consulte [Automação e Testes](AutomacaoETestes.md) e [Workflows do GitHub Actions](WorkflowsGitHubActions.md).
