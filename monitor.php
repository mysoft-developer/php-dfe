<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function escapar($valor): string
{
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tamanhoEmGb($valor): ?float
{
    if (!preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*GB\b/i', (string)$valor, $correspondencia)) {
        return null;
    }

    return (float)str_replace(',', '.', $correspondencia[1]);
}

function exibirValorTabela($valor): string
{
    $texto = trim((string)($valor ?? 'N/A'));
    $linhaPrincipal = $texto;
    $linhaSecundaria = '';

    if (preg_match('/^(ERRO:)\s*(.*)$/s', $texto, $correspondencia)) {
        $linhaPrincipal = $correspondencia[1];
        $linhaSecundaria = $correspondencia[2];
    } elseif (preg_match('/^([^\s]+)\s+([0-9]+(?:[.,][0-9]+)?\s*GB)$/i', $texto, $correspondencia)) {
        $linhaPrincipal = $correspondencia[1];
        $linhaSecundaria = $correspondencia[2];
    }

    $html = '<div class="valor-tabela-principal">' . escapar($linhaPrincipal) . '</div>';
    if ($linhaSecundaria !== '') {
        $html .= '<div class="valor-tabela-secundaria">' . escapar($linhaSecundaria) . '</div>';
    }
    return $html;
}

$servidores = [];
$regras = [];
$erro = '';
$monitorAtivo = false;
$cicloMonitor = 0;
$banco = null;

try {
    $arquivoConfiguracao = __DIR__ . '/config.json';
    $configuracao = is_file($arquivoConfiguracao)
        ? json_decode((string) file_get_contents($arquivoConfiguracao), true)
        : null;
    $configuracaoMysql = is_array($configuracao) && isset($configuracao['server_mysql'])
        && is_array($configuracao['server_mysql']) ? $configuracao['server_mysql'] : [];
    $hostMysql = trim((string) ($configuracaoMysql['host'] ?? ''));
    $databaseMysql = trim((string) ($configuracaoMysql['database'] ?? ''));
    $usuarioMysql = (string) ($configuracaoMysql['user'] ?? '');
    $senhaMysql = (string) ($configuracaoMysql['password'] ?? '');
    $portaMysql = (int) ($configuracaoMysql['port'] ?? 3306);
    if ($hostMysql === '' || $databaseMysql === '' || $usuarioMysql === '' || $portaMysql < 1 || $portaMysql > 65535) {
        throw new RuntimeException('Configuração server_mysql incompleta no config.json.');
    }
    if (!function_exists('mysqli_init')) {
        throw new RuntimeException('A extensão MySQLi do PHP não está habilitada.');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $banco = mysqli_init();
    if ($banco === false || !@$banco->real_connect($hostMysql, $usuarioMysql, $senhaMysql, $databaseMysql, $portaMysql)) {
        throw new RuntimeException('Não foi possível conectar ao MySQL configurado em server_mysql.');
    }
    $banco->set_charset('utf8mb4');

    $stmtTabela = $banco->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1');
    if ($stmtTabela === false) {
        throw new RuntimeException('Não foi possível verificar as tabelas do monitor no MySQL.');
    }
    $nomeTabela = 'monitor_servidores';
    $stmtTabela->bind_param('ss', $databaseMysql, $nomeTabela);
    $stmtTabela->execute();
    $resultadoTabela = $stmtTabela->get_result();
    $existeTabela = $resultadoTabela->fetch_row() !== null;
    $resultadoTabela->free();
    if (!$existeTabela) {
        $stmtTabela->close();
        throw new RuntimeException('A tabela monitor_servidores não existe no banco MySQL configurado.');
    }
    $nomeTabela = 'monitor_regras';
    $stmtTabela->bind_param('ss', $databaseMysql, $nomeTabela);
    $stmtTabela->execute();
    $resultadoTabela = $stmtTabela->get_result();
    $existeTabela = $resultadoTabela->fetch_row() !== null;
    $resultadoTabela->free();
    $stmtTabela->close();
    if (!$existeTabela) {
        throw new RuntimeException('A tabela monitor_regras não existe no banco MySQL configurado.');
    }

    $resultado = $banco->query(
        'SELECT nome, grupo, endereco, porta, `database`, rede, log, log_sis, '
        . 'qtde, origens FROM monitor_servidores '
        . 'ORDER BY grupo, endereco, porta, `database`'
    );
    $servidores = $resultado->fetch_all(MYSQLI_ASSOC);
    $resultadoRegras = $banco->query(
        'SELECT ordem, operacao, status, status_descricao, acao, tolerancia_minutos, qtde '
        . 'FROM monitor_regras ORDER BY ordem'
    );
    $regras = $resultadoRegras->fetch_all(MYSQLI_ASSOC);
    $nomeTabela = 'monitor_status';
    $stmtTabela = $banco->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1');
    if ($stmtTabela === false) {
        throw new RuntimeException('Não foi possível verificar a tabela de status do monitor no MySQL.');
    }
    $stmtTabela->bind_param('ss', $databaseMysql, $nomeTabela);
    $stmtTabela->execute();
    $resultadoTabela = $stmtTabela->get_result();
    $existeTabelaStatus = $resultadoTabela->fetch_row() !== null;
    $resultadoTabela->free();
    $stmtTabela->close();
    if ($existeTabelaStatus) {
        $nomeColuna = 'ciclo';
        $stmtColuna = $banco->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1');
        if ($stmtColuna === false) {
            throw new RuntimeException('Não foi possível verificar a coluna ciclo do monitor no MySQL.');
        }
        $stmtColuna->bind_param('sss', $databaseMysql, $nomeTabela, $nomeColuna);
        $stmtColuna->execute();
        $resultadoColuna = $stmtColuna->get_result();
        $temColunaCiclo = $resultadoColuna->fetch_row() !== null;
        $resultadoColuna->free();
        $stmtColuna->close();
        $selectCiclo = $temColunaCiclo ? 'ciclo' : '0 AS ciclo';
        $statusMonitor = $banco->query(
            "SELECT fase, {$selectCiclo}, CASE WHEN atualizado_em >= NOW() - INTERVAL 10 MINUTE "
            . 'THEN 1 ELSE 0 END AS recente FROM monitor_status WHERE id = 1'
        )->fetch_assoc();
        $monitorAtivo = $statusMonitor
            && in_array($statusMonitor['fase'], ['consultando_servidores', 'executando_regras', 'aguardando'], true)
            && (int)$statusMonitor['recente'] === 1;
        $cicloMonitor = (int)($statusMonitor['ciclo'] ?? 0);
    }
    $totalRegras = array_sum(array_map(
        static function (array $regra): int {
            return (int)($regra['qtde'] ?? 0);
        },
        $regras
    ));
} catch (RuntimeException $excecao) {
    $erro = $excecao->getMessage();
} catch (Throwable $excecao) {
    $erro = 'Não foi possível ler as tabelas do monitor no MySQL. Confira a conexão e a estrutura das tabelas.';
} finally {
    if ($banco instanceof mysqli) {
        $banco->close();
    }
}

$totalServidores = count($servidores);
$servidores = array_values(array_filter(
    $servidores,
    static function (array $servidor): bool {
        $logGb = tamanhoEmGb($servidor['log'] ?? null);
        $logSisGb = tamanhoEmGb($servidor['log_sis'] ?? null);
        $quantidadeNotas = (int)($servidor['qtde'] ?? 0);

        return ($logGb !== null && $logGb > 2)
            || ($logSisGb !== null && $logSisGb > 2)
            || $quantidadeNotas > 1000;
    }
));

$paletaGrupos = ['#e8f1fb', '#e8f5e9', '#fff3e0', '#f3e8f9', '#e0f7fa', '#fce4ec'];
$coresPorGrupo = [];
foreach ($servidores as $servidor) {
    $grupo = (string)($servidor['grupo'] ?? '');
    if (!array_key_exists($grupo, $coresPorGrupo)) {
        $coresPorGrupo[$grupo] = $paletaGrupos[count($coresPorGrupo) % count($paletaGrupos)];
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monitor de servidores</title>
    <style>
        html, body { height: 100%; overflow: hidden; }
        body { box-sizing: border-box; display: flex; flex-direction: column; margin: 0; padding: 14px 20px; background: #eef4fb; color: #17324d; font: 13px Arial, Helvetica, sans-serif; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        .atualizacao { margin: 0 0 8px; color: #52677b; }
        #dados-monitor { display: flex; flex: 1; min-width: 0; min-height: 0; }
        .painel-monitor { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); grid-template-rows: minmax(0, 1fr); flex: 1; min-width: 0; min-height: 0; height: 100%; gap: 10px; }
        .painel { display: grid; grid-template-rows: auto minmax(0, 1fr); min-width: 0; min-height: 0; height: 100%; }
        h2 { margin: 0 0 5px; font-size: 15px; }
        h2 small { margin-left: 8px; color: #52677b; font-size: 12px; font-weight: normal; }
        .rolagem { min-width: 0; min-height: 0; height: 100%; overflow-x: auto; overflow-y: scroll; overscroll-behavior: contain; scrollbar-gutter: stable; background: #fff; border: 1px solid #cbd8e6; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 7px; border-bottom: 1px solid #dce5ee; text-align: left; vertical-align: top; }
        th { position: sticky; top: 0; background: #dce9f5; white-space: nowrap; }
        tr:last-child td { border-bottom: 0; }
        .valor-tabela-principal { white-space: nowrap; }
        .valor-tabela-secundaria { white-space: normal; }
        @keyframes piscar-vermelho { 0%, 49% { background-color: #fde8e7; color: #7f1d1d; } 50%, 100% { background-color: #f4b6b6; color: #7f1d1d; } }
        .celula-alerta { animation: piscar-vermelho 1s steps(2, start) infinite; }
        @media (prefers-reduced-motion: reduce) { .celula-alerta { animation: none; background-color: #f8d7da; color: #7f1d1d; } }
        tr.regra-alterada td { background-color: #e8f5e9; }
        .erro { color: #a12622; font-weight: bold; }
        .aviso { padding: 12px; background: #fff; border: 1px solid #cbd8e6; }
    </style>
</head>
<body>
    <h1>Monitor de servidores</h1>
    <p class="atualizacao">Dados atualizados automaticamente via AJAX a cada 5 segundos.</p>
    <noscript><p class="aviso">Ative o JavaScript para atualizar os dados sem recarregar a página.</p></noscript>

    <div id="dados-monitor" data-monitor-ativo="<?= $monitorAtivo ? '1' : '0' ?>" data-ciclo="<?= escapar($cicloMonitor) ?>">
    <?php if ($erro !== ''): ?>
        <p class="aviso erro">Não foi possível carregar o monitor: <?= escapar($erro) ?></p>
    <?php else: ?>
        <div class="painel-monitor">
            <section class="painel">
                <h2>Servidores</h2>
                <div class="rolagem" id="rolagem-servidores">
                    <?php if (!$servidores): ?>
                        <p class="aviso">Nenhum servidor atende ao filtro (log &gt; 2 GB, log_sis &gt; 2 GB ou notas &gt; 1000; <?= escapar($totalServidores) ?> registro(s) avaliados).</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Servidor</th>
                                    <th>Endereço</th>
                                    <th>Porta</th>
                                    <th>Base</th>
                                    <th>Redes</th>
                                    <th>Log</th>
                                    <th>Log_sis</th>
                                    <th>Notas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($servidores as $servidor): ?>
                                    <?php $corGrupo = $coresPorGrupo[(string)($servidor['grupo'] ?? '')]; ?>
                                    <?php $logGb = tamanhoEmGb($servidor['log'] ?? null); ?>
                                    <?php $logSisGb = tamanhoEmGb($servidor['log_sis'] ?? null); ?>
                                    <?php $quantidadeNotas = (int)($servidor['qtde'] ?? 0); ?>
                                    <tr style="background-color: <?= escapar($corGrupo) ?>;">
                                        <td><?= escapar($servidor['nome'] ?: $servidor['grupo']) ?></td>
                                        <td><?= escapar($servidor['endereco']) ?></td>
                                        <td><?= escapar($servidor['porta']) ?></td>
                                        <td><?= escapar($servidor['database']) ?></td>
                                        <td><?= escapar($servidor['rede'] ?: 'N/A') ?></td>
                                        <td class="<?= $logGb !== null && $logGb > 2 ? 'celula-alerta' : '' ?>"><?= exibirValorTabela($servidor['log']) ?></td>
                                        <td class="<?= $logSisGb !== null && $logSisGb > 2 ? 'celula-alerta' : '' ?>"><?= exibirValorTabela($servidor['log_sis']) ?></td>
                                        <td class="<?= $quantidadeNotas > 1000 ? 'celula-alerta' : '' ?>"><?= escapar($servidor['qtde'] ?? 'N/A') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>

            <section class="painel">
                <h2>Regras <small>Total: <?= escapar($totalRegras ?? 0) ?></small></h2>
                <div class="rolagem">
                    <?php if (!$regras): ?>
                        <p class="aviso">Nenhuma regra ativa encontrada neste ciclo.</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Ordem</th>
                                    <th>Operação</th>
                                    <th>Status</th>
                                    <th>Descrição do status</th>
                                    <th>Ação</th>
                                    <th>Tolerância (min)</th>
                                    <th>Qtde</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($regras as $regra): ?>
                                    <tr data-regra-ordem="<?= escapar($regra['ordem']) ?>">
                                        <td><?= escapar($regra['ordem']) ?></td>
                                        <td><?= escapar($regra['operacao']) ?></td>
                                        <td><?= escapar($regra['status']) ?></td>
                                        <td><?= escapar($regra['status_descricao']) ?></td>
                                        <td><?= escapar($regra['acao']) ?></td>
                                        <td><?= escapar($regra['tolerancia_minutos']) ?></td>
                                        <td><?= escapar($regra['qtde']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    <?php endif; ?>
    </div>
    <script>
        let atualizacaoEmAndamento = false;
        let rolagemAutomaticaAtiva = false;
        let direcaoRolagem = 1;
        let ultimoFrameRolagem = null;
        let posicaoRolagemServidores = 0;
        let rolagemPausadaPeloUsuario = false;

        function animarRolagemServidores(tempoAtual) {
            if (!rolagemAutomaticaAtiva) {
                return;
            }
            const dados = document.getElementById('dados-monitor');
            const area = document.getElementById('rolagem-servidores');
            if (!dados || dados.dataset.monitorAtivo !== '1' || rolagemPausadaPeloUsuario || !area) {
                rolagemAutomaticaAtiva = false;
                ultimoFrameRolagem = null;
                return;
            }

            const limite = area.scrollHeight - area.clientHeight;
            if (limite <= 0) {
                ultimoFrameRolagem = null;
                window.requestAnimationFrame(animarRolagemServidores);
                return;
            }
            if (ultimoFrameRolagem !== null) {
                const segundos = Math.min((tempoAtual - ultimoFrameRolagem) / 1000, 0.1);
                posicaoRolagemServidores += direcaoRolagem * 18 * segundos;
                if (posicaoRolagemServidores >= limite) {
                    posicaoRolagemServidores = limite;
                    direcaoRolagem = -1;
                } else if (posicaoRolagemServidores <= 0) {
                    posicaoRolagemServidores = 0;
                    direcaoRolagem = 1;
                }
                area.scrollTop = posicaoRolagemServidores;
            }
            ultimoFrameRolagem = tempoAtual;
            window.requestAnimationFrame(animarRolagemServidores);
        }

        function sincronizarRolagemServidores() {
            const dados = document.getElementById('dados-monitor');
            const deveRolar = dados && dados.dataset.monitorAtivo === '1' && !rolagemPausadaPeloUsuario;
            if (deveRolar && !rolagemAutomaticaAtiva) {
                const area = document.getElementById('rolagem-servidores');
                if (area) {
                    area.scrollTop = 0;
                }
                rolagemAutomaticaAtiva = true;
                direcaoRolagem = 1;
                ultimoFrameRolagem = null;
                posicaoRolagemServidores = 0;
                window.requestAnimationFrame(animarRolagemServidores);
            } else if (!deveRolar) {
                rolagemAutomaticaAtiva = false;
                direcaoRolagem = 1;
                ultimoFrameRolagem = null;
            }
        }

        async function atualizarMonitor() {
            if (atualizacaoEmAndamento) {
                return;
            }
            atualizacaoEmAndamento = true;
            try {
                const endereco = new URL(window.location.href);
                endereco.searchParams.set('_atualizacao', Date.now().toString());
                const resposta = await fetch(endereco.toString(), { cache: 'no-store' });
                if (!resposta.ok) {
                    return;
                }
                const html = await resposta.text();
                const documento = new DOMParser().parseFromString(html, 'text/html');
                const novosDados = documento.getElementById('dados-monitor');
                const dadosAtuais = document.getElementById('dados-monitor');
                if (novosDados && dadosAtuais) {
                    const novoCiclo = novosDados.dataset.ciclo;
                    const cicloMudou = novoCiclo !== dadosAtuais.dataset.ciclo;
                    if (cicloMudou) {
                        rolagemPausadaPeloUsuario = false;
                        rolagemAutomaticaAtiva = false;
                        direcaoRolagem = 1;
                        ultimoFrameRolagem = null;
                        posicaoRolagemServidores = 0;
                    }
                    const valoresAnteriores = new Map();
                    const linhaAnteriorAtiva = dadosAtuais.querySelector('tr.regra-alterada');
                    let ordemRegraAtiva = cicloMudou || !linhaAnteriorAtiva
                        ? null
                        : linhaAnteriorAtiva.dataset.regraOrdem;
                    dadosAtuais.querySelectorAll('tr[data-regra-ordem]').forEach((linha) => {
                        const valores = Array.from(linha.cells).map((celula) => celula.textContent.trim());
                        valoresAnteriores.set(linha.dataset.regraOrdem, valores.join('\u001f'));
                    });
                    novosDados.querySelectorAll('tr[data-regra-ordem]').forEach((linha) => {
                        const valores = Array.from(linha.cells).map((celula) => celula.textContent.trim());
                        const chave = linha.dataset.regraOrdem;
                        if (!valoresAnteriores.has(chave) || valoresAnteriores.get(chave) !== valores.join('\u001f')) {
                            ordemRegraAtiva = chave;
                        }
                    });
                    novosDados.querySelectorAll('tr[data-regra-ordem]').forEach((linha) => {
                        if (ordemRegraAtiva !== null && linha.dataset.regraOrdem === ordemRegraAtiva) {
                            linha.classList.add('regra-alterada');
                        }
                    });
                    const posicoesRolagem = Array.from(dadosAtuais.querySelectorAll('.rolagem')).map(
                        (area) => ({ top: area.scrollTop, left: area.scrollLeft })
                    );
                    dadosAtuais.replaceWith(novosDados);
                    novosDados.querySelectorAll('.rolagem').forEach((area, indice) => {
                        if (posicoesRolagem[indice]) {
                            area.scrollTop = indice === 0 && cicloMudou
                                ? 0
                                : posicoesRolagem[indice].top;
                            area.scrollLeft = posicoesRolagem[indice].left;
                        }
                    });
                    sincronizarRolagemServidores();
                }
            } catch (erro) {
                // Mantém os últimos dados exibidos se uma atualização falhar.
            } finally {
                atualizacaoEmAndamento = false;
            }
        }

        document.addEventListener('click', (evento) => {
            if (evento.target.closest('#rolagem-servidores')) {
                rolagemPausadaPeloUsuario = true;
                rolagemAutomaticaAtiva = false;
                ultimoFrameRolagem = null;
            }
        });

        sincronizarRolagemServidores();
        window.setInterval(atualizarMonitor, 5000);
    </script>
</body>
</html>
