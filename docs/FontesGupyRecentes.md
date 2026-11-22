# Histórico de Fontes Gupy por Empresa (Legado)

Este documento preserva o contexto dos cadastros Gupy feitos quando a integração consultava páginas de carreiras individualmente. **Esse não é mais o modelo de coleta em operação.**

## Modelo atual

A coleta atual utiliza um único coletor global, implementado em `app/Sources/GupyGlobalSource.php`, que consulta a API pública de busca da Gupy:

```bash
php artisan vacancies:collect-gupy
```

Os termos, paginação, janela de consulta e intervalo entre requisições são definidos em `config/vacancy_sources.php`, no bloco `vacancy_sources.gupy.global`. O workflow `.github/workflows/gupy.yml` executa essa coleta separadamente, quatro vezes ao dia.

Não há execução da classe antiga `GupySource` por empresa na fábrica nem coleta individual de cada página de carreiras. O bloco `vacancy_sources.gupy.companies` e a classe `GupySource` permanecem no repositório como legado, mas não determinam a coleta atual. Portanto, os registros abaixo não devem ser interpretados como uma lista de fontes individualmente consultadas.

## Histórico de cadastros documentados

Os cadastros adicionados em uma etapa anterior incluíram:

- Drogaria Globo — cadastro independente da empresa Globo;
- Skill Consultoria;
- Smart NX;
- C&A;
- Viceri.

Essas empresas fizeram parte da configuração individual histórica. A presença de um nome nesse histórico não significa que exista uma consulta exclusiva à respectiva página de carreiras no fluxo atual.

## Elegibilidade

A coleta global não significa que todos os anúncios retornados serão exibidos. Os dados seguem pelo importador comum e continuam sujeitos às regras gerais do portal, incluindo área profissional, senioridade, experiência, tipo de contratação, localização, disponibilidade, qualidade dos dados e link de candidatura.

## Manutenção

Alterações no comportamento atual da Gupy devem ser documentadas em [Coleta e Integração de Vagas](ColetaEIntegracaoDeVagas.md) e implementadas no coletor global e em sua configuração. Não atualize este documento como se fosse uma lista operacional de empresas individuais.
