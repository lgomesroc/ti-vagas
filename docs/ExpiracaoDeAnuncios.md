# Expiração dos anúncios

A expiração por idade é uma política de retenção independente da verificação de disponibilidade. Seu objetivo é evitar o acúmulo de anúncios antigos no banco de dados.

## Regra atual

O projeto considera a data original de publicação da oportunidade. Vagas com mais de 60 dias são removidas pelo comando:

```bash
php artisan vacancies:prune-expired
```

A rotina está agendada para as segundas-feiras às 03h, no fuso `America/Sao_Paulo`, condicionada à semana ISO par, conforme a configuração atual do scheduler.

## Diferença entre expiração por idade e encerramento

São processos diferentes:

- **Expiração por idade:** remove registros que ultrapassaram a janela de retenção de 60 dias.
- **Disponibilidade:** classifica a vaga como `active`, `suspect` ou `expired` conforme informações da fonte e verificações do link.

Uma vaga pode estar indisponível antes de completar 60 dias. Da mesma forma, uma vaga ainda acessível pode ser removida ao ultrapassar a janela de retenção.

## Histórico relacionado

Os registros de verificação vinculados à vaga acompanham sua exclusão, conforme as chaves estrangeiras e a política de retenção do banco. A expiração não deve ser interpretada como prova de que a empresa encerrou o processo seletivo; é uma regra de conservação dos dados do portal.

Antes de alterar o prazo ou o agendamento, confira o comando `PruneExpiredVacancies`, o scheduler em `routes/console.php` e os testes correspondentes.
