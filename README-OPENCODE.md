# OpenCode — php-dfe

Este pacote configura o OpenCode especificamente para o projeto:

`C:\httpd-2.4.46-win64-VS16\htdocs\php-dfe`

Entrada principal:

`consultar_nota_servidores.php`

## Estrutura

- `AGENTS.md` — regras gerais do projeto.
- `opencode.json` — agentes, modelo e permissões.
- `prompts/plan.txt` — planejamento sem edição.
- `prompts/build.txt` — implementação controlada.
- `prompts/review.txt` — revisão sem edição.
- `.opencode/commands/` — comandos rápidos do projeto.
- `.opencode/skills/php-dfe/SKILL.md` — conhecimento operacional reutilizável.
- `scripts/check-environment.ps1` — verifica PHP, Apache, entrada e Git.
- `scripts/check-php.ps1` — valida sintaxe PHP.

## Modelo

Os agentes PLAN, BUILD e REVIEW estão configurados para:

`openai/gpt-5.6-luna`

O pacote não configura chave/API/provider global. Ele usa o provider OpenAI que já estiver configurado no OpenCode.

## Fluxo recomendado

1. Use `plan` para investigar e montar a solução.
2. Use `build` para implementar.
3. Use `review` para revisar o diff sem editar.
4. Se REVIEW apontar correções, volte ao BUILD com os achados.

## Comandos personalizados

- `/ambiente` — inspeciona o ambiente do projeto.
- `/planejar <pedido>` — analisa um pedido sem editar.
- `/implementar <pedido>` — executa uma alteração.
- `/validar-php` — valida sintaxe dos PHP.
- `/revisar` — revisa as alterações atuais.

## Aprovações

Ficam liberados sem aprovação:

- leitura e busca;
- `git status`;
- `git diff`;
- `git log`;
- `git show`;
- inspeção de branches/remotos;
- `php -l`;
- scripts de validação fornecidos neste pacote.

Continuam pedindo aprovação:

- edição;
- comandos de shell não reconhecidos como leitura/validação;
- Git mutável;
- instalação/atualização;
- operações potencialmente destrutivas ou consequenciais.

## Instalação

Extraia o conteúdo deste pacote diretamente na raiz de:

`C:\httpd-2.4.46-win64-VS16\htdocs\php-dfe`

Depois abra um PowerShell nessa pasta e execute o OpenCode normalmente.

Para checar o ambiente:

`pwsh -NoProfile -File .\scripts\check-environment.ps1`

Para validar PHP:

`pwsh -NoProfile -File .\scripts\check-php.ps1`
