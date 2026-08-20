# AGENTS.md — php-dfe

## Projeto

- Nome: `php-dfe`
- Raiz esperada no Windows: `C:\httpd-2.4.46-win64-VS16\htdocs\php-dfe`
- Entrada principal da aplicação: `consultar_nota_servidores.php`
- URL local esperada: `http://localhost/php-dfe/consultar_nota_servidores.php`
- Stack principal: PHP, HTML, CSS e JavaScript.
- Apache esperado: `C:\httpd-2.4.46-win64-VS16`
- PHP esperado: `C:\php-8.1.3-Win32-vs16-x64\php.exe`
- Shell preferencial: PowerShell 7 (`pwsh`).

## Idioma

Responda sempre em português do Brasil.
Comentários novos no código também devem preferir português quando isso combinar com o padrão já existente no projeto.

## Regra principal

Trabalhe somente dentro da raiz deste projeto.
Não use cópias antigas, backups, arquivos externos ou outro projeto como fonte de código sem pedido explícito.

Antes de alterar qualquer arquivo:

1. Leia o arquivo relevante e suas dependências próximas.
2. Entenda o fluxo existente.
3. Identifique exatamente o que o pedido exige.
4. Faça a menor alteração necessária.
5. Preserve comportamento, nomes, visual e estrutura que não façam parte do pedido.

## Entrada e navegação

Considere `consultar_nota_servidores.php` como ponto inicial para entender a aplicação.
Ao investigar um fluxo, siga includes, requires, links, formulários, chamadas Ajax/fetch, JavaScript e endpoints PHP a partir dele.

Não assuma que todo arquivo PHP é uma página independente.

## PHP

- Não altere versão, arquitetura ou bibliotecas do PHP sem pedido.
- Use o estilo de acesso a banco já adotado pelo projeto.
- Não converta `mysqli` para PDO, PDO para `mysqli`, ou introduza framework sem pedido.
- Preserve compatibilidade com a versão de PHP atualmente usada pelo projeto.
- Após alterar PHP, execute `php -l` nos arquivos PHP modificados.
- Sintaxe válida não significa que o fluxo foi testado em runtime.
- Nunca diga que banco, Apache, browser ou integração funcionaram se isso não foi realmente verificado.

## HTML, CSS e JavaScript

- Preserve o layout e o comportamento visual existentes quando o pedido não for visual.
- Evite reformatação ampla de HTML/CSS/JS.
- Não renomeie IDs, classes, funções JavaScript, parâmetros ou campos sem necessidade.
- Verifique referências cruzadas antes de renomear qualquer identificador.
- Mantenha JavaScript compatível com o padrão atual do projeto.
- Não introduza bundler, TypeScript, Node.js, npm ou framework frontend sem pedido explícito.

## Banco de dados

- Não altere schema, dados, índices, procedures, events ou configurações do banco sem pedido explícito.
- Não execute comandos destrutivos.
- Nunca invente nomes de tabelas, colunas, bancos, hosts, portas ou credenciais.
- Antes de alterar SQL existente, confirme tabelas, colunas, joins, filtros e parâmetros no próprio código.
- Dê atenção a SQL injection e escaping, mas não faça refatoração ampla fora do escopo solicitado.

## Segurança

Ao alterar ou revisar código, observe especialmente:

- SQL injection.
- XSS e saída HTML sem escaping.
- Entradas vindas de GET, POST, cookies e headers.
- Upload/download de arquivos.
- Sessão e autenticação.
- CSRF em ações mutáveis.
- Caminhos de arquivo manipuláveis pelo usuário.
- Exposição de mensagens de erro, credenciais ou dados sensíveis.

Corrija problemas de segurança somente quando fizerem parte do pedido ou forem consequência direta da alteração em execução. Caso contrário, reporte-os separadamente.

## Git

Comandos somente de leitura podem ser usados sem pedir permissão, por exemplo:

- `git status`
- `git diff`
- `git log`
- `git show`
- `git branch`
- `git remote`
- `git rev-parse`
- `git ls-files`
- `git grep`

Ações mutáveis ou consequenciais exigem aprovação, incluindo:

- `git add`
- `git commit`
- `git push`
- `git pull`
- `git merge`
- `git rebase`
- `git reset`
- `git restore`
- `git checkout` quando alterar arquivos/branch
- criação ou remoção de branch/tag
- limpeza de arquivos
- qualquer ação destrutiva

Não faça commit ou push sem pedido explícito.

## PowerShell

Use PowerShell 7 quando disponível.
Evite depender de `&&` e `||`; prefira comandos separados e tratamento explícito de erros.
Scripts fornecidos pelo projeto devem falhar com código de saída diferente de zero quando uma validação falhar.

## Aprovação

Leituras, buscas, inspeções, `git diff/status/log/show` e validações de sintaxe sem alteração podem ser executadas sem aprovação.

Edição de arquivos e comandos potencialmente mutáveis devem seguir a política de aprovação configurada no `opencode.json`.

## Fluxo recomendado

### PLAN

- Investiga.
- Lê arquivos.
- Mapeia dependências.
- Identifica riscos.
- Propõe passos pequenos.
- Não edita.

### BUILD

- Executa somente o escopo solicitado.
- Faz alterações mínimas.
- Valida sintaxe dos PHP modificados.
- Inspeciona `git diff`.
- Não faz commit/push por conta própria.

### REVIEW

- Não edita.
- Revisa o diff e arquivos relacionados.
- Procura regressões, bugs, segurança e inconsistências.
- Distingue claramente inspeção estática, sintaxe, runtime e integração real.

## Ao concluir uma alteração

Informe objetivamente:

1. Arquivos alterados.
2. O que mudou.
3. Validações realmente executadas.
4. Resultado dessas validações.
5. O que não foi possível validar.
6. Riscos ou pendências relevantes.

Nunca invente testes, saídas ou resultados.
