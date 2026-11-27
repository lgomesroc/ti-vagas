# Visão Geral da Aplicação

## Propósito

O TI Vagas é um portal independente que reúne oportunidades efetivas de nível júnior na área de tecnologia. O objetivo é reduzir o tempo gasto na busca por vagas, concentrando anúncios de empresas e plataformas de recrutamento em uma única interface.

O portal não recebe candidaturas: apresenta as informações coletadas e direciona o visitante ao endereço original de candidatura.

## Público e escopo

O escopo contempla desenvolvimento de software, infraestrutura de TI, DevOps, computação em nuvem, redes, suporte técnico e QA compatível com os critérios editoriais.

Não são o foco estágios, trainee, programas de formação corporativa, consultoria funcional de ERP/SAP, CRM, BI, análise de dados, ciência de dados ou especialidades equivalentes. A avaliação considera o conteúdo integral do anúncio, não apenas o título.

## Jornada do visitante

1. O visitante acessa a página de boas-vindas, consulta os termos e registra o aceite.
2. O aceite da versão vigente dos termos é mantido na sessão e em cookie persistente, evitando solicitar nova confirmação a cada visita enquanto a versão não mudar.
3. O visitante escolhe aceitar ou rejeitar cookies não essenciais. Analytics e publicidade dependentes desse consentimento só são carregados após autorização.
4. Na página de vagas, o navegador pode solicitar autorização para obter a localização. Até que uma posição válida seja confirmada pelo servidor, a sessão permanece limitada às vagas remotas nacionais.
5. Com coordenadas válidas, o servidor identifica o município e determina a região aplicável às vagas presenciais e híbridas. A região não é estimada pelo endereço IP.
6. Se a permissão for negada ou revogada, a sessão retorna ao modo limitado e exibe apenas oportunidades remotas nacionais.
7. O visitante pesquisa, pagina os resultados, consulta os detalhes e segue para o endereço original de candidatura da empresa ou plataforma.

## Funcionalidades principais

- Listagem, pesquisa por palavras-chave e paginação;
- Página individual de oportunidade;
- Exibição de empresa, localidade, modalidade e data original quando fornecida pela fonte;
- Exibição de salário e tipo de contratação quando disponíveis;
- Direcionamento ao canal original de candidatura;
- Coleta automatizada e normalização de anúncios de múltiplas fontes;
- Filtros de área, senioridade, experiência, localização e disponibilidade;
- Expiração e limpeza de anúncios antigos;
- Verificação periódica dos links de candidatura e histórico de disponibilidade;
- Proteções para localização e reputação de rede;
- Registro da primeira origem de campanha (UTM) na sessão;
- Consentimento separado para cookies não essenciais.

## Operação e dependências

A aplicação web é executada em Laravel e PostgreSQL. A coleta de vagas é realizada por adaptadores de fontes, comandos, filas e workflows do GitHub Actions. Algumas integrações possuem workflows dedicados; outras são despachadas pela coleta geral.

O funcionamento depende da disponibilidade das fontes externas, das credenciais e secrets operacionais, das filas e do banco configurado no ambiente.

## Limitações

Os dados podem estar incompletos, desatualizados ou incorretos. A verificação automatizada reduz a exposição a links encerrados, mas não garante que uma candidatura esteja aberta ou que a vaga seja legítima. O portal não representa as empresas anunciantes e não garante contratação.
