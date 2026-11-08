# TI Vagas

**Menos tempo procurando. Mais tempo se preparando para a próxima oportunidade.**

O **TI Vagas** é um portal independente de oportunidades profissionais na área de tecnologia, com foco em vagas efetivas de nível júnior.

O projeto reúne anúncios publicados em diferentes empresas e plataformas de recrutamento, aplicando critérios automatizados de área profissional, senioridade, experiência, localização e modalidade de trabalho.

O objetivo é facilitar a busca por oportunidades em desenvolvimento de software, infraestrutura, DevOps, redes e suporte técnico.

## Objetivo do projeto

Encontrar uma oportunidade de entrada ou conquistar uma recolocação profissional em tecnologia pode ser um processo demorado.

As plataformas de recrutamento reúnem vagas de diferentes níveis, especialidades e localidades. Nem sempre os anúncios deixam claro o nível de experiência esperado ou se a oportunidade é compatível com quem está no início da carreira.

O TI Vagas procura centralizar essas oportunidades e reduzir o tempo gasto na procura, priorizando vagas efetivas de nível júnior.

## Público-alvo

O portal é direcionado a profissionais que procuram oportunidades efetivas de nível júnior nas seguintes áreas:

- Desenvolvimento backend, frontend e full stack;
- Desenvolvimento web e mobile;
- Infraestrutura de TI;
- DevOps e computação em nuvem;
- Redes e administração de sistemas;
- Suporte técnico e suporte de TI.

O projeto não tem como foco vagas de estágio, trainee ou programas de formação corporativa.

## Critérios de seleção das vagas

As oportunidades passam por filtros automatizados para manter o foco editorial do portal.

### Áreas contempladas

- Desenvolvimento de software convencional;
- Infraestrutura de TI;
- DevOps e cloud;
- Redes e administração de sistemas;
- Suporte técnico e suporte de TI.

Entre as tecnologias de desenvolvimento consideradas estão PHP, Laravel, C#/.NET, Java, Python, JavaScript, TypeScript e seus respectivos frameworks e ecossistemas.

### Áreas não contempladas

O portal não tem como objetivo divulgar vagas especializadas em:

- CRM;
- ERP, SAP, ABAP e sistemas corporativos equivalentes;
- Consultoria funcional e implantação de sistemas empresariais;
- Business Intelligence (BI);
- Análise e engenharia de dados;
- Ciência de dados;
- Machine Learning e Deep Learning;
- Outras especialidades profissionais fora do escopo definido.

A classificação considera principalmente o cargo e a especialidade da oportunidade. Uma menção incidental a uma dessas tecnologias ou áreas na descrição não deve, isoladamente, eliminar uma vaga de desenvolvimento convencional.

### Senioridade e experiência

O foco do portal são oportunidades de nível júnior.

Os filtros identificam indicações de senioridade incompatíveis com esse público, incluindo:

- Pleno;
- Sênior;
- Staff;
- Principal;
- Lead;
- Manager;
- Director;
- Head;
- Architect;
- Especialista e outras posições de maior responsabilidade.

Também são avaliados requisitos de experiência e responsabilidades descritas no anúncio. Exigências de três ou mais anos de experiência são consideradas incompatíveis com os critérios atuais.

Vagas sem senioridade claramente informada podem ser submetidas aos demais critérios de elegibilidade, sem que a ausência dessa informação seja interpretada automaticamente como comprovação de nível júnior.

### Localização e modalidade de trabalho

O portal prioriza oportunidades destinadas a profissionais residentes no Brasil.

- **Remoto:** oportunidades que permitem atuação remota no Brasil, independentemente do estado.
- **Híbrido:** oportunidades em municípios da Região Metropolitana do Rio de Janeiro contemplados pelos filtros.
- **Presencial:** oportunidades em municípios da Região Metropolitana do Rio de Janeiro contemplados pelos filtros.
- **Modalidade não informada:** algumas oportunidades podem ser aceitas quando a localização atende aos critérios territoriais do projeto.

O sistema também verifica referências a localidades estrangeiras e cidades fora da região permitida, evitando apresentar oportunidades incompatíveis com a abrangência geográfica definida.

A ausência de informação sobre modalidade não significa que uma oportunidade seja remota.

## Coleta e integração de vagas

O TI Vagas utiliza integrações automatizadas para consultar anúncios disponíveis em APIs, páginas de carreiras e plataformas de recrutamento.

Cada integração é implementada considerando as características técnicas da respectiva fonte.

Entre as plataformas e fontes integradas estão:

- Greenhouse;
- Lever;
- Workable;
- Workday;
- Ashby;
- Oracle HCM;
- InHire;
- GeekHunter;
- Recrut.ai;
- Derevo;
- Sensedia;
- Ozy;
- RadarTEC.

A relação de fontes pode mudar conforme novas integrações sejam implementadas ou fontes existentes deixem de funcionar.

### Processo de importação

Antes de disponibilizar uma oportunidade, o sistema realiza validações e normalizações dos dados recebidos.

Entre as etapas estão:

1. Consulta às fontes configuradas;
2. Identificação da empresa e do anúncio;
3. Normalização dos dados recebidos;
4. Validação dos campos obrigatórios;
5. Verificação do identificador externo;
6. Preservação da data original de publicação;
7. Identificação de anúncios potencialmente indisponíveis;
8. Inserção ou atualização dos registros no banco de dados.

A identificação dos anúncios utiliza a fonte de origem e o identificador externo para evitar registros duplicados.

Quando uma oportunidade já existe, seus dados podem ser atualizados sem substituir a data original de publicação pela data da coleta.

## Verificação de disponibilidade

O projeto possui um mecanismo centralizado para identificar anúncios que não representam mais oportunidades disponíveis.

O detector considera informações de status fornecidas pelas fontes e expressões encontradas nos títulos e nas descrições.

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
- Caso já exista no banco de dados, seu registro é removido;
- A ocorrência é contabilizada como item ignorado durante a importação.

A aplicação também verifica a disponibilidade dos anúncios antes de exibi-los na listagem ou permitir o acesso à página individual.

O objetivo é reduzir a exibição de oportunidades que já foram encerradas pelas empresas.

**Limitação:** a ausência de uma vaga na resposta de uma fonte externa não significa necessariamente que ela foi encerrada. Quando uma fonte deixa de retornar um anúncio sem informar seu status, não é possível confirmar automaticamente sua indisponibilidade apenas pela ausência do registro.

Como as fontes externas podem apresentar informações desatualizadas ou incompletas, não existe garantia de que todas as oportunidades sejam identificadas corretamente.

## Expiração dos anúncios

Além da verificação de disponibilidade, o projeto possui uma regra independente de expiração.

Vagas com mais de 60 dias, considerando a data original de publicação, são removidas do banco de dados pelo processo de limpeza.

Essa regra evita o acúmulo de anúncios antigos.

A expiração por idade não substitui a verificação de disponibilidade: são mecanismos distintos e complementares.

## Funcionalidades

- Listagem de oportunidades recentes;
- Pesquisa por palavras-chave;
- Página individual com detalhes da oportunidade;
- Identificação da empresa, localização e modalidade de trabalho;
- Exibição da data original de publicação, quando disponível;
- Redirecionamento para a página original de candidatura;
- Integração com diferentes fontes de recrutamento;
- Importação e atualização automatizada de oportunidades;
- Identificação de anúncios indisponíveis;
- Remoção de registros identificados como encerrados;
- Exclusão de vagas com mais de 60 dias;
- Paginação dos resultados;
- Validação de elegibilidade antes da exibição das oportunidades.

## Tecnologias utilizadas

| Tecnologia | Finalidade |
|---|---|
| PHP 8.3 | Linguagem principal |
| Laravel 13 | Framework da aplicação |
| PostgreSQL | Banco de dados relacional |
| Blade | Renderização das páginas |
| Tailwind CSS | Estilização da interface |
| Vite | Compilação dos recursos frontend |
| Laravel Scheduler | Agendamento de tarefas |
| Laravel Queue | Processamento de filas |
| PHPUnit 12 | Testes automatizados |
| Git | Controle de versão |
| GitHub Actions | Integração contínua e automação das rotinas |

## Testes automatizados

O projeto possui testes automatizados para validar regras de negócio e comportamentos importantes da aplicação.

Entre os cenários cobertos estão:

- Identificação de anúncios encerrados em português e inglês;
- Reconhecimento de diferentes estados de indisponibilidade;
- Identificação de expressões de encerramento em títulos e descrições;
- Prevenção de falsos positivos em anúncios que mencionam encerramentos futuros;
- Prevenção de falsos positivos em referências históricas;
- Aplicação dos critérios de senioridade;
- Exclusão de vagas de estágio e trainee;
- Exclusão de especialidades fora do escopo, como SAP/ABAP, ERP, CRM, BI e ciência de dados;
- Preservação de vagas de desenvolvimento que apenas mencionam tecnologias ou áreas excluídas na descrição;
- Tratamento de vagas com requisitos de experiência incompatíveis;
- Aplicação dos critérios de localização e modalidade;
- Comportamento da listagem diante de oportunidades indisponíveis;
- Bloqueio de acesso a anúncios indisponíveis;
- Importação de oportunidades;
- Tratamento de registros duplicados e indisponíveis.

**Última execução completa registrada:**

- **73 testes aprovados**
- **213 asserções**
- **Nenhuma falha na execução**

Para executar a suíte localmente:

```bash
php artisan test
```

## Automação e agendamento

O projeto utiliza o Laravel Scheduler e o GitHub Actions para executar as rotinas de coleta e manutenção.

### Coleta de oportunidades

A coleta está configurada para ocorrer a cada seis horas, no fuso horário `America/Sao_Paulo`.

O comando responsável é:

```bash
php artisan vacancies:collect
```

A rotina consulta as fontes configuradas e processa as oportunidades recebidas.

### Limpeza de anúncios expirados

A limpeza está configurada para ocorrer às segundas-feiras, às 03h, no fuso horário `America/Sao_Paulo`, com execução condicionada à semana ISO par.

O comando responsável é:

```bash
php artisan vacancies:prune-expired
```

### GitHub Actions

O projeto possui workflows separados:

- **CI:** valida o `composer.json`, instala dependências, verifica a sintaxe dos arquivos PHP e compila o frontend. É executado em push e pull request nas branches `developer` e `main`.
- **Automação de vagas:** executa as rotinas agendadas, conecta ao banco de dados e processa a fila de oportunidades.

A automação de vagas utiliza processamento de filas para executar as tarefas de coleta.

## Executando o projeto localmente

### Requisitos

- PHP 8.3 ou superior;
- Composer;
- Node.js 22 ou superior;
- PostgreSQL;
- Git.

### Instalação

Clone o repositório:

```bash
git clone https://github.com/lgomesroc/ti-vagas.git
cd ti-vagas
```

Instale as dependências PHP:

```bash
composer install
```

Configure o ambiente:

```bash
cp .env.example .env
php artisan key:generate
```

Configure as variáveis de conexão com o PostgreSQL no arquivo `.env`.

Execute as migrações:

```bash
php artisan migrate
```

Instale as dependências frontend e compile os recursos:

```bash
npm install
npm run build
```

Inicie o servidor local:

```bash
php artisan serve
```

A aplicação ficará disponível, por padrão, em:

```text
http://127.0.0.1:8000
```

## Aviso importante e responsabilidade

O TI Vagas é um projeto independente de agregação e divulgação de oportunidades profissionais.

Não somos uma agência de recrutamento, consultoria de RH, empregador ou representantes das empresas anunciantes.

**A publicação de uma vaga no portal não representa garantia de que ela esteja ativa, seja legítima, esteja disponível ou resulte em contratação.**

Embora sejam aplicados filtros e verificações automatizadas, não é possível garantir a precisão de todas as informações recebidas das fontes externas.

O usuário deve considerar que:

- Uma vaga pode já ter sido encerrada pela empresa, mesmo que continue aparecendo no portal;
- Podem existir anúncios desatualizados, duplicados ou incorretos;
- Podem existir vagas fantasmas, publicadas ou mantidas pelas empresas sem intenção imediata de contratação;
- Requisitos, salários, benefícios, localização e modalidades podem mudar sem aviso;
- A empresa pode alterar, suspender ou encerrar um processo seletivo a qualquer momento;
- A classificação automática de senioridade, área e modalidade pode apresentar falhas.

**A responsabilidade pela verificação da oportunidade e pela decisão de candidatura é do próprio usuário.**

Antes de fornecer dados pessoais, documentos ou qualquer informação sensível, recomendamos conferir a legitimidade da empresa e utilizar seus canais oficiais de recrutamento.

As candidaturas são realizadas diretamente nos canais indicados pela empresa ou plataforma responsável pelo anúncio.

## Atualização e disponibilidade

A coleta depende da disponibilidade das fontes externas e de suas respectivas APIs, páginas de carreiras e estruturas de dados.

Alterações em serviços de terceiros, políticas de acesso ou formatos de resposta podem interromper temporariamente a integração de determinadas empresas.

A atualização das oportunidades também depende da execução dos workflows, da disponibilidade do banco de dados e do funcionamento dos serviços externos.

## Projeto

O TI Vagas é desenvolvido e mantido como um projeto independente, com foco em facilitar o acesso a oportunidades profissionais de nível júnior na área de tecnologia.

Sugestões, identificação de anúncios incorretos e contribuições para melhorar os critérios de seleção são bem-vindas.

---

**TI Vagas — Menos tempo procurando. Mais tempo se preparando para a próxima oportunidade.**
