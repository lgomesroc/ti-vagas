# Consentimento e atribuição de campanhas

## Aceite dos termos

O acesso ao portal depende do aceite dos termos apresentados na página de boas-vindas. O aceite é mantido na sessão e em cookie persistente; uma nova versão dos termos pode exigir novo aceite. O portal não recebe candidaturas nem atua como intermediário entre candidato e empresa.

## Cookies não essenciais

O aceite dos termos e a escolha sobre cookies não essenciais são decisões separadas. Rejeitar cookies não essenciais não impede a navegação. Nessa situação, os códigos do Google Analytics e do Google AdSense não são carregados. Quando o visitante aceita, essas ferramentas podem ser carregadas conforme a implementação e as demais configurações do ambiente.

Não confunda o aceite dos termos com o consentimento para análise e publicidade: um não substitui o outro.

## Atribuição UTM

O middleware `CaptureUtmParameters` captura a primeira origem de campanha identificada pelos parâmetros UTM suportados. Os valores são sanitizados e limitados antes de serem armazenados na sessão.

A primeira origem identificada é preservada durante a sessão; parâmetros posteriores não a substituem. O envio dos dados de atribuição ao Google Analytics depende do consentimento para cookies não essenciais.

## Cuidados de implementação

- Não carregar Analytics ou AdSense antes da escolha positiva de cookies não essenciais.
- Não condicionar o funcionamento básico do portal ao aceite de publicidade ou análise.
- Não substituir a primeira origem UTM por parâmetros encontrados em navegações posteriores.
- Não registrar valores UTM sem sanitização ou sem os limites definidos na implementação.

## Código relacionado

- Middleware: `app/Http/Middleware/CaptureUtmParameters.php`.
- Configuração de serviços: `config/services.php`.
- Rotas, views e scripts de consentimento: consultar os arquivos correspondentes na árvore atual do projeto.

A implementação pode evoluir; confira o código versionado antes de alterar nomes de parâmetros, duração dos cookies ou comportamento de consentimento.
