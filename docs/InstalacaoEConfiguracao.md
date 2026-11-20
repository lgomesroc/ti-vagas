# Instalação e Configuração

## Requisitos

- PHP 8.3 ou superior;
- Composer;
- Node.js 22 ou superior;
- PostgreSQL;
- Git.

## Clonar o repositório

```bash
git clone https://github.com/lgomesroc/ti-vagas.git
cd ti-vagas
```

## Instalar dependências

```bash
composer install
npm install
```

## Configurar o ambiente

```bash
cp .env.example .env
php artisan key:generate
```

Configure no arquivo `.env` as variáveis de conexão com o PostgreSQL e demais opções necessárias. Use `.env.example` como referência; não versione credenciais ou segredos.

## Banco de dados e geografia

Crie o banco e ajuste a conexão antes de executar:

```bash
php artisan migrate

# Gere o CSV oficial a partir do ZIP DTB 2025 (PowerShell/Windows):
.\scripts\ibge\import-dtb-2025.ps1

php artisan geo:import-ibge
php artisan vacancies:resolve-municipalities
```

A importação utiliza o arquivo `database/data/ibge/municipios_dtb_2025.csv`, gerado a partir de `ibge/DTB_2025.zip` pelo script PowerShell versionado em `scripts/ibge/import-dtb-2025.ps1`. O CSV normalizado deve ser incluído no repositório para que o Docker e os ambientes de produção também tenham a base territorial. A rotina não consulta a API do IBGE.

## Banco exclusivo para testes locais

Os testes automatizados **não utilizam o banco definido no `.env`**. O `phpunit.xml` força a conexão `pgsql` e o banco `ti_vagas_test`, evitando que a suíte acesse acidentalmente o banco de desenvolvimento ou produção.

Crie um banco PostgreSQL local exclusivo para testes:

```sql
CREATE DATABASE ti_vagas_test;
```

Copie o modelo de ambiente de testes e informe as credenciais do PostgreSQL instalado na máquina:

```powershell
Copy-Item .env.testing.example .env.testing
```

No `.env.testing`, ajuste `DB_HOST`, `DB_PORT`, `DB_USERNAME` e `DB_PASSWORD` conforme sua instalação local. O arquivo `.env.testing` é ignorado pelo Git.

Execute a suíte normalmente:

```powershell
php artisan test
```

As migrations executadas pelo trait `RefreshDatabase` serão aplicadas somente ao banco local `ti_vagas_test`. Não configure nesse arquivo a URL ou as credenciais do Neon.

## Compilar os recursos frontend

```bash
npm run build
```

## Executar localmente

```bash
php artisan serve
```

Por padrão, a aplicação fica disponível em `http://127.0.0.1:8000`.

## Atalhos

O `composer.json` define scripts de setup, desenvolvimento e testes. Confira o arquivo antes de utilizar um script, pois ele pode executar migrations ou outras operações no ambiente configurado.
