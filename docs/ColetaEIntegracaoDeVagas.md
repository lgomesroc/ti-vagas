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

## Coletores globais

Algumas plataformas são consultadas por um único coletor global, em vez de criar e executar um adaptador independente para cada empresa.

### Gupy

A coleta atual da Gupy utiliza a API pública global `https://portal.gupy.io/api/job-search/jobs`, implementada em `GupyGlobalSource`. O comando dedicado é:

```bash
php artisan vacancies:collect-gupy
```

O coletor pesquisa termos configurados em `config/vacancy_sources.php` no bloco `vacancy_sources.gupy.global.terms`, percorre páginas da API, normaliza os anúncios e importa todos pela mesma fonte lógica (`gupy-global`). A configuração atual usa página de até 50 resultados, até cinco páginas por termo, janela inicial de 60 dias, sobreposição de 60 minutos entre execuções e intervalo de 250 ms entre páginas.

A data da última coleta bem-sucedida é mantida no cache. Essa janela só avança depois que a importação termina sem exceção. A rotina possui lock para impedir duas coletas globais simultâneas e tenta novamente respostas HTTP 429; se o limite persistir, a execução falha e não avança a janela.

**Não há mais coleta individual agendada por empresa Gupy.** O comando geral `vacancies:collect` não instancia fontes por empresa Gupy. O workflow `.github/workflows/gupy.yml` executa o comando global separadamente, quatro vezes ao dia. O bloco legado `vacancy_sources.gupy.companies` e a classe antiga `GupySource` permanecem no repositório, mas não são usados pelo fluxo atual da fábrica. A configuração operacional da coleta está no bloco `global`.

A busca global não garante cobertura integral de todas as vagas publicadas na plataforma: os resultados dependem dos termos, paginação, janela consultada e resposta disponibilizada pela API.

### InHire

A InHire também utiliza um coletor global (`InhireGlobalSource`), registrado uma única vez pela fábrica e executado junto às demais fontes de `vacancies:collect`. A descoberta de tenants combina uma lista inicial conhecida com identificadores recuperados de vagas InHire já armazenadas, fontes antigas e candidatos de publicações do LinkedIn — incluindo links presentes em comentários armazenados.

Os tenants são consultados individualmente por meio do adaptador InHire, mas a orquestração e a identidade de importação são globais (`inhire-global`). IDs já conhecidos são agrupados por tenant para evitar consultas desnecessárias aos detalhes de vagas previamente importadas. Uma falha isolada em um tenant é registrada e não interrompe a consulta dos demais.

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


### Abler

A Abler é consultada por um único coletor global (`AblerSource`), usando a listagem pública JSON do portal de candidatos em `/api/candidate/v2/vacancies`. A coleta percorre a paginação informada em `meta.next` e `meta.last`, respeita o limite configurado e considera a janela inicial de 60 dias. Os anúncios são normalizados para o contrato comum, com empresa, descrição, localização, modalidade, URL de candidatura, identificador externo e data original.

A configuração operacional fica em `vacancy_sources.abler`. Os limites padrão são 30 páginas, 100 registros por página e até 1.500 anúncios examinados/importáveis por execução. A classificação de TI e a elegibilidade geográfica são aplicadas antes da importação. Como a API pode alterar seus campos e regras de paginação, a integração deve ser acompanhada pelos testes e diagnósticos da fonte.
