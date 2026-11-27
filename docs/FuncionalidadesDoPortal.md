# Funcionalidades do Portal

O TI Vagas reúne oportunidades efetivas de nível júnior em tecnologia, aplica filtros editoriais e direciona o visitante ao canal original de candidatura.

## Recursos disponíveis ao visitante

- **Listagem de vagas:** consulta de oportunidades que passaram pelos critérios de importação e exibição.
- **Pesquisa:** busca por palavras-chave e paginação dos resultados.
- **Detalhes:** página individual com as informações disponíveis do anúncio.
- **Informações da oportunidade:** empresa, título, descrição, localidade, modalidade, tipo de contratação, salário e data original, conforme os dados fornecidos pela fonte.
- **Candidatura externa:** link para o endereço original da empresa ou plataforma de recrutamento.
- **Regionalização:** vagas presenciais e híbridas são filtradas pela região validada no servidor.
- **Vagas remotas:** oportunidades compatíveis com atuação no Brasil podem ser exibidas nacionalmente.
- **Consentimento:** escolha de aceitar ou rejeitar cookies não essenciais.
- **Aplicativo instalável (PWA):** instalação na tela inicial ou no computador, com página própria quando não há conexão.

## Localização

A localização é solicitada pelo navegador e validada pelo servidor. Enquanto não houver uma posição válida, a listagem permanece limitada às vagas remotas nacionais.

A permissão pode ser negada ou revogada. Nesse caso, o portal volta ao modo limitado. A localização não é inferida pelo IP e parâmetros enviados pelo usuário não substituem a validação do servidor.

## Atualização e disponibilidade

As vagas são coletadas de fontes externas e normalizadas. Anúncios antigos são tratados pela política de expiração. Links de candidatura também passam por verificações periódicas, com estados `active`, `suspect` e `expired` e histórico das tentativas.

Esses mecanismos são sinais automatizados. Uma resposta inconclusiva não significa necessariamente que a vaga foi encerrada, e uma resposta positiva não garante que a candidatura ainda esteja disponível no momento da visita.

## O que o portal não faz

- Não recebe currículos nem candidaturas;
- Não cria contas de candidatos nem mantém banco de perfis profissionais;
- Não representa empresas ou plataformas de recrutamento;
- Não envia candidatura em nome do visitante;
- Não garante que uma oportunidade esteja ativa, seja legítima ou resulte em contratação;
- Não transforma automaticamente uma vaga sem senioridade declarada em oportunidade comprovadamente júnior.

## Aplicativo instalável (PWA)

O TI Vagas pode ser instalado como aplicativo pelo botão "Instalar app" do cabeçalho (quando o navegador oferece) ou pelo menu do navegador. O aplicativo abre em janela própria e segue as mesmas regras do site: termos, cookies, localização validada e filtros editoriais.

As vagas não ficam salvas no aparelho. Sem conexão, é exibida uma página informativa que recarrega automaticamente quando a internet volta. Consulte [Aplicativo Progressivo (PWA)](AplicativoPWA.md).

## Fluxo de uso

1. O visitante consulta e aceita os termos.
2. Escolhe sua preferência de cookies não essenciais.
3. Autoriza ou não a localização no navegador.
4. Consulta as oportunidades compatíveis com os critérios editoriais e regionais.
5. Abre os detalhes e segue para a página original de candidatura.

Sem localização validada, o portal limita a exibição às vagas remotas nacionais.

## Documentação relacionada

- [Visão Geral da Aplicação](VisaoGeralDaAplicacao.md)
- [Regras de Elegibilidade](RegrasDeElegibilidade.md)
- [Localização e Regionalização](LocalizacaoERegionalizacao.md)
- [Consentimento e atribuição de campanhas](ConsentimentoEAtribuicao.md)
- [Verificação de Disponibilidade das Vagas](VerificacaoDeDisponibilidade.md)
- [Responsabilidade e Limitações](ResponsabilidadeELimitacoes.md)
- [Aplicativo Progressivo (PWA)](AplicativoPWA.md)
