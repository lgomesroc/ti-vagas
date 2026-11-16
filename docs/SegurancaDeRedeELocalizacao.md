# Segurança de Rede e Localização

## Objetivo

O projeto mantém mecanismos distintos para avaliar a integridade da localização do visitante e a reputação da rede de origem. Eles têm finalidades diferentes e não devem ser tratados como prova absoluta de identidade ou localização física.

## Proteção da localização

O fluxo de localização:

- Recebe coordenadas fornecidas pelo navegador com autorização;
- Resolve o município no servidor;
- Valida plausibilidade e coerência temporal das amostras;
- Assina os dados de localização mantidos na sessão com a chave da aplicação;
- Remove e registra tentativas de sobrescrever a localização por parâmetros não autorizados;
- Limita atualizações e mudanças excessivas de região.

Os principais componentes ficam em `app/Services/Geo/Security`, `app/Services/Geo/VisitorLocationResolver.php` e `app/Http/Middleware/RejectLocationOverrides.php`.

## Reputação de rede

A política de rede avalia sinais relacionados a VPNs, proxies, Tor, datacenters e endereços associados a abuso. A classificação e a ação dependem da categoria, confiança dos sinais e configuração ativa.

Os componentes ficam em `app/Services/Network`, com detectores em `app/Services/Network/Detectors`. O middleware `EvaluateNetworkReputation` aplica a avaliação às requisições.

## Configuração

Consulte `config/location_guard.php`, `config/network_security.php` e as variáveis correspondentes em `.env.example`.

Para redes, o modo de operação é configurado por `NETWORK_SECURITY_MODE` (off, monitor ou enforce). A allowlist e as ações por categoria podem ser ajustadas sem alterar o código dos detectores.

## Atualização de listas

O comando `php artisan network:update-lists` atualiza as listas de rede configuradas. O comando `php artisan network:check <ip>` permite inspecionar a classificação de um endereço.

## Documento complementar existente

A explicação detalhada das fontes, políticas, configurações, limitações e possíveis falsos positivos já existe em [deteccao-de-redes-suspeitas.md](deteccao-de-redes-suspeitas.md). Esse arquivo foi preservado sem renomeação ou exclusão.


## Aceite dos termos e preferências de cookies

O aceite da versão vigente dos termos é registrado na sessão e em cookie persistente por cinco anos. A escolha sobre cookies não essenciais é registrada separadamente, na sessão e em cookie com validade de um ano. O cookie de aceite e o cookie de preferência usam as configurações de domínio, caminho, SameSite, HttpOnly e Secure definidas para a sessão da aplicação.

As respostas que dependem de sessão ou cookies recebem cabeçalhos que impedem cache compartilhado (`no-store`, `private` e `Vary: Cookie`). O carregamento de Google Analytics e Google AdSense está condicionado à aceitação de cookies não essenciais; a rejeição impede que essas tags sejam incluídas na página.

A persistência entre domínios ou subdomínios depende da configuração correta de `SESSION_DOMAIN`, além de HTTPS e da configuração de proxy confiável no ambiente publicado. O comportamento deve ser validado no domínio real observando o atributo `Set-Cookie` e o reenvio do cookie pelo navegador; os testes automatizados de sessão não substituem essa validação operacional.
