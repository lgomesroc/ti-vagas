# Automação e Testes

## Agendamento Laravel

As rotinas agendadas são declaradas em `routes/console.php`:

- `vacancies:collect`: executa a coleta a cada seis horas, no fuso `America/Sao_Paulo`.
- `network:update-lists`: atualiza as listas de reputação de rede a cada seis horas.
- `vacancies:check-links --limit=500`: verifica até 500 links de candidatura por execução, a cada seis horas.
- `vacancies:prune-expired`: executa às segundas-feiras às 03h, em semanas ISO pares.

As tarefas utilizam `withoutOverlapping()` para evitar execuções simultâneas da mesma rotina.

## GitHub Actions

Os workflows ficam em `.github/workflows`:

- `ci.yml`: valida dependências, sintaxe PHP, testes automatizados e compilação frontend.
- `vacancies.yml`: executa o agendador e permite disparo manual da coleta.

Consulte os arquivos de workflow para detalhes de gatilhos, secrets e serviços utilizados.

## Comandos úteis

```bash
php artisan test
php artisan vacancies:collect
php artisan vacancies:check-links --limit=500
php artisan vacancies:prune-expired
php artisan network:update-lists
php artisan network:check <ip>
```

## Organização dos testes

- `tests/Feature`: testes de integração e comportamento HTTP, fontes, importação, regionalização, localização, reputação de rede, atribuição UTM e verificação de links de candidatura.
- `tests/Unit`: testes unitários de regras e serviços.
- `tests/Concerns`: recursos compartilhados pelos testes.

## Verificação dos links de candidatura

O comando `vacancies:check-links` recebe a opção `--limit` (padrão operacional: 500) e prioriza vagas ainda não verificadas ou verificadas há mais tempo. A rotina agendada roda a cada seis horas e evita sobreposição. Os resultados são registrados em `vacancy_availability_checks`; falhas internas são contabilizadas e fazem o comando terminar com código de falha.

## Critério de manutenção

Ao alterar uma regra de negócio, atualize ou acrescente testes que cubram o comportamento esperado e os casos-limite. A documentação descreve o comportamento observado no código, mas os testes são a referência executável para validar regressões.
