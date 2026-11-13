# Detecção de VPN, proxy e redes suspeitas

Este documento descreve como o TI Vagas identifica acessos vindos de VPNs,
proxies anônimos, rede Tor, datacenters e endereços associados a abuso, o que
é feito com cada um e, principalmente, **onde a detecção erra**.

## Princípios

1. **Um IP público não é um IP suspeito.** Só deixa de ser "rede comum" o IP que
   aparece em alguma lista. Na dúvida, o acesso é liberado.
2. **IPs compartilhados nunca são punidos.** Wi-Fi de cafeterias, restaurantes,
   hotéis e aeroportos, assim como dados móveis (CGNAT), compartilham um IP entre
   muitas pessoas. Redes comuns não são contadas, limitadas nem bloqueadas por
   comportamento.
3. **O IP não substitui a geolocalização do navegador.** A classificação não lê
   nem grava localização, e nunca libera vagas presenciais ou híbridas. Em VPN,
   proxy, Tor e datacenter o IP é o do ponto de saída, então a estimativa de
   região por IP (aviso apenas informativo) é omitida.
4. **Só fontes oficiais ou conservadoras bloqueiam sozinhas.** Cada sinal tem uma
   confiança que limita o que ele pode causar (veja abaixo).
5. **Falha aberta.** Lista ausente, cache fora do ar ou detector com erro nunca
   derrubam o site nem bloqueiam alguém: o acesso é tratado como comum.
6. **Gratuito.** Todas as fontes padrão são gratuitas e o AbuseIPDB é opcional.

## Política de acesso

| Origem | Tratamento |
|---|---|
| Internet residencial | Permitir |
| Dados móveis 4G/5G | Permitir |
| Wi-Fi de cafeterias, restaurantes, hotéis e aeroportos | Permitir |
| VPN identificada | **Avaliar**; restringe se o IP se comportar como robô |
| Proxy anônimo | **Restringir** (configurável para bloquear) |
| Tor | **Restringir** (configurável para bloquear) |
| Datacenter | **Avaliar** comportamento e risco |
| Endereço associado a abuso (lista conservadora) | **Bloquear** |

Pelo IP não há como distinguir residência, dados móveis e Wi-Fi público entre
si, e isso não é necessário: todos são "rede comum" e recebem o mesmo
tratamento (liberado).

### O que cada ação faz

| Ação | Efeito |
|---|---|
| `allow` | Nada. Nenhum dado é consultado, contado ou registrado. |
| `evaluate` | Libera, registra um log por IP a cada 10 minutos e observa o comportamento. Se passar de 60 requisições por minuto, ou acumular 5 respostas 429, vira `restrict` por 15 minutos. |
| `restrict` | Libera, com limite de 30 requisições por minuto. Acima disso, responde 429 com `Retry-After`. |
| `block` | Responde 403 (página simples ou JSON), antes de a sessão ser criada. |

### Confiança limita a ação

| Confiança | Ação máxima | Fontes |
|---|---|---|
| alta | `block` | Lista oficial de saídas Tor, Spamhaus DROP, faixas publicadas pela AWS e pelo Google Cloud |
| média | `restrict` | Listas comunitárias de VPN e datacenter, proxies abertos, AbuseIPDB |
| baixa | `evaluate` | (reservada para heurísticas futuras) |

Por isso, mesmo que o operador configure `NETWORK_ACTION_PROXY=block`, uma lista
de proxies (confiança média) só restringe. Só a lista de abuso conservadora e a
lista oficial do Tor podem bloquear automaticamente.

## Como funciona

```
requisição ──► EvaluateNetworkReputation (antes da sessão)
                 │
                 ├─ /up, IP privado, IP da allowlist ──► libera
                 ├─ NetworkClassifier
                 │     ├─ ListDetector     (listas locais, ~9 µs)
                 │     └─ AbuseIpDbDetector (só lê o cache)
                 ├─ NetworkPolicy: sinais + confiança ──► ação
                 └─ aplica: allow | evaluate | restrict | block
```

O agendador (`network:update-lists`, a cada seis horas, no GitHub Actions)
baixa as listas e as grava no **cache compartilhado**, porque o agendador e o
servidor web são máquinas diferentes. Cada servidor guarda uma cópia local em
arquivo PHP (mantida em memória pelo OPcache) e a renova a cada 15 minutos.
Uma requisição de IP comum custa microssegundos e nenhuma consulta ao banco.

## Mecanismos, limitações e falsos positivos

### 1. Rede Tor (lista oficial do Tor Project)

- **Fonte:** `check.torproject.org/torbulkexitlist`.
- **Confiança:** alta. **Padrão:** `restrict`.
- **Limitações:**
  - A lista é atualizada a cada seis horas, mas os nós de saída mudam o tempo
    todo: um nó novo passa até seis horas sem ser detectado.
  - Detecta só **nós de saída**. Pontes (bridges) e quem usa Tor por trás de uma
    VPN não aparecem.
- **Falsos positivos:** raros (o IP de um nó de saída é de fato Tor), mas um IP
  pode ser reaproveitado por outra pessoa depois que o nó sai da lista. Quem
  usa Tor por privacidade, legitimamente, é restringido; por isso o padrão é
  limitar, não bloquear.

### 2. VPNs comerciais (lista comunitária X4BNet/lists_vpn)

- **Fonte:** `raw.githubusercontent.com/X4BNet/lists_vpn`.
- **Confiança:** média. **Padrão:** `evaluate`.
- **Limitações:**
  - A lista é baseada em faixas de provedores conhecidos. VPNs que usam IPs
    residenciais, VPNs próprias e provedores novos não aparecem.
  - Muitos serviços de privacidade usam o espaço de grandes nuvens e CDNs, e
    não é possível separá-los de tráfego comum nelas.
- **Falsos positivos:**
  - **VPN corporativa:** a saída de empresas costuma sair de IP de datacenter.
  - **iCloud Private Relay e Cloudflare WARP:** são usados por pessoas comuns,
    sem saber que estão em uma "VPN", e saem de faixas de CDN.
  - Faixas de VPN que mudam de dono.
  - Por isso a VPN só é **avaliada**: nada é bloqueado nem limitado enquanto o
    comportamento for normal.
- **Medido:** a lista cobre 0,16% dos 8 blocos /8 da LACNIC mais usados por
  operadoras brasileiras (177, 179, 186, 187, 189, 191, 200 e 201).

### 3. Proxies anônimos (listas FireHOL de proxies abertos, 7 dias)

- **Fontes:** `socks_proxy_7d` e `sslproxies_7d` do repositório
  `firehol/blocklist-ipsets`.
- **Confiança:** média. **Padrão:** `restrict`.
- **Limitações:**
  - Só enxerga proxies **abertos e públicos**. Proxies privados e pagos, em
    especial de IPs residenciais ("residential proxies"), não aparecem.
  - Os IPs são colhidos de sites de listas de proxies e mudam rápido.
- **Falsos positivos:** o principal. Proxies abertos costumam ser roteadores e
  câmeras comprometidos **em redes residenciais**; o IP de uma pessoa comum pode
  ter estado numa lista nos últimos sete dias. Por isso a lista tem janela curta
  e só **restringe** (30 requisições por minuto, o que não afeta quem navega
  normalmente).
- **Medido:** 0,00% dos blocos /8 brasileiros citados acima.

### 4. Datacenters e hospedagem

- **Fontes:** lista comunitária X4BNet (baseada em ASNs de hospedagem) e faixas
  publicadas pela própria AWS (serviço EC2) e pelo Google Cloud.
- **Confiança:** média (X4BNet) e alta (AWS/Google). **Padrão:** `evaluate`.
- **Limitações:**
  - A lista comunitária é baseada em ASN e tende a **exagerar**: provedores
    que misturam hospedagem e acesso residencial (comum no Brasil) entram
    inteiros. As faixas da AWS/Google são exatas, mas incluem só essas nuvens;
    Azure, Oracle, OVH, Hetzner e outros dependem da lista comunitária.
- **Falsos positivos:**
  - **Robôs legítimos** (Googlebot, AdSense, Bingbot, monitores de
    disponibilidade) saem de datacenters. A escalada de `evaluate` para
    `restrict` é ignorada para os User-Agents da lista
    `crawler_user_agents`. O User-Agent pode ser forjado: isso só afasta o limite
    brando de `evaluate`, nunca vale para Tor, proxy ou abuso.
  - Usuários em VPN de empresa, ou em Wi-Fi de hotel e aeroporto cujo tráfego
    sai por infraestrutura em nuvem.
  - A verificação de saúde da hospedagem (`/up`) é isenta.
- **Medido:** a lista comunitária marca **3,04%** dos 8 blocos /8 brasileiros
  citados (de 0,66% no 201/8 a 8,60% no 191/8). Esse é o motivo de datacenter
  ser apenas **avaliado**: o erro é grande demais para bloquear ou limitar de
  cara.

### 5. Endereços associados a abuso

- **Fonte:** Spamhaus DROP (redes sequestradas ou controladas por criminosos),
  pelo espelho do FireHOL.
- **Confiança:** alta. **Padrão:** `block`.
- **Limitações:**
  - A DROP cobre blocos inteiros sob controle de criminosos, não IPs
    individuais que enviam spam ou fazem varredura. Um IP abusivo isolado em uma
    rede legítima não aparece.
  - Atualiza por espelho: pode haver atraso entre a Spamhaus remover um bloco e
    ele sair da nossa cópia.
- **Falsos positivos:** muito baixos por construção (a DROP é conservadora), e
  é por isso a única lista que bloqueia sozinha.
- **Medido:** 0,08% dos 8 blocos /8 brasileiros citados.
- **Comportamento local:** respostas 429 repetidas de um IP em `evaluate`
  (VPN/datacenter) o escalam para `restrict`. Isso **nunca** se aplica a redes
  comuns.

### 6. AbuseIPDB (opcional)

- **Ativação:** defina `ABUSEIPDB_API_KEY` (plano gratuito: 1.000 consultas por
  dia; o limite local padrão é 900).
- **Confiança:** média. Sinaliza datacenter (`usageType`), Tor e pontuação de
  abuso a partir de 75.
- **Limitações:**
  - A primeira requisição de um IP novo **não** usa este sinal: a consulta é
    feita depois que a resposta é enviada, e o resultado fica 24 horas em cache.
  - Ao esgotar a cota (ou ao receber 429), as consultas pausam.
- **Falsos positivos:** dados colaborativos. Um IP compartilhado (CGNAT, Wi-Fi
  público) acumula denúncias de pessoas diferentes. Por isso a confiança é média:
  restringe, nunca bloqueia.
- **Verifique os termos de uso** do plano gratuito para o seu caso (uso
  comercial, anúncios).

## Limitações gerais

- **Nenhuma lista é completa.** Quem quer esconder a origem consegue
  (VPN própria, proxy residencial, IP novo). O objetivo é reduzir ruído e abuso
  óbvio, não impedir um atacante determinado.
- **X-Forwarded-For.** Com `TRUSTED_PROXIES=*` (padrão do projeto), qualquer
  visitante pode forjar o cabeçalho e se passar por um IP comum, escapando da
  detecção, ou por outro IP, causando restrição a terceiros. Em produção,
  restrinja `TRUSTED_PROXIES` aos IPs da hospedagem.
- **IPv6.** Cobertura menor que a de IPv4; usuários com IPv6 residencial são
  tratados como rede comum.
- **Licenças.** Confira as licenças de cada lista antes de usá-la em produção:
  a X4BNet não declara licença no repositório, e as listas do FireHOL
  agregam dados com termos próprios de cada fonte. Cada fonte pode ser
  desativada (`NETWORK_SOURCE_*`).
- **Privacidade (LGPD).** O IP é dado pessoal. Nenhum IP é gravado em texto
  claro: chaves de cache e logs usam um HMAC com a chave da aplicação, e os logs
  (`network.access`) trazem só categoria, ação e fontes. Revise o texto de
  privacidade do site para citar o uso do IP para segurança.

## Configuração

Veja `config/network_security.php` e `.env.example`.

| Variável | Padrão | Função |
|---|---|---|
| `NETWORK_SECURITY_MODE` | `enforce` | `off`, `monitor` (só registra) ou `enforce` |
| `NETWORK_ALLOWLIST` | vazio | IPs/CIDRs que nunca são classificados |
| `NETWORK_ACTION_VPN` | `evaluate` | `allow`, `evaluate`, `restrict` ou `block` |
| `NETWORK_ACTION_PROXY` | `restrict` | idem |
| `NETWORK_ACTION_TOR` | `restrict` | idem |
| `NETWORK_ACTION_DATACENTER` | `evaluate` | idem |
| `NETWORK_ACTION_ABUSIVE` | `block` | idem |
| `NETWORK_EVALUATE_RPM` | `60` | requisições/min que escalam `evaluate` |
| `NETWORK_RESTRICTED_RPM` | `30` | limite/min de um IP em `restrict` |
| `NETWORK_STRIKES_TO_ESCALATE` | `5` | respostas 429 que escalam `evaluate` |
| `NETWORK_SOURCE_*` | `true` | liga/desliga cada fonte (`TOR`, `VPN`, `DATACENTER`, `AWS`, `GCP`, `PROXY`, `ABUSE`) |
| `ABUSEIPDB_API_KEY` | vazio | ativa o AbuseIPDB |

## Publicação recomendada

1. Publique com `NETWORK_SECURITY_MODE=monitor`.
2. Rode a atualização das listas pela primeira vez (workflow *Coleta de vagas*
   com *Run workflow*, ou `php artisan network:update-lists`).
3. Observe os logs `network.access` por alguns dias: quantos acessos reais
   seriam restringidos?
4. Troque para `enforce`.

## Operação

```bash
php artisan network:update-lists                # baixa todas as fontes ativas
php artisan network:update-lists --source=tor   # só uma fonte
php artisan network:check                       # estado das listas
php artisan network:check 198.51.100.10         # como um IP seria classificado
```

Se uma fonte falhar (HTTP de erro, resposta vazia, lista pequena demais ou que
cubra mais de 10% do espaço IPv4), a lista anterior é mantida e o comando
termina com erro. Faixas mais largas que `/8` (IPv4) ou `/16` (IPv6), como
`0.0.0.0/0`, são ignoradas para que uma lista corrompida nunca bloqueie todo
mundo.

Para liberar alguém bloqueado por engano, acrescente o IP em
`NETWORK_ALLOWLIST`. Para um falso positivo recorrente, abra uma issue com a
saída de `php artisan network:check <ip>`.
