# TI Vagas

Portal independente que reúne oportunidades efetivas de nível júnior na área de tecnologia. O projeto consulta fontes externas, normaliza os anúncios e aplica critérios de área, senioridade, experiência, localização e disponibilidade.

O visitante consulta os detalhes e segue para o canal original de candidatura. O TI Vagas não recebe currículos, não intermedeia contratações e não garante que um anúncio permaneça ativo.

**Menos tempo procurando. Mais tempo se preparando para a próxima oportunidade.**

## Documentação

A documentação foi dividida por assunto. Este README funciona como página inicial: apresenta o projeto e resume cada tema; os detalhes técnicos, procedimentos e regras ficam nos documentos específicos em `docs/`.

### Índice

| Documento | Conteúdo |
|---|---|
| [Arquitetura e Fluxo de Dados](docs/ArquiteturaEFluxoDeDados.md) | Camadas da aplicação, responsabilidades e percurso dos dados entre fontes, serviços e banco. |
| [Atualizações Recentes](docs/AtualizacoesRecentes.md) | Registro cronológico das principais mudanças implementadas no projeto. |
| [Automação e Testes](docs/AutomacaoETestes.md) | Scheduler, filas, comandos operacionais e execução dos testes automatizados. |
| [Coleta e Integração de Vagas](docs/ColetaEIntegracaoDeVagas.md) | Contrato comum das fontes, normalização, importação, deduplicação e coletores globais. |
| [Coleta de Oportunidades no LinkedIn](docs/ColetaPublicacoesLinkedIn.md) | Fluxos de publicações, LinkedIn Jobs, análise de comentários, limites e tratamento de bloqueios. |
| [Consentimento e Atribuição de Campanhas](docs/ConsentimentoEAtribuicao.md) | Aceite dos termos, cookies não essenciais, Analytics, AdSense e parâmetros UTM. |
| [Detecção de VPN, proxy e redes suspeitas](docs/deteccao-de-redes-suspeitas.md) | Fontes de reputação, classificação de rede, políticas, configuração e limitações. |
| [Estrutura do Projeto](docs/EstruturaDoProjeto.md) | Árvore de diretórios e arquivos versionados, com pastas antes dos arquivos e ordenação alfabética. |
| [Expiração dos Anúncios](docs/ExpiracaoDeAnuncios.md) | Política de retenção de 60 dias, comando de limpeza e diferença entre idade e disponibilidade. |
| [Histórico de Fontes Gupy por Empresa (legado)](docs/FontesGupyRecentes.md) | Contexto histórico dos cadastros individuais antigos; não descreve o fluxo global atual. |
| [Fontes IBM e Microsoft](docs/FontesIBMEMicrosoft.md) | Comportamento específico dos conectores de carreiras IBM e Microsoft. |
| [Funcionalidades do Portal](docs/FuncionalidadesDoPortal.md) | Recursos disponíveis ao visitante e limites do que o portal oferece. |
| [Instalação e Configuração](docs/InstalacaoEConfiguracao.md) | Requisitos, clonagem, dependências, ambiente, banco, geografia e execução local. |
| [Integração Abler](docs/IntegracaoAbler.md) | Coletor público com Playwright, termos de busca, limites, extração e workflow isolado. |
| [Localização e Regionalização](docs/LocalizacaoERegionalizacao.md) | Identificação de município, regiões do IBGE e regras de exibição local/remota. |
| [Regras de Elegibilidade](docs/RegrasDeElegibilidade.md) | Áreas contempladas, senioridade, experiência, estágio e critérios editoriais. |
| [Responsabilidade, Limitações e Uso das Informações](docs/ResponsabilidadeELimitacoes.md) | Dependência de fontes externas, cuidados do visitante e limites das verificações. |
| [Segurança de Rede e Localização](docs/SegurancaDeRedeELocalizacao.md) | Integridade da localização, sinais de falsificação, middleware e proteções no servidor. |
| [Tecnologias Utilizadas](docs/TecnologiasUtilizadas.md) | Stack de backend, frontend, automação, testes e arquivos de dependências. |
| [Verificação de Disponibilidade das Vagas](docs/VerificacaoDeDisponibilidade.md) | Estados active/suspect/expired, verificação HTTP, histórico e critérios de classificação. |
| [Visão Geral da Aplicação](docs/VisaoGeralDaAplicacao.md) | Propósito, público-alvo, escopo, jornada do visitante e funcionalidades em alto nível. |
| [Workflows do GitHub Actions](docs/WorkflowsGitHubActions.md) | Inventário dos workflows, branches de checkout, secrets, agendas, jobs e concorrência. |

## Escopo do portal

O foco são oportunidades efetivas de nível júnior nas áreas de:

- Desenvolvimento backend, frontend e full stack;
- Infraestrutura, DevOps e computação em nuvem;
- Redes e administração de sistemas;
- Suporte técnico e suporte de TI;
- QA e testes de software, quando compatíveis com o escopo editorial.

O portal não tem como público-alvo vagas de estágio, trainee, programas de formação corporativa ou especialidades fora do escopo, como consultoria funcional de ERP/SAP, CRM, BI e ciência de dados. A classificação considera o cargo, a especialidade, os requisitos e as responsabilidades do anúncio.

## Como as vagas são exibidas

- Oportunidades remotas compatíveis com atuação no Brasil podem ser exibidas nacionalmente.
- Vagas presenciais e híbridas são exibidas conforme a região do visitante, identificada e validada no servidor.
- Sem localização válida, o portal limita os resultados às oportunidades remotas nacionais.
- A candidatura ocorre diretamente no site da empresa ou plataforma que publicou o anúncio.

Os filtros automatizados reduzem anúncios incompatíveis, mas não garantem a exatidão de todos os dados nem a disponibilidade em tempo real. Leia [Responsabilidade, Limitações e Uso das Informações](docs/ResponsabilidadeELimitacoes.md).

## Tecnologias principais

| Tecnologia | Uso |
|---|---|
| PHP 8.3 e Laravel 13 | Backend e regras de negócio |
| PostgreSQL | Persistência de dados |
| Blade, Tailwind CSS e Vite | Interface e build frontend |
| Node.js e Playwright | Scripts auxiliares e automação de navegador |
| Laravel Scheduler e Queue | Agendamento e processamento assíncrono |
| PHPUnit | Testes automatizados |
| GitHub Actions | CI e rotinas automatizadas |

Consulte [Tecnologias Utilizadas](docs/TecnologiasUtilizadas.md) para os arquivos de referência e [Instalação e Configuração](docs/InstalacaoEConfiguracao.md) para preparar o ambiente.

## Executar localmente

Requisitos: PHP 8.3+, Composer, Node.js 22+, PostgreSQL e Git.

```bash
git clone https://github.com/lgomesroc/ti-vagas.git
cd ti-vagas
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configure a conexão PostgreSQL no `.env`, crie o banco e execute:

```bash
php artisan migrate
npm run build
php artisan serve
```

A aplicação ficará disponível em `http://127.0.0.1:8000`.

Para executar a suíte, configure o banco exclusivo de testes conforme [Instalação e Configuração](docs/InstalacaoEConfiguracao.md) e rode:

```bash
php artisan test
```

## Aviso

O TI Vagas é um projeto independente. Não é empregador, agência de recrutamento ou representante das empresas anunciantes. Uma vaga publicada pode estar encerrada, desatualizada, incorreta ou não resultar em contratação. Antes de compartilhar dados pessoais, confira a legitimidade da empresa e utilize seus canais oficiais.

---

**TI Vagas — Menos tempo procurando. Mais tempo se preparando para a próxima oportunidade.**
