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
