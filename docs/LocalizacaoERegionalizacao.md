# Localização e Regionalização

## Objetivo

A regionalização permite que o visitante encontre vagas presenciais e híbridas compatíveis com sua região, enquanto oportunidades remotas elegíveis permanecem disponíveis em todo o Brasil.

## Origem da localização do visitante

O navegador pode fornecer coordenadas mediante autorização do visitante. O servidor resolve essas coordenadas para um município e determina a região aplicável. O navegador não escolhe diretamente o município, a UF ou a região usada na filtragem.

A região validada é mantida na sessão. A aplicação não deve confiar em município, UF ou região enviados livremente por parâmetros de URL, corpo da requisição ou cookies.

Toda sessão de quem já aceitou os termos tem um modo de localização. O aceite fica num cookie de cinco anos, mas a sessão expira antes (`SESSION_LIFETIME`); por isso o middleware `EnsureWelcomeAccepted` inicia em `limited` qualquer sessão sem modo definido. Sem isso, quem voltava com sessão nova não recebia o script de localização e ficava preso às vagas remotas mesmo com a permissão já concedida no navegador.

No modo `limited`, somente vagas remotas nacionais são exibidas. O script de atualização de localização é carregado nesse modo para permitir a solicitação de permissão; o endpoint aceita a primeira leitura tanto em `limited` quanto em `available`. Somente coordenadas com precisão dentro do limite, aprovadas pelas validações de segurança e resolvidas para um município brasileiro plausível podem promover a sessão para `available`.

**O sistema não adivinha a localização.** Não há estimativa por IP, nem para exibir ao visitante nem para liberar vagas locais. A única origem da região é uma posição informada pelo navegador e validada pelo servidor. (O IP continua sendo usado apenas como sinal de segurança do `LocationGuard`.)

## O que a permissão do navegador decide

O script consulta a permissão atual (`navigator.permissions`) a cada carregamento de página e quando ela muda, e age conforme o estado. Quem decide é o que o visitante escolheu no navegador, não um temporizador da aplicação.

| Permissão no navegador | O que acontece |
| --- | --- |
| `granted` (concedida) | Obtém a posição sem perguntar de novo e envia ao servidor. Aparecem vagas presenciais e híbridas da região, além das remotas. Vale já na segunda visita, mesmo com sessão nova. |
| `prompt` (nunca decidida, ou redefinida/revogada nas configurações) | O navegador pergunta (uma vez por página). Se havia uma região guardada na sessão, ela é removida antes: sem permissão não há vagas locais. |
| `denied` (negada/bloqueada) | Somente vagas remotas. Se havia região na sessão, ela é removida (`POST /localizacao/desativar`) e a página recarrega. O site não consegue perguntar de novo; a mensagem orienta a liberar nas configurações. |

Se o visitante mudar a permissão com a página aberta, o evento `change` da permissão faz o script reagir na hora, sem recarregar à mão.

O aviso de permissão não conta no tempo de espera por uma leitura: o prazo de refinamento (`REFINE_WINDOW_MS`) só começa depois que o aparelho devolve a primeira posição aceitável.

## Quem se move

Com a permissão concedida e a aba visível, o script usa `navigator.geolocation.watchPosition` e acompanha o deslocamento do aparelho (por exemplo, um celular em um trajeto). Uma nova posição só é enviada ao servidor quando:

- o aparelho se afastou pelo menos 1 km da última posição enviada; e
- passaram pelo menos 20 segundos desde o último envio (o endpoint aceita 6 requisições por minuto).

Se o servidor reconhecer uma região diferente, a lista de vagas é atualizada com um aviso na tela. As travas de segurança continuam valendo: deslocamento mínimo de 5 km em relação à posição que definiu a região (`MIN_MOVE_KM`), limite de trocas de região por hora, análise de velocidade e precisão máxima de 5.000 m. Com a aba em segundo plano o acompanhamento é suspenso e retomado quando ela volta ao primeiro plano.

## Escopo regional

A comparação utiliza códigos de município do IBGE e considera as seguintes camadas:

1. Região Metropolitana (RM) ou Região Integrada de Desenvolvimento (RIDE), quando aplicável;
2. Região Geográfica Intermediária do IBGE para municípios que não pertencem a uma RM ou RIDE.

A regra evita misturar municípios de regiões distintas apenas por proximidade geográfica.

## Atualização da localização

A atualização acompanha o dispositivo com `navigator.geolocation.watchPosition` enquanto a permissão estiver concedida e a aba estiver visível (veja "Quem se move"). A primeira leitura espera até 8 segundos por uma precisão melhor, ou é enviada na hora se já estiver dentro de 100 m. A resposta não revela ao cliente os detalhes internos de uma recusa de segurança.

Rotas:

- `POST /localizacao/atualizar` (`location.refresh`): recebe latitude, longitude e precisão; o servidor valida, resolve o município e, se aprovado, grava a região na sessão.
- `POST /localizacao/desativar` (`location.disable`): chamada quando o navegador informa que a permissão foi bloqueada ou revogada. Remove a região da sessão e volta ao modo `limited`. Só reduz o acesso e não apaga o histórico de suspeitas do `LocationGuard`.

## Dados territoriais

- `ibge/extraido/RELATORIO_DTB_BRASIL_2025_MUNICIPIOS.ods`: planilha oficial da DTB 2025, lida diretamente pelo importador.
- `database/data/municipios.csv`: origem histórica dos centroides; as coordenadas existentes são preservadas por código IBGE durante a atualização da DTB.
- `config/geo_regions.php`: ajustes manuais de regiões metropolitanas e RIDEs.
- `app/Services/Geo`: importação e resolução de dados geográficos.
- `app/Services/VacancyLocationFilter.php`: aplicação dos critérios regionais na consulta de vagas.

## Comandos de manutenção

Após executar as migrations, importe a base territorial:

```bash
php artisan geo:import-ibge
php artisan vacancies:resolve-municipalities
```

A importação lê diretamente a planilha ODS da DTB 2025 versionada em `ibge/extraido/RELATORIO_DTB_BRASIL_2025_MUNICIPIOS.ods`, sem exigir CSV intermediário nem chamadas à API. O Dockerfile executa `geo:import-ibge --if-empty` no deploy.

## Privacidade e limitações

A aplicação deve utilizar a localização apenas para definir quais oportunidades podem ser exibidas. A validação reduz adulterações, mas não comprova presença física: coordenadas podem ser simuladas no dispositivo do visitante.

Limitação de hardware: o servidor só aceita posições com precisão de até 5.000 m. Computadores sem GPS e com o serviço de localização do sistema desligado (por exemplo, a localização do Windows) costumam devolver precisão pior que isso; nesse caso o visitante vê uma mensagem com a precisão obtida e continua com as vagas remotas, em vez de o sistema adivinhar uma região.
