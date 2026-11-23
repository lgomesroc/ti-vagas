# Verificação de disponibilidade das vagas

O TI Vagas utiliza dois mecanismos complementares: a análise de sinais de encerramento durante a coleta e a verificação posterior dos links de candidatura. Nenhum deles garante que a empresa ainda esteja recebendo candidaturas em tempo real.

## Estados de disponibilidade

| Estado | Significado |
|---|---|
| `active` | A última verificação foi bem-sucedida e não encontrou sinal de encerramento. |
| `suspect` | A resposta foi inconclusiva, temporária ou exige confirmação. |
| `expired` | Há sinal explícito de encerramento ou confirmação suficiente de indisponibilidade. |

A listagem pública e a página individual devem apresentar somente oportunidades com estado `active`.

## Verificação HTTP

O serviço `VacancyLinkChecker` é executado pelo comando:

```bash
php artisan vacancies:check-links --limit=500
```

A rotina é agendada a cada seis horas. O limite pode ser ajustado pela opção `--limit`. São priorizadas vagas ainda não verificadas e, depois, as verificadas há mais tempo.

### Classificação das respostas

| Resposta ou situação | Resultado |
|---|---|
| HTTP 2xx sem indicador de encerramento | `active` |
| HTTP 2xx com texto de encerramento | `expired` |
| HTTP 410 | `expired` |
| Primeiro HTTP 404 | `suspect` |
| HTTP 404 em duas verificações consecutivas | `expired` |
| HTTP 403, 429, 5xx, timeout ou falha de conexão | `suspect` |

Uma falha temporária não deve encerrar automaticamente uma vaga. O código 404 exige confirmação em uma segunda verificação; o 410 é tratado como encerramento explícito.

## Histórico

Cada tentativa é registrada em `vacancy_availability_checks`, com informações como URL consultada, destino após redirecionamentos, status HTTP, justificativa, duração e data da verificação. A vaga também mantém o último resultado e os dados necessários para a próxima verificação.

Os registros históricos acompanham a retenção da vaga: quando a vaga é removida pela política de expiração, os registros relacionados também são eliminados.

## Proteções de requisição

Como os links vêm de fontes externas, a rotina valida esquemas e destinos antes de consultar URLs e redirecionamentos. Endereços locais, IPs privados ou reservados e destinos não permitidos devem ser bloqueados para reduzir risco de SSRF.

## Limitações

- HTTP 200 confirma que uma página respondeu, não que a empresa ainda recebe candidaturas.
- Plataformas podem responder com páginas genéricas, bloqueios ou desafios automatizados.
- Uma vaga pode ser encerrada sem que a página ou o status HTTP seja atualizado.
- O estado `active` representa o último resultado conhecido, não uma garantia de disponibilidade atual.

Consulte `app/Services/VacancyLinkChecker.php`, `app/Services/VacancyAvailabilityDetector.php`, o comando `vacancies:check-links` e as migrations da tabela de histórico para detalhes da implementação.
