# Localização e Regionalização

## Objetivo

A regionalização permite que o visitante encontre vagas presenciais e híbridas compatíveis com sua região, enquanto oportunidades remotas elegíveis permanecem disponíveis em todo o Brasil.

## Origem da localização do visitante

O navegador pode fornecer coordenadas mediante autorização do visitante. O servidor resolve essas coordenadas para um município e determina a região aplicável. O navegador não escolhe diretamente o município, a UF ou a região usada na filtragem.

A região validada é mantida na sessão. A aplicação não deve confiar em município, UF ou região enviados livremente por parâmetros de URL, corpo da requisição ou cookies.

Após o aceite dos termos, a sessão começa em modo `limited`. Nesse estado, somente vagas remotas nacionais são exibidas. O script de atualização de localização também é carregado nesse modo para permitir a primeira solicitação de permissão; o endpoint aceita a primeira leitura tanto em `limited` quanto em `available`. Somente coordenadas com precisão dentro do limite, aprovadas pelas validações de segurança e resolvidas para um município brasileiro plausível podem promover a sessão para `available`.

Se a pessoa negar a permissão, o navegador não disponibilizar a geolocalização, a precisão for insuficiente ou as coordenadas não puderem ser resolvidas, a sessão continua em `limited`. O visitante pode alterar a permissão nas configurações do navegador e tentar novamente. O sistema não usa a estimativa de IP para liberar vagas locais.

## Escopo regional

A comparação utiliza códigos de município do IBGE e considera as seguintes camadas:

1. Região Metropolitana (RM) ou Região Integrada de Desenvolvimento (RIDE), quando aplicável;
2. Região Geográfica Intermediária do IBGE para municípios que não pertencem a uma RM ou RIDE.

A regra evita misturar municípios de regiões distintas apenas por proximidade geográfica.

## Atualização da localização

A atualização é feita por requisições pontuais com `navigator.geolocation.getCurrentPosition`, não por rastreamento contínuo com `watchPosition`. O navegador mantém a autorização conforme as regras do próprio dispositivo; a aplicação verifica novamente a localização em intervalos e quando necessário, respeitando a permissão concedida. A resposta não revela ao cliente os detalhes internos de uma recusa de segurança.

## Dados territoriais

- `database/data/municipios.csv`: municípios, unidades federativas e centroides.
- `config/geo_regions.php`: ajustes manuais de regiões metropolitanas e RIDEs.
- `app/Services/Geo`: importação e resolução de dados geográficos.
- `app/Services/VacancyLocationFilter.php`: aplicação dos critérios regionais na consulta de vagas.

## Comandos de manutenção

Após executar as migrations, importe a base territorial:

```bash
php artisan geo:import-ibge
php artisan vacancies:resolve-municipalities
```

O comando de importação consulta a API de localidades do IBGE. A opção `--offline` utiliza os dados locais e os ajustes manuais. Em implantação, o Dockerfile executa a importação com `--if-empty`.

## Privacidade e limitações

A aplicação deve utilizar a localização apenas para definir quais oportunidades podem ser exibidas. A validação reduz adulterações, mas não comprova presença física: coordenadas podem ser simuladas no dispositivo do visitante.
