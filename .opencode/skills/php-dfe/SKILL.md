---
name: php-dfe
description: Regras específicas para investigar, alterar e revisar o projeto php-dfe em PHP, HTML, CSS e JavaScript, partindo de consultar_nota_servidores.php e preservando o comportamento existente.
compatibility: opencode
metadata:
  project: php-dfe
  stack: php-html-css-js
---

## Contexto

Projeto: `php-dfe`

Raiz esperada:
`C:\httpd-2.4.46-win64-VS16\htdocs\php-dfe`

Entrada principal:
`consultar_nota_servidores.php`

Tecnologias:
- PHP
- HTML
- CSS
- JavaScript
- Apache no Windows

## Como investigar

1. Comece no arquivo relacionado ao pedido.
2. Quando precisar entender a navegação geral, comece em `consultar_nota_servidores.php`.
3. Siga:
   - `include`
   - `include_once`
   - `require`
   - `require_once`
   - formulários
   - links
   - Ajax
   - `fetch`
   - chamadas JavaScript
   - endpoints PHP
4. Use busca antes de renomear IDs, classes, funções, parâmetros, tabelas ou campos.

## Como alterar

- Faça a menor mudança necessária.
- Preserve visual e comportamento fora do pedido.
- Não introduza framework, npm, bundler ou TypeScript.
- Não mude biblioteca de banco por preferência pessoal.
- Não altere banco ou dados sem pedido explícito.
- Não crie arquivos de backup no projeto.

## Validação

Para cada PHP alterado:
`php -l caminho\arquivo.php`

Ao final:
- inspecione `git diff`;
- diferencie sintaxe de runtime;
- não afirme teste em browser, Apache ou banco sem realmente executá-lo.

## Revisão

Procure principalmente:
- regressão funcional;
- SQL injection;
- XSS;
- validação de entrada;
- sessão/autorização;
- CSRF;
- JS quebrado;
- IDs/classes alterados;
- chamadas Ajax/fetch incompatíveis;
- tratamento de erro insuficiente.
