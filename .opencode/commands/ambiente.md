---
description: Verifica o ambiente do php-dfe sem alterar arquivos
agent: plan
---

Leia o AGENTS.md.

Inspecione o ambiente atual do projeto php-dfe sem modificar nada.

Execute, se disponível:

`pwsh -NoProfile -File scripts/check-environment.ps1`

Depois informe:
- raiz detectada;
- existência de consultar_nota_servidores.php;
- versão/caminho do PHP;
- existência do Apache;
- estado do Git;
- qualquer divergência objetiva encontrada.

Não faça correções automaticamente.
