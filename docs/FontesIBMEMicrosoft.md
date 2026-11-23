# Integrações IBM e Microsoft

Este documento registra o comportamento específico dos conectores IBM e Microsoft. A habilitação efetiva e os parâmetros de cada fonte devem ser conferidos em `config/vacancy_sources.php`.

## IBM

O conector consulta a API de busca de carreiras da IBM. A coleta restringe os resultados aos níveis Entry Level e Internship e aplica os filtros de tecnologia do TI Vagas. Anúncios sem data original de publicação são descartados para evitar importar registros cuja recência não possa ser avaliada.

A indicação de Internship recebida da fonte não significa que o portal passe a aceitar estágio como categoria editorial: os critérios gerais de elegibilidade continuam aplicáveis e o foco do projeto permanece em oportunidades efetivas de nível júnior.

## Microsoft

O conector consulta o portal Eightfold da Microsoft para oportunidades associadas ao Brasil. Para cada resultado, recupera os detalhes individuais para obter a descrição e o link oficial de candidatura. O filtro procura indicações de júnior, início de carreira ou estágio, mas a elegibilidade final continua subordinada às regras gerais do portal, que não tem estágio como público-alvo.

## Limitações

As APIs e páginas são serviços de terceiros e podem mudar estrutura, paginação, filtros ou disponibilidade. Os resultados dependem dos parâmetros de busca e das informações efetivamente fornecidas pelas empresas.

Consulte `app/Sources/IbmSource.php`, `app/Sources/MicrosoftSource.php`, os testes correspondentes e `config/vacancy_sources.php` antes de alterar os conectores.
