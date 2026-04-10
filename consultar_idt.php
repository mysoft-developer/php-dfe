<?php
// consultar_idt.php

date_default_timezone_set('America/Sao_Paulo');
mysqli_report(MYSQLI_REPORT_OFF);
@ini_set('max_execution_time', '0');
@set_time_limit(0);
@ini_set('default_socket_timeout', '600');

$hostMysqlCentral = '10.8.0.6';
$portaMysqlCentral = 3306;
$bancoMysqlCentral = 'mysoft';
$usuarioMysql = 'mysoftweb';
$senhaMysql   = 'g3108f88';

function h($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function classeColunaTabela($nomeColuna)
{
    $nomeColuna = strtolower(trim((string)$nomeColuna));

    $mapaAcentos = array(
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c'
    );

    $nomeColuna = strtr($nomeColuna, $mapaAcentos);
    $nomeColuna = str_replace(array(' ', '_'), '-', $nomeColuna);
    $nomeColuna = preg_replace('/[^a-z0-9\-]/', '', $nomeColuna);

    if ($nomeColuna === 'query') {
        $nomeColuna = 'querie';
    }

    return 'col-' . $nomeColuna;
}

function buscarResultadosStatement($stmt)
{
    $linhas = array();

    if (method_exists($stmt, 'get_result')) {
        $resultado = @$stmt->get_result();
        if ($resultado instanceof mysqli_result) {
            while ($linha = $resultado->fetch_assoc()) {
                $linhas[] = $linha;
            }
            $resultado->free();
            return $linhas;
        }
    }

    $meta = $stmt->result_metadata();
    if (!$meta) {
        return $linhas;
    }

    $linhaAtual = array();
    $binds = array();
    $campos = array();

    while ($campo = $meta->fetch_field()) {
        $nomeCampo = $campo->name;
        $campos[] = $nomeCampo;
        $linhaAtual[$nomeCampo] = null;
        $binds[] = &$linhaAtual[$nomeCampo];
    }

    if (count($binds) > 0) {
        call_user_func_array(array($stmt, 'bind_result'), $binds);

        while ($stmt->fetch()) {
            $registro = array();
            foreach ($campos as $campo) {
                $registro[$campo] = $linhaAtual[$campo];
            }
            $linhas[] = $registro;
        }
    }

    $meta->free();

    return $linhas;
}

function conectarMysql($host, $usuario, $senha, $banco, $porta)
{
    try {
        $conn = @new mysqli($host, $usuario, $senha, $banco, (int)$porta);
    } catch (Throwable $e) {
        return false;
    }

    if (!$conn || $conn->connect_errno) {
        if ($conn instanceof mysqli) {
            $conn->close();
        }
        return false;
    }

    @$conn->set_charset('utf8mb4');
    return $conn;
}

function carregarGrupos($host, $porta, $banco, $usuario, $senha)
{
    $grupos = array();
    $mensagemErro = '';

    $conn = conectarMysql($host, $usuario, $senha, $banco, $porta);
    if (!$conn instanceof mysqli) {
        return array($grupos, 'Não foi possível conectar na base central de servidores.');
    }

    $sql = 'select grupo from servidores where ifnull(trim(grupo), "") <> "" group by grupo order by grupo';
    $resultado = $conn->query($sql);

    if ($resultado === false) {
        $mensagemErro = 'Erro ao carregar grupos da tabela servidores - ' . $conn->error;
        $conn->close();
        return array($grupos, $mensagemErro);
    }

    while ($linha = $resultado->fetch_assoc()) {
        $grupo = isset($linha['grupo']) ? trim((string)$linha['grupo']) : '';
        if ($grupo !== '') {
            $grupos[] = $grupo;
        }
    }

    $resultado->free();
    $conn->close();

    return array($grupos, '');
}

function carregarServidoresPorGrupo($host, $porta, $banco, $usuario, $senha, $grupo)
{
    $servidores = array();
    $mensagemErro = '';

    $conn = conectarMysql($host, $usuario, $senha, $banco, $porta);
    if (!$conn instanceof mysqli) {
        return array($servidores, 'Não foi possível conectar na base central de servidores.');
    }

    $sql = 'select nome, grupo, endereco, porta, `database` '
         . 'from servidores '
         . 'where grupo = ? '
         . 'order by nome, `database`, endereco, porta';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $mensagemErro = 'Erro ao preparar leitura da tabela servidores - ' . $conn->error;
        $conn->close();
        return array($servidores, $mensagemErro);
    }

    $grupoParam = (string)$grupo;
    $stmt->bind_param('s', $grupoParam);

    if (!$stmt->execute()) {
        $mensagemErro = 'Erro ao executar leitura da tabela servidores - ' . $stmt->error;
        $stmt->close();
        $conn->close();
        return array($servidores, $mensagemErro);
    }

    $servidores = buscarResultadosStatement($stmt);
    $stmt->close();
    $conn->close();

    return array($servidores, '');
}

$dataConsulta = isset($_POST['data_consulta']) ? trim((string)$_POST['data_consulta']) : (isset($_GET['data_consulta']) ? trim((string)$_GET['data_consulta']) : '');
$idt = isset($_POST['idt']) ? trim((string)$_POST['idt']) : (isset($_GET['idt']) ? trim((string)$_GET['idt']) : '');
$grupoSelecionado = isset($_POST['grupo']) ? trim((string)$_POST['grupo']) : (isset($_GET['grupo']) ? trim((string)$_GET['grupo']) : '');
$consultaAutomatica = $_SERVER['REQUEST_METHOD'] !== 'POST'
    && isset($_GET['auto'])
    && (string)$_GET['auto'] === '1'
    && $dataConsulta !== ''
    && $idt !== '';
$mensagensErro = array();
$mensagensOk = array();
$resultados = array();
$colunasResultado = array();
$consultado = false;

list($gruposDisponiveis, $erroGrupos) = carregarGrupos($hostMysqlCentral, $portaMysqlCentral, $bancoMysqlCentral, $usuarioMysql, $senhaMysql);
if ($erroGrupos !== '') {
    $mensagensErro[] = $erroGrupos;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($grupoSelecionado === '') {
            $mensagensErro[] = 'Selecione um grupo para consultar.';
        } elseif (!in_array($grupoSelecionado, $gruposDisponiveis, true)) {
            $mensagensErro[] = 'Grupo inválido.';
        }

        if ($dataConsulta === '') {
            $mensagensErro[] = 'Informe a data no formato yyyymmdd.';
        } elseif (!preg_match('/^\d{8}$/', $dataConsulta)) {
            $mensagensErro[] = 'A data deve estar no formato yyyymmdd.';
        }

        if ($idt === '') {
            $mensagensErro[] = 'Informe o IDT.';
        } elseif (!ctype_digit($idt)) {
            $mensagensErro[] = 'O IDT deve conter apenas números.';
        }

        if (count($mensagensErro) === 0) {
            list($servidores, $erroServidores) = carregarServidoresPorGrupo(
                $hostMysqlCentral,
                $portaMysqlCentral,
                $bancoMysqlCentral,
                $usuarioMysql,
                $senhaMysql,
                $grupoSelecionado
            );

            if ($erroServidores !== '') {
                $mensagensErro[] = $erroServidores;
            } elseif (count($servidores) === 0) {
                $mensagensErro[] = 'Nenhum servidor encontrado para o grupo selecionado.';
            }
        }

        if (count($mensagensErro) === 0) {
            $consultado = true;
            $tabelaLogs = 'mysoft_logs.autonfe_' . $dataConsulta;
            $idtInt = (int)$idt;

            foreach ($servidores as $item) {
                $nomeServidor = isset($item['nome']) ? trim((string)$item['nome']) : '';
                $grupoServidor = isset($item['grupo']) ? trim((string)$item['grupo']) : '';
                $endereco     = isset($item['endereco']) ? trim((string)$item['endereco']) : '';
                $porta        = isset($item['porta']) ? (int)$item['porta'] : 3306;
                $database     = isset($item['database']) ? trim((string)$item['database']) : '';

                $localConexao = $endereco . ':' . $porta . '/' . $database;

                if ($endereco === '' || $database === '') {
                    $mensagensErro[] = 'Configuração incompleta em ' . $localConexao;
                    continue;
                }

                $conn = conectarMysql($endereco, $usuarioMysql, $senhaMysql, $database, $porta);
                if (!$conn instanceof mysqli) {
                    $mensagensErro[] = 'Erro em ' . $localConexao . ' - falha ao criar conexão';
                    continue;
                }

                $sql = 'SELECT * '
                     . 'FROM ' . $tabelaLogs . ' '
                     . 'WHERE idt = ? '
                     . 'ORDER BY hora DESC, ms DESC';

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    $mensagensErro[] = 'Erro ao preparar consulta em ' . $localConexao . ' - ' . $conn->error;
                    $conn->close();
                    continue;
                }

                $stmt->bind_param('i', $idtInt);

                if (!$stmt->execute()) {
                    $mensagensErro[] = 'Erro ao executar consulta em ' . $localConexao . ' - ' . $stmt->error;
                    $stmt->close();
                    $conn->close();
                    continue;
                }

                $linhas = buscarResultadosStatement($stmt);

                if (count($linhas) > 0 && count($colunasResultado) === 0) {
                    $colunasResultado = array_keys($linhas[0]);
                }

                foreach ($linhas as $linha) {
                    $linha['_servidor'] = $endereco;
                    $linha['_porta'] = $porta;
                    $linha['_database'] = $database;
                    $linha['_nome'] = $nomeServidor;
                    $linha['_grupo'] = $grupoServidor;
                    $resultados[] = $linha;
                }

                $stmt->close();
                $conn->close();
            }

            if (count($resultados) > 1) {
                usort($resultados, function ($a, $b) {
                    $horaA = isset($a['hora']) ? (string)$a['hora'] : '';
                    $horaB = isset($b['hora']) ? (string)$b['hora'] : '';

                    if ($horaA !== $horaB) {
                        return strcmp($horaB, $horaA);
                    }

                    $msA = isset($a['ms']) ? (int)$a['ms'] : 0;
                    $msB = isset($b['ms']) ? (int)$b['ms'] : 0;

                    if ($msA == $msB) {
                        return 0;
                    }

                    return ($msA < $msB) ? 1 : -1;
                });
            }
        }
    } catch (Exception $e) {
        $mensagensErro[] = $e->getMessage();
    }
}

$versaoCssBase = @filemtime(__DIR__ . '/consultar_notas_servidores.css');
$versaoCssLocal = @filemtime(__DIR__ . '/consultar_idt.css');
$totalResultados = count($resultados);
$totalErros = count($mensagensErro);
$totalGrupos = count($gruposDisponiveis);
$servidoresComRetorno = array();
foreach ($resultados as $linhaResultado) {
    $chaveServidor = (isset($linhaResultado['_servidor']) ? (string) $linhaResultado['_servidor'] : '') . ':' . (isset($linhaResultado['_porta']) ? (string) $linhaResultado['_porta'] : '') . '/' . (isset($linhaResultado['_database']) ? (string) $linhaResultado['_database'] : '');
    if ($chaveServidor !== '') {
        $servidoresComRetorno[$chaveServidor] = true;
    }
}
$totalServidoresComRetorno = count($servidoresComRetorno);
$totalColunasResultado = count($colunasResultado);
$statusPainel = 'Informe a data, o IDT e selecione o grupo para consultar os logs AutoNFe pela tabela central de servidores.';
if ($consultado) {
    if ($totalResultados > 0) {
        $statusPainel = 'Consulta concluída para o grupo ' . $grupoSelecionado . ' com ' . $totalResultados . ' registro(s) encontrado(s).';
    } elseif ($totalErros === 0) {
        $statusPainel = 'Consulta concluída. Nenhum registro encontrado para a data, o IDT e o grupo informados.';
    } else {
        $statusPainel = 'Consulta concluída com alertas. Verifique as mensagens exibidas abaixo.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Consulta Logs AutoNFe</title>
<link rel="stylesheet" href="consultar_notas_servidores.css<?php echo $versaoCssBase ? '?v=' . $versaoCssBase : ''; ?>">
<link rel="stylesheet" href="consultar_idt.css<?php echo $versaoCssLocal ? '?v=' . $versaoCssLocal : ''; ?>">
<script>
var CONSULTA_AUTOMATICA_IDT = <?php echo $consultaAutomatica ? 'true' : 'false'; ?>;
function mostrarOverlayConsultaIdt(texto, subtexto) {
    var overlay = document.getElementById('overlayConsultaIdt');
    if (!overlay) { return; }
    var textoEl = document.getElementById('overlayConsultaIdtTexto');
    var subtextoEl = document.getElementById('overlayConsultaIdtSubtexto');
    if (textoEl) {
        textoEl.textContent = texto || 'Consultando logs do IDT...';
    }
    if (subtextoEl) {
        subtextoEl.textContent = subtexto || 'Aguarde enquanto os servidores do grupo são consultados.';
    }
    overlay.hidden = false;
}
function prepararFormularioConsultaIdt() {
    mostrarOverlayConsultaIdt('Consultando logs do IDT...', 'Aguarde enquanto os servidores do grupo são consultados.');
    var botao = document.getElementById('botaoConsultarIdt');
    if (botao) {
        botao.disabled = true;
    }
    return true;
}
window.addEventListener('pageshow', function() {
    var botao = document.getElementById('botaoConsultarIdt');
    if (botao) {
        botao.disabled = false;
    }
});
window.addEventListener('DOMContentLoaded', function() {
    var formulario = document.getElementById('formConsultaIdt');
    if (!formulario) { return; }
    if (CONSULTA_AUTOMATICA_IDT) {
        mostrarOverlayConsultaIdt('Consultando logs do IDT...', 'Aguarde enquanto a consulta automática é disparada.');
        window.setTimeout(function() {
            formulario.submit();
        }, 40);
    }
});
</script>
</head>
<body>
<div id="overlayConsultaIdt" class="overlay-consulta-idt"<?php echo $consultaAutomatica ? "" : " hidden"; ?>>
    <div class="overlay-consulta-idt-box">
        <div class="overlay-consulta-idt-spinner"></div>
        <div class="overlay-consulta-idt-titulo">Carregando</div>
        <div id="overlayConsultaIdtTexto" class="overlay-consulta-idt-texto">Consultando logs do IDT...</div>
        <div id="overlayConsultaIdtSubtexto" class="overlay-consulta-idt-subtexto">Aguarde enquanto os servidores do grupo são consultados.</div>
    </div>
</div>
<div class="app-shell">
    <header class="topbar">
        <div class="title-row">
            <div class="title-block">
                <h1>Consulta Logs AutoNFe</h1>
                <p>Visual alinhado com os outros painéis, mantendo a busca por data e IDT, agora com seleção prévia do grupo carregado da tabela mysql central.</p>
            </div>
            <div class="tag-row">
                <span class="chip"><span>Origem</span> <strong>mysql / servidores</strong></span>
                <span class="chip"><span>Data</span> <strong><?php echo h($dataConsulta !== '' ? $dataConsulta : '---'); ?></strong></span>
                <span class="chip"><span>IDT</span> <strong><?php echo h($idt !== '' ? $idt : '---'); ?></strong></span>
                <span class="chip live"><span>Registros</span> <strong><?php echo (int) $totalResultados; ?></strong></span>
            </div>
        </div>

        <div class="toolbar-grid">
            <section class="panel panel-form">
                <form id="formConsultaIdt" class="form-consulta-idt" method="post" onsubmit="return prepararFormularioConsultaIdt()">
                    <div class="field">
                        <label for="grupo">Grupo</label>
                        <select name="grupo" id="grupo">
                            <option value="">Selecione</option>
                            <?php foreach ($gruposDisponiveis as $grupo): ?>
                                <option value="<?php echo h($grupo); ?>"<?php echo $grupoSelecionado === $grupo ? ' selected' : ''; ?>><?php echo h($grupo); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="data_consulta">Data</label>
                        <input type="text" name="data_consulta" id="data_consulta" value="<?php echo h($dataConsulta); ?>" placeholder="yyyymmdd" autocomplete="off">
                    </div>

                    <div class="field">
                        <label for="idt">IDT</label>
                        <input type="text" name="idt" id="idt" value="<?php echo h($idt); ?>" autocomplete="off">
                    </div>

                    <div>
                        <button class="botao" id="botaoConsultarIdt" type="submit">Consultar</button>
                    </div>
                </form>
            </section>

            <section class="telemetry">
                <div class="panel metric">
                    <div class="label">Base central</div>
                    <div class="value" style="font-size: 20px;">10.8.0.6</div>
                    <div class="hint">mysoft / tabela servidores</div>
                </div>
                <div class="panel metric ok">
                    <div class="label">Registros</div>
                    <div class="value"><?php echo (int) $totalResultados; ?></div>
                    <div class="hint">Linhas retornadas pela consulta</div>
                </div>
                <div class="panel metric warn">
                    <div class="label">Servidores com retorno</div>
                    <div class="value"><?php echo (int) $totalServidoresComRetorno; ?></div>
                    <div class="hint">Bases onde o IDT apareceu</div>
                </div>
                <div class="panel metric">
                    <div class="label">Grupos</div>
                    <div class="value"><?php echo (int) $totalGrupos; ?></div>
                    <div class="hint">Grupos disponíveis no combobox</div>
                </div>
                <div class="panel metric danger">
                    <div class="label">Alertas</div>
                    <div class="value"><?php echo (int) $totalErros; ?></div>
                    <div class="hint">Erros encontrados durante a leitura</div>
                </div>
            </section>
        </div>

        <div class="status-bar">
            <span class="status-dot"></span>
            <div class="status-text"><?php echo h($statusPainel); ?></div>
            <span class="cache-pill cache-local">Leitura direta do MySQL</span>
        </div>
    </header>

    <main class="content-shell">
        <?php if (count($mensagensOk) > 0 || count($mensagensErro) > 0 || !$consultado): ?>
        <section class="panel message-panel">
            <div class="message-stack">
                <?php if (!$consultado && !$consultaAutomatica): ?>
                    <div class="message-line info">Informe a data, o IDT e selecione um grupo antes de consultar.</div>
                <?php endif; ?>

                <?php foreach ($mensagensOk as $msg): ?>
                    <div class="message-line ok"><?php echo h($msg); ?></div>
                <?php endforeach; ?>

                <?php foreach ($mensagensErro as $erro): ?>
                    <div class="message-line erro"><?php echo h($erro); ?></div>
                <?php endforeach; ?>

                <?php if ($consultado && count($resultados) === 0 && count($mensagensErro) === 0): ?>
                    <div class="message-line info">Nenhum registro encontrado para a data, o IDT e o grupo informados.</div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <section class="surface">
            <div class="surface-head">
                <div>
                    <div class="surface-title">Resultado da consulta</div>
                    <div class="surface-subtitle">Lista ordenada por hora decrescente e milissegundos, preservando a lógica original do script.</div>
                </div>
                <div class="toolbar-inline">
                    <span class="counter-pill">Grupo <strong><?php echo h($grupoSelecionado !== '' ? $grupoSelecionado : '---'); ?></strong></span>
                    <span class="counter-pill">Servidores <strong><?php echo (int) $totalServidoresComRetorno; ?></strong></span>
                    <span class="counter-pill">Registros <strong><?php echo (int) $totalResultados; ?></strong></span>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <colgroup>
                        <col class="col-servidor">
                        <col class="col-porta">
                        <col class="col-database">
                        <?php foreach ($colunasResultado as $coluna): ?>
                            <col class="<?php echo h(classeColunaTabela($coluna)); ?>">
                        <?php endforeach; ?>
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="col-servidor">Servidor</th>
                            <th class="col-porta">Porta</th>
                            <th class="col-database">Database</th>
                            <?php foreach ($colunasResultado as $coluna): ?>
                                <?php $classeColuna = classeColunaTabela($coluna); ?>
                                <th class="<?php echo h($classeColuna); ?>"><?php echo h($coluna); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($resultados) > 0): ?>
                            <?php foreach ($resultados as $linha): ?>
                                <tr>
                                    <td class="col-servidor"><?php echo h($linha['_servidor']); ?></td>
                                    <td class="col-porta"><?php echo h($linha['_porta']); ?></td>
                                    <td class="col-database"><?php echo h($linha['_database']); ?></td>
                                    <?php foreach ($colunasResultado as $coluna): ?>
                                        <?php $classeColuna = classeColunaTabela($coluna); ?>
                                        <td class="<?php echo h($classeColuna); ?>"><?php echo h(isset($linha[$coluna]) ? $linha[$coluna] : ''); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="empty-state" style="<?php echo count($resultados) > 0 ? 'display:none;' : ''; ?>">
                <?php echo h($consultado ? 'Nenhum registro encontrado para a consulta atual.' : ($consultaAutomatica ? 'Preparando consulta automática do IDT informado.' : 'Preencha os dados, selecione o grupo e clique em Consultar para carregar os logs.')); ?>
            </div>
        </section>
    </main>
</div>
</body>
</html>
