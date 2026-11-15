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
php artisan geo:import-ibge
php artisan vacancies:resolve-municipalities
```

A importação geográfica depende de acesso à API do IBGE. Para o modo offline, consulte as opções do comando `geo:import-ibge`.

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
