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
