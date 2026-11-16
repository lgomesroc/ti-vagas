# Coleta e Integração de Vagas

## Contrato das fontes

As integrações implementam o contrato `App\\Contracts\\VacancySource`. Cada adaptador é responsável por consultar uma plataforma ou página de carreiras e converter sua resposta para o formato esperado pelo importador.

Os adaptadores ficam em `app/Sources`; a configuração de fontes fica em `config/vacancy_sources.php`; e `VacancySourceFactory` instancia as integrações habilitadas.

## Dados normalizados

Os anúncios são tratados em um formato comum, com informações como:

- Título e empresa;
- Descrição;
- Localização e modalidade;
- Tipo de contratação e salário, quando informados;
- URL original de candidatura;
- Identificador externo;
- Data original de publicação.

A disponibilidade de cada campo depende da fonte de origem.

## Etapas de importação

1. Consultar as fontes habilitadas.
2. Identificar a empresa e o anúncio.
3. Normalizar os campos retornados.
4. Validar os dados necessários.
5. Verificar duplicidade por fonte e identificador externo.
6. Aplicar os critérios de elegibilidade.
7. Verificar sinais de indisponibilidade.
8. Inserir ou atualizar o registro válido.

A data original de publicação deve ser preservada durante atualizações; a data da coleta não deve substituí-la.

## Concorrência e filas

A coleta utiliza jobs para distribuir consultas por fonte. O processamento em fila reduz o acoplamento entre a rotina de coleta e o tempo de resposta de cada plataforma.

## Manutenção das integrações

Plataformas podem alterar APIs, páginas, autenticação, limites ou formatos de resposta. Quando uma integração falhar, verifique os logs, a configuração da fonte e os testes correspondentes antes de alterar o fluxo comum do importador.

## Fontes

A lista efetivamente habilitada deve ser consultada em `config/vacancy_sources.php`. A documentação não replica essa lista para evitar divergência quando novas fontes forem adicionadas ou removidas.


## Verificação periódica dos links de candidatura

A verificação do conteúdo recebido durante a coleta e a verificação posterior do endereço de candidatura são mecanismos complementares. A rotina HTTP é implementada por `VacancyLinkChecker` e executada pelo comando `php artisan vacancies:check-links --limit=500`, agendado a cada seis horas.

O comando seleciona vagas com estado `active` ou `suspect`, priorizando as que nunca foram verificadas e, em seguida, as verificadas há mais tempo. O limite pode ser ajustado pela opção `--limit`.

### Critérios de classificação

| Resposta ou situação | Resultado |
|---|---|
| HTTP 2xx e conteúdo sem indicador de encerramento | `active` |
| HTTP 2xx com texto que indica encerramento da vaga ou candidatura | `expired` |
| HTTP 410 (Gone) | `expired` |
| Primeiro HTTP 404 | `suspect` |
| HTTP 404 em duas verificações consecutivas | `expired` |
| Outros status HTTP, falha de conexão, timeout ou erro de validação | `suspect` |

Uma resposta temporária ou inconclusiva não é tratada automaticamente como encerramento. A rotina grava o status atual na vaga e registra cada tentativa em `vacancy_availability_checks`, incluindo URL consultada, URL final após redirecionamentos, status HTTP, justificativa, tempo de resposta e data da verificação.

### Proteções ao consultar URLs externas

Como os endereços vêm de fontes externas, o verificador aplica restrições antes de cada requisição e redirecionamento:

- Aceita somente esquemas HTTP e HTTPS.
- Bloqueia hosts locais, nomes sem domínio público, credenciais embutidas na URL e endereços IP privados ou reservados.
- Em ambiente diferente de testes, resolve o domínio e rejeita destinos que apontem para IPs privados ou reservados.
- Segue redirecionamentos manualmente, validando cada destino, com limite de cinco redirecionamentos.
- Usa timeout de conexão de cinco segundos e timeout total de quinze segundos.
- Limita a leitura do corpo da resposta a 250.000 caracteres para procurar indicadores de encerramento.

A verificação é um indício automatizado, não uma garantia de que a candidatura esteja realmente aberta. Páginas protegidas, bloqueios automatizados, respostas temporárias e mudanças no HTML podem produzir estados suspeitos ou classificações incorretas. A ausência de confirmação não deve ser confundida com prova de encerramento.
