# Integração Abler

A integração Abler utiliza a interface pública de busca do portal de candidatos, renderizada no navegador. O coletor não depende do endpoint interno de API que bloqueia acessos diretos.

## Implementação

- Adaptador PHP: `app/Sources/AblerSource.php`.
- Coletor de navegador: `scripts/abler/collect.mjs`.
- Workflow isolado: `.github/workflows/abler.yml`.
- Testes do adaptador: `tests/Feature/AblerSourceTest.php`.

O adaptador executa o script Node.js/Playwright, recebe um JSON padronizado e aplica os filtros e a normalização usados pelo projeto. O navegador abre a página pública de busca, coleta links de anúncios e visita os detalhes para obter os campos disponíveis.

## Termos e limites de busca

O coletor utiliza termos específicos relacionados a desenvolvimento, linguagens e frameworks, suporte, infraestrutura e QA em nível júnior. Para cada termo, o script limita a busca a três páginas e até 30 links de vagas. Links repetidos entre termos são deduplicados antes da extração de detalhes.

A paginação depende dos controles que a própria interface pública disponibiliza. Se não houver controle de próxima página reconhecível, o coletor encerra a busca daquele termo sem inventar parâmetros de URL.

## Dados extraídos

Quando disponíveis na página ou nos metadados estruturados, são coletados:

- Título e empresa;
- Localização e modalidade de trabalho;
- Descrição;
- Data original de publicação;
- Tipo de contratação e salário;
- URL original do anúncio e identificador externo.

A ausência de um campo na página não deve ser preenchida por suposição. O adaptador exige os dados necessários à elegibilidade e à importação comum.

## Elegibilidade

Após a extração, o adaptador aplica a janela de recência e os critérios gerais do TI Vagas. O título precisa corresponder às áreas contempladas e não pode indicar senioridade superior a júnior ou estágio. A localização e a modalidade passam pelo serviço compartilhado `VacancyLocationFilter`.

O projeto continua focado em oportunidades efetivas de nível júnior. A presença de termos como estágio em uma busca técnica não transforma vagas de estágio em oportunidades elegíveis.

## Execução

A coleta isolada é disponibilizada por workflow manual:

1. O workflow faz checkout da branch `developer`.
2. Configura PHP e Node.js.
3. Instala dependências Composer e npm.
4. Instala o Chromium necessário ao Playwright.
5. Executa `php artisan vacancies:collect-source abler-global`.

O workflow usa a configuração de banco definida nos secrets e grava no banco de dados operacional. Portanto, não deve ser disparado como teste de desenvolvimento sem intenção de executar a coleta real.

## Diagnóstico e limitações

O script devolve contadores de termos, links encontrados, detalhes abertos e erros por termo ou anúncio. O adaptador registra os indicadores de execução no log da aplicação.

A estrutura do portal pode mudar, o carregamento client-side pode falhar e alguns anúncios podem não expor todos os campos esperados. Os limites por termo também significam que a busca não representa cobertura integral das vagas da Abler.

Antes de alterar seletores, filtros ou limites, valide a página pública atual, atualize os testes e confira a execução do workflow. Não substitua a coleta por acesso direto a endpoints internos bloqueados.
