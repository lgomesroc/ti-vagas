# Fontes Gupy Recentes

Este documento registra as fontes Gupy adicionadas recentemente à branch `developer` e suas particularidades de configuração.

A configuração operacional está em `config/vacancy_sources.php`. Os filtros gerais do TI Vagas continuam sendo aplicados após a coleta; cadastrar uma fonte não significa que todas as oportunidades publicadas nela serão exibidas.

## Fontes adicionadas

| Empresa | Página de carreiras | Configuração específica |
|---|---|---|
| Drogaria Globo | [drogariaglobo.gupy.io](https://drogariaglobo.gupy.io/) | `technology_only = true` |
| Skill Consultoria | [skillconsultoria.gupy.io](https://skillconsultoria.gupy.io/) | Sem filtro antecipado `technology_only`; os critérios gerais são aplicados |
| Smart NX | [smartnx.gupy.io](https://smartnx.gupy.io/) | Sem filtro antecipado `technology_only` |
| C&A | [cea.gupy.io](https://cea.gupy.io/) | `technology_only = true` |
| Viceri | [viceri-vagas.gupy.io](https://viceri-vagas.gupy.io/) | Sem filtro antecipado `technology_only` |

## Particularidades

### Drogaria Globo

A Drogaria Globo possui cadastro próprio e independente. Não deve ser confundida com a fonte da empresa Globo, que também existe na configuração.

### Skill Consultoria

A Skill Consultoria foi cadastrada sem `technology_only = true`, para que a coleta não descarte oportunidades na origem por um filtro específico de tecnologia. As regras gerais de área, senioridade, experiência, contratação e demais critérios do portal continuam responsáveis pela elegibilidade.

### Smart NX e Viceri

As duas empresas foram adicionadas como fontes Gupy próprias. Não foi configurado filtro antecipado `technology_only`; a seleção depende dos critérios gerais aplicados pelo projeto.

### C&A

A fonte foi cadastrada com `technology_only = true`, restringindo a coleta na origem às oportunidades identificadas como relacionadas à tecnologia, além dos filtros gerais posteriores.

## Critérios que continuam valendo

A inclusão dessas fontes não altera o escopo editorial do TI Vagas. Uma oportunidade só deve ser exibida se passar pelas regras gerais do projeto, incluindo:

- área profissional contemplada;
- senioridade compatível com o foco júnior;
- requisitos de experiência;
- tipo de contratação aceito;
- localização e modalidade de trabalho;
- disponibilidade e validade do anúncio;
- existência de informações suficientes e link de candidatura utilizável.

O cadastro de uma página de carreiras não é uma garantia de que haverá vagas elegíveis disponíveis nela.

## Manutenção

Ao adicionar ou remover uma empresa:

1. Atualize `config/vacancy_sources.php`;
2. Preserve um identificador de fonte estável e exclusivo;
3. Confirme o nome de exibição e a URL oficial da página de carreiras;
4. Defina `technology_only` somente quando houver justificativa para filtrar oportunidades na origem;
5. Acrescente ou atualize os testes de configuração e comportamento do adaptador Gupy;
6. Atualize esta documentação quando a alteração modificar as fontes aqui relacionadas.
