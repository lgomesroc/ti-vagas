# TI Vagas

Portal independente que reúne oportunidades efetivas de nível júnior na área de tecnologia. O projeto consulta plataformas de recrutamento, páginas de carreiras e publicações públicas, normaliza os anúncios e aplica critérios editoriais, geográficos e de disponibilidade.

O visitante pesquisa oportunidades, consulta os detalhes disponíveis e segue diretamente para o canal original de candidatura. O TI Vagas não recebe currículos, não intermedeia contratações e não garante que um anúncio permaneça ativo.

**Menos tempo procurando. Mais tempo se preparando para a próxima oportunidade.**

## Documentação

Este README é a porta de entrada. As regras e os procedimentos detalhados estão organizados por assunto em `docs/`.

| Documento | Conteúdo |
|---|---|
| [Aplicativo Progressivo (PWA)](docs/AplicativoPWA.md) | Instalação como aplicativo, service worker, cache e modo offline. |
| [Arquitetura e Fluxo de Dados](docs/ArquiteturaEFluxoDeDados.md) | Camadas, responsabilidades e percurso dos dados. |
| [Atualizações Recentes](docs/AtualizacoesRecentes.md) | Registro das alterações relevantes incorporadas ao projeto. |
| [Automação e Testes](docs/AutomacaoETestes.md) | Scheduler, filas, comandos operacionais e testes. |
| [Coleta e Integração de Vagas](docs/ColetaEIntegracaoDeVagas.md) | Contrato das fontes, normalização, importação e coletores globais. |
| [Coleta de Oportunidades no LinkedIn](docs/ColetaPublicacoesLinkedIn.md) | Publicações de recrutamento, LinkedIn Jobs, conta secundária e limites. |
| [Consentimento e Atribuição de Campanhas](docs/ConsentimentoEAtribuicao.md) | Termos, cookies, Analytics, AdSense e parâmetros UTM. |
| [Detecção de VPN, proxy e redes suspeitas](docs/deteccao-de-redes-suspeitas.md) | Sinais de reputação, classificação, políticas e limitações. |
| [Estrutura do Projeto](docs/EstruturaDoProjeto.md) | Árvore versionada da branch `main`. |
| [Expiração dos Anúncios](docs/ExpiracaoDeAnuncios.md) | Retenção, limpeza e diferença entre idade e disponibilidade. |
| [Histórico de Fontes Gupy por Empresa (legado)](docs/FontesGupyRecentes.md) | Contexto dos antigos cadastros individuais; não descreve a coleta global atual. |
| [Fontes IBM e Microsoft](docs/FontesIBMEMicrosoft.md) | Detalhes dos conectores IBM e Microsoft. |
| [Funcionalidades do Portal](docs/FuncionalidadesDoPortal.md) | Recursos disponíveis ao visitante e limites do serviço. |
| [Páginas de Erro Padronizadas](docs/PaginasDeErroPadronizadas.md) | Experiência de erro, códigos HTTP, fallbacks, segurança e testes. |
| [Instalação e Configuração](docs/InstalacaoEConfiguracao.md) | Ambiente local, dependências, banco e dados geográficos. |
| [Integração Abler](docs/IntegracaoAbler.md) | Coletor Abler, navegador, limites e workflow isolado. |
| [Localização e Regionalização](docs/LocalizacaoERegionalizacao.md) | Município, regiões do IBGE e regras de vagas locais/remotas. |
| [Regras de Elegibilidade](docs/RegrasDeElegibilidade.md) | Áreas, senioridade, experiência e critérios editoriais. |
| [Responsabilidade, Limitações e Uso das Informações](docs/ResponsabilidadeELimitacoes.md) | Dependência de fontes externas e cuidados do visitante. |
| [Segurança de Rede e Localização](docs/SegurancaDeRedeELocalizacao.md) | Validação da localização, sinais de falsificação e proteções. |
| [Tecnologias Utilizadas](docs/TecnologiasUtilizadas.md) | Stack, ferramentas e arquivos de referência. |
| [Verificação de Disponibilidade das Vagas](docs/VerificacaoDeDisponibilidade.md) | Estados active/suspect/expired e histórico de verificações. |
| [Visão Geral da Aplicação](docs/VisaoGeralDaAplicacao.md) | Propósito, público, escopo e jornada do visitante. |
| [Workflows do GitHub Actions](docs/WorkflowsGitHubActions.md) | Inventário atualizado dos workflows existentes em `main`. |

## Escopo

O portal concentra oportunidades efetivas de nível júnior em tecnologia, incluindo:

- Desenvolvimento backend, frontend e full stack;
- Infraestrutura, DevOps e computação em nuvem;
- Redes e administração de sistemas;
- Suporte técnico e suporte de TI;
- QA e testes de software, quando compatíveis com os critérios editoriais.

Não são o público-alvo estágios, trainee, programas de formação corporativa ou especialidades fora do escopo, como consultoria funcional de ERP/SAP, CRM, BI e ciência de dados. A classificação considera o cargo, a especialidade, os requisitos e as responsabilidades descritas no anúncio.

## Como as oportunidades são exibidas

- Oportunidades remotas compatíveis com atuação no Brasil podem ser exibidas nacionalmente.
- Vagas presenciais e híbridas dependem da região do visitante, identificada pelo navegador e validada no servidor.
- Sem localização válida, o portal limita os resultados às oportunidades remotas nacionais.
- A candidatura acontece diretamente no endereço original da empresa ou plataforma de recrutamento.

Os filtros automatizados reduzem anúncios incompatíveis, mas não garantem a exatidão de todos os dados nem a disponibilidade em tempo real. Consulte [Responsabilidade, Limitações e Uso das Informações](docs/ResponsabilidadeELimitacoes.md).

## Páginas de erro padronizadas

O portal utiliza páginas próprias para comunicar falhas de forma clara, sem expor detalhes internos da aplicação. O tratamento considera apenas os códigos pertinentes ao funcionamento do TI Vagas e mantém respostas JSON para requisições de API. Consulte [Páginas de Erro Padronizadas](docs/PaginasDeErroPadronizadas.md) para conhecer os códigos cobertos, os fallbacks, as limitações da hospedagem e os testes.

## Tecnologias principais

| Tecnologia | Uso |
|---|---|
| PHP 8.3 e Laravel 13 | Backend, rotas e regras de negócio |
| PostgreSQL | Persistência |
| Blade, Tailwind CSS e Vite | Interface e build frontend |
| Node.js 22 e Playwright | Scripts auxiliares e automação de navegador |
| Laravel Scheduler e Queue | Agendamento e processamento assíncrono |
| PHPUnit e Node.js Test Runner | Testes automatizados |
| GitHub Actions | CI e rotinas operacionais |
| Render | Hospedagem da aplicação |

Consulte [Tecnologias Utilizadas](docs/TecnologiasUtilizadas.md) e [Instalação e Configuração](docs/InstalacaoEConfiguracao.md).

## Executar localmente

Requisitos: PHP 8.3+, Composer, Node.js 22+, PostgreSQL e Git.

```bash
git clone https://github.com/lgomesroc/ti-vagas.git
cd ti-vagas
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

Configure a conexão PostgreSQL no `.env`, crie o banco e execute:

```bash
php artisan migrate
php artisan geo:import-ibge
php artisan vacancies:resolve-municipalities
npm run build
php artisan serve
```

A importação geográfica deve ser executada quando o banco ainda não tiver a base municipal. O projeto inclui os dados de apoio versionados; veja os detalhes em [Instalação e Configuração](docs/InstalacaoEConfiguracao.md).

A aplicação ficará disponível em `http://127.0.0.1:8000`.

Os testes utilizam o banco PostgreSQL isolado `ti_vagas_test`, nunca o banco definido para a aplicação. Configure `.env.testing` a partir de `.env.testing.example` e execute:

```bash
php artisan test
```

## Aviso

O TI Vagas é um projeto independente. Não é empregador, agência de recrutamento ou representante das empresas anunciantes. Uma oportunidade pode estar encerrada, desatualizada, incorreta ou não resultar em contratação. Antes de compartilhar dados pessoais, confira a legitimidade da empresa e utilize seus canais oficiais.

---

**TI Vagas — Menos tempo procurando. Mais tempo se preparando para a próxima oportunidade.**
