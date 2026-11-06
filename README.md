
# TI Vagas

**Menos tempo procurando. Mais tempo se preparando para a próxima oportunidade.**

O TI Vagas é um portal independente de oportunidades profissionais na área de tecnologia, com foco em profissionais em início de carreira.

O projeto reúne anúncios de diferentes empresas e plataformas de recrutamento em um único lugar, aplicando filtros de área, senioridade, experiência, localização e modalidade de trabalho.

Seu objetivo é facilitar o acesso a oportunidades de estágio e nível júnior em desenvolvimento de software, infraestrutura e suporte técnico.

## Objetivo do projeto

Encontrar a primeira oportunidade em tecnologia ou conquistar uma recolocação profissional pode ser um processo cansativo.

Muitas plataformas misturam vagas de diferentes níveis de senioridade, áreas e localidades. Além disso, nem sempre os anúncios deixam claro o nível de experiência esperado.

O TI Vagas busca tornar essa procura mais objetiva, priorizando oportunidades compatíveis com profissionais que estão começando sua trajetória na área de tecnologia.

## Público-alvo

- Desenvolvedores em início de carreira;
- Profissionais que procuram estágio em tecnologia;
- Pessoas interessadas em suporte técnico e suporte de TI;
- Profissionais que buscam oportunidades em infraestrutura e redes.

## Critérios de seleção das vagas

As oportunidades passam por filtros automatizados para manter o foco do portal.

### Áreas contempladas

- Desenvolvimento de software;
- Infraestrutura de TI e redes;
- Suporte técnico e suporte de TI.

### Senioridade

O foco é em vagas Júnior e estágio.

Os filtros procuram identificar oportunidades compatíveis com esse público e excluir anúncios que indiquem explicitamente níveis como:

- Pleno;
- Sênior;
- Staff;
- Principal;
- Lead;
- Outras posições de maior senioridade.

Também são considerados requisitos de experiência, responsabilidades e exigências técnicas que possam indicar uma posição incompatível com o público do portal.

Vagas sem senioridade claramente informada podem ser consideradas, desde que não apresentem requisitos ou responsabilidades que indiquem um nível mais elevado.

### Localização e modalidade de trabalho

O portal prioriza oportunidades destinadas a profissionais residentes no Brasil.

- **Remoto:** oportunidades destinadas a candidatos no Brasil, independentemente do estado.
- **Híbrido ou presencial:** oportunidades localizadas no Rio de Janeiro e municípios da região metropolitana contemplados pelos filtros.
- **Modalidade não informada:** quando aplicável às localidades permitidas, a vaga pode ser exibida com essa informação sinalizada.

A ausência de informação sobre modalidade não é considerada prova de que uma vaga seja remota.

## Coleta e integração de vagas

O TI Vagas utiliza coletores automatizados para consultar fontes públicas de recrutamento, páginas de carreiras, APIs e plataformas de empresas.

As integrações são implementadas de acordo com as características técnicas de cada fonte.

Entre as fontes integradas ou contempladas pelo projeto estão:

- Greenhouse;
- Lever;
- Workable;
- Workday;
- Oracle HCM;
- Inhire;
- Derevo;
- Sensedia;
- Ozy;
- RadarTEC.

A relação de fontes pode mudar conforme novas integrações forem implementadas ou fontes existentes deixarem de funcionar.

### Processo de importação

O processo de importação realiza validações antes de disponibilizar uma oportunidade no portal.

Entre as verificações estão:

- Identificação da fonte e do anúncio;
- Validação dos campos obrigatórios;
- Verificação de identificadores externos;
- Validação da data original de publicação;
- Aplicação dos critérios de localização e modalidade;
- Identificação de oportunidades potencialmente indisponíveis;
- Atualização ou inserção dos registros no banco de dados.

A identificação da oportunidade utiliza a fonte e seu identificador externo para evitar a criação de registros duplicados.

A data original de publicação é preservada durante as atualizações de registros existentes. A data da coleta não substitui a data original do anúncio.

## Verificação de disponibilidade

O projeto possui um mecanismo centralizado para identificar anúncios que não representam mais oportunidades disponíveis.

O detector considera informações de status, quando fornecidas pela fonte, além de expressões encontradas no título e na descrição.

Entre as situações identificadas estão:

- Vagas encerradas;
- Vagas finalizadas;
- Vagas preenchidas;
- Vagas pausadas;
- Vagas suspensas;
- Vagas congeladas;
- Vagas canceladas;
- Inscrições ou candidaturas encerradas;
- Anúncios que informam que não estão mais recebendo candidaturas.

O mecanismo contempla expressões em português e inglês.

### Tratamento de oportunidades indisponíveis

Quando uma oportunidade é identificada como indisponível durante a importação:

- O anúncio não é inserido novamente;
- Caso já exista no banco de dados, seu registro é excluído;
- A ocorrência é contabilizada como item ignorado durante a importação.

O portal também verifica a disponibilidade dos anúncios antes de exibi-los na listagem ou permitir o acesso à página individual.

O objetivo é reduzir a exibição de oportunidades que já foram encerradas pelas empresas.

**Limitação:** a ausência de uma vaga na resposta de uma fonte externa não significa necessariamente que ela foi encerrada. Quando uma fonte deixa de retornar um anúncio sem informar seu status, não é possível confirmar automaticamente sua indisponibilidade apenas pela ausência do registro.

Como as fontes externas podem apresentar informações desatualizadas ou incompletas, não existe garantia de que todas as oportunidades sejam identificadas corretamente.

## Expiração dos anúncios

Além da verificação de disponibilidade, o projeto possui uma regra independente de expiração.

Vagas com mais de 60 dias, considerando a data original de publicação, são removidas do banco de dados por meio do processo de limpeza.

Essa regra evita o acúmulo de anúncios antigos.

A expiração por idade não substitui a verificação de disponibilidade: são mecanismos distintos e complementares.

## Funcionalidades

- Listagem de oportunidades recentes;
- Busca por palavras-chave;
- Página individual com detalhes da vaga;
- Identificação da empresa, localização e modalidade de trabalho;
- Exibição da data original de publicação, quando disponível;
- Redirecionamento para a página original de candidatura;
- Integração com diferentes fontes de recrutamento;
- Importação e atualização automatizada de oportunidades;
- Identificação de anúncios indisponíveis;
- Remoção de registros identificados como encerrados;
- Exclusão de vagas com mais de 60 dias;
- Cadastro administrativo de vagas próprias.

## Tecnologias utilizadas

| Tecnologia | Finalidade |
|---|---|
| PHP 8.3 | Linguagem principal |
| Laravel 13 | Framework da aplicação |
| PostgreSQL | Banco de dados relacional |
| Blade | Renderização das páginas |
| Laravel Scheduler | Agendamento de tarefas |
| Laravel Queue | Estrutura de processamento de filas |
| PHPUnit | Testes automatizados |
| Git | Controle de versão |

## Testes automatizados

O projeto possui testes automatizados para validar regras de negócio e comportamentos importantes da aplicação.

Entre os cenários cobertos estão:

- Identificação de anúncios encerrados em português e inglês;
- Reconhecimento de diferentes estados de indisponibilidade;
- Identificação de expressões de encerramento em títulos e descrições;
- Prevenção de falsos positivos em anúncios que mencionam encerramentos futuros;
- Prevenção de falsos positivos em referências históricas e processos seletivos de outras áreas;
- Comportamento da listagem diante de oportunidades indisponíveis;
- Bloqueio de acesso a anúncios indisponíveis;
- Importação de oportunidades;
- Tratamento de registros duplicados e indisponíveis;
- Aplicação dos critérios de localização.

A última execução completa registrada apresentou:

**57 testes aprovados e 156 asserções.**

## Aviso importante e responsabilidade

O TI Vagas é um projeto independente de agregação e divulgação de oportunidades profissionais.

Não somos uma agência de recrutamento, consultoria de RH, empregador ou representantes das empresas anunciantes.

**A publicação de uma vaga no portal não representa garantia de que ela esteja ativa, seja legítima, esteja disponível ou resulte em contratação.**

Embora sejam aplicados filtros e verificações automatizadas, não é possível garantir a precisão de todas as informações recebidas das fontes externas.

O usuário deve considerar que:

- Uma vaga pode já ter sido encerrada pela empresa, mesmo que continue aparecendo no portal.
- Podem existir anúncios desatualizados, duplicados ou incorretos.
- Podem existir vagas fantasmas, publicadas ou mantidas pelas empresas sem intenção imediata de contratação.
- Requisitos, salários, benefícios, localização e modalidades podem mudar sem aviso.
- A empresa pode alterar, suspender ou encerrar um processo seletivo a qualquer momento.
- A classificação de senioridade e área pode apresentar falhas.

**A responsabilidade pela verificação da oportunidade e pela decisão de candidatura é do próprio usuário.**

Antes de fornecer dados pessoais, documentos ou qualquer informação sensível, recomendamos conferir a legitimidade da empresa e utilizar seus canais oficiais de recrutamento.

As candidaturas são realizadas diretamente nos canais indicados pela empresa ou plataforma responsável pelo anúncio.

## Atualização e disponibilidade

A coleta depende da disponibilidade das fontes externas e de suas respectivas APIs, páginas de carreiras e estruturas de dados.

Alterações em serviços de terceiros, políticas de acesso ou formatos de resposta podem interromper temporariamente a integração de determinadas empresas.

A automação de produção e seu agendamento serão configurados durante a publicação da aplicação.

## Projeto

O TI Vagas é desenvolvido e mantido como um projeto independente, com foco em facilitar o acesso a oportunidades profissionais na área de tecnologia.

Sugestões, identificação de anúncios incorretos e contribuições para melhorar os critérios de seleção são bem-vindas.

---

**TI Vagas — Menos tempo procurando. Mais tempo se preparando para a próxima oportunidade.**
