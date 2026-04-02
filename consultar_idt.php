<?php
// consultar_idt.php

date_default_timezone_set('America/Sao_Paulo');
mysqli_report(MYSQLI_REPORT_OFF);

$arquivoCacheJson = __DIR__ . '/cache_consultar_idt_servidores.json';
$usuarioMysql = 'mysoftweb';
$senhaMysql   = 'g3108f88';

function h($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function lerConteudoArquivo($arquivo)
{
    if (!file_exists($arquivo)) {
        return '';
    }

    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        return '';
    }

    return $conteudo;
}

function salvarCacheJson($tmpName, $arquivoDestino)
{
    if (!is_uploaded_file($tmpName)) {
        throw new Exception('Arquivo JSON inválido.');
    }

    if (!move_uploaded_file($tmpName, $arquivoDestino)) {
        throw new Exception('Não foi possível salvar o JSON em cache.');
    }
}

function lerJsonServidoresDoConteudo($conteudo)
{
    $dados = json_decode($conteudo, true);

    if (!is_array($dados)) {
        throw new Exception('JSON inválido.');
    }

    if (!isset($dados['servidores']) || !is_array($dados['servidores'])) {
        throw new Exception('O JSON não possui a lista "servidores".');
    }

    return $dados['servidores'];
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

$dataConsulta = '';
$idt = '';
$mensagensErro = array();
$mensagensOk = array();
$resultados = array();
$colunasResultado = array();
$consultado = false;
$jsonEmUso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_FILES['arquivo_json']) && isset($_FILES['arquivo_json']['tmp_name']) && $_FILES['arquivo_json']['tmp_name'] !== '') {
            if ((int)$_FILES['arquivo_json']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Erro no upload do arquivo JSON.');
            }

            salvarCacheJson($_FILES['arquivo_json']['tmp_name'], $arquivoCacheJson);
            $mensagensOk[] = 'Arquivo JSON salvo em cache com sucesso.';
        }

        $dataConsulta = isset($_POST['data_consulta']) ? trim((string)$_POST['data_consulta']) : '';
        $idt = isset($_POST['idt']) ? trim((string)$_POST['idt']) : '';

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

        $jsonEmUso = lerConteudoArquivo($arquivoCacheJson);

        if (trim($jsonEmUso) === '') {
            $mensagensErro[] = 'Nenhum JSON em cache. Informe um arquivo JSON para consultar.';
        }

        if (count($mensagensErro) === 0) {
            $servidores = lerJsonServidoresDoConteudo($jsonEmUso);
            $consultado = true;

            $tabelaLogs = 'mysoft_logs.autonfe_' . $dataConsulta;

            foreach ($servidores as $item) {
                $nomeServidor = isset($item['nome']) ? trim((string)$item['nome']) : '';
                $endereco     = isset($item['endereco']) ? trim((string)$item['endereco']) : '';
                $porta        = isset($item['porta']) ? (int)$item['porta'] : 3306;
                $database     = isset($item['database']) ? trim((string)$item['database']) : '';

                $localConexao = $endereco . ':' . $porta . '/' . $database;

                if ($endereco === '' || $database === '') {
                    $mensagensErro[] = 'Configuração incompleta em ' . $localConexao;
                    continue;
                }

                try {
                    $conn = @new mysqli($endereco, $usuarioMysql, $senhaMysql, $database, $porta);

                    if (!$conn || $conn->connect_errno) {
                        $textoErro = $conn ? $conn->connect_error : 'falha ao criar conexão';
                        $mensagensErro[] = 'Erro em ' . $localConexao . ' - ' . $textoErro;
                        if ($conn) {
                            $conn->close();
                        }
                        continue;
                    }
                } catch (Throwable $e) {
                    $mensagensErro[] = 'Erro em ' . $localConexao . ' - ' . $e->getMessage();
                    continue;
                }

                @$conn->set_charset('utf8mb4');

                $sql = 'SELECT * ' .
                       'FROM ' . $tabelaLogs . ' ' .
                       'WHERE idt = ? ' .
                       'ORDER BY hora DESC, ms DESC';

                $stmt = $conn->prepare($sql);

                if (!$stmt) {
                    $mensagensErro[] = 'Erro ao preparar consulta em ' . $localConexao . ' - ' . $conn->error;
                    $conn->close();
                    continue;
                }

                $idtInt = (int)$idt;
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
} else {
    $jsonEmUso = lerConteudoArquivo($arquivoCacheJson);
}
$versaoCssBase = @filemtime(__DIR__ . '/consultar_notas_servidores.css');
$versaoCssLocal = @filemtime(__DIR__ . '/consultar_idt.css');
$totalResultados = count($resultados);
$totalErros = count($mensagensErro);
$cacheExiste = file_exists($arquivoCacheJson);
$nomeCacheAtual = $cacheExiste ? basename($arquivoCacheJson) : 'nenhum arquivo salvo';
$servidoresComRetorno = [];
foreach ($resultados as $linhaResultado) {
    $chaveServidor = (isset($linhaResultado['_servidor']) ? (string) $linhaResultado['_servidor'] : '') . ':' . (isset($linhaResultado['_porta']) ? (string) $linhaResultado['_porta'] : '') . '/' . (isset($linhaResultado['_database']) ? (string) $linhaResultado['_database'] : '');
    if ($chaveServidor !== '') {
        $servidoresComRetorno[$chaveServidor] = true;
    }
}
$totalServidoresComRetorno = count($servidoresComRetorno);
$totalColunasResultado = count($colunasResultado);
$statusPainel = 'Informe a data e o IDT para consultar os logs AutoNFe usando o JSON em cache.';
if ($consultado) {
    if ($totalResultados > 0) {
        $statusPainel = 'Consulta concluída com ' . $totalResultados . ' registro(s) encontrado(s).';
    } elseif ($totalErros === 0) {
        $statusPainel = 'Consulta concluída. Nenhum registro encontrado para a data e o IDT informados.';
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
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <div class="title-row">
            <div class="title-block">
                <h1>Consulta Logs AutoNFe</h1>
                <p>Visual alinhado com os outros painéis, mantendo a mesma lógica de busca por data, IDT e servidores em cache.</p>
            </div>
            <div class="tag-row">
                <span class="chip"><span>Cache</span> <strong><?php echo h($nomeCacheAtual); ?></strong></span>
                <span class="chip"><span>Data</span> <strong><?php echo h($dataConsulta !== '' ? $dataConsulta : '---'); ?></strong></span>
                <span class="chip"><span>IDT</span> <strong><?php echo h($idt !== '' ? $idt : '---'); ?></strong></span>
                <span class="chip live"><span>Registros</span> <strong><?php echo (int) $totalResultados; ?></strong></span>
            </div>
        </div>

        <div class="toolbar-grid">
            <section class="panel panel-form">
                <form class="form-consulta-idt" method="post" enctype="multipart/form-data">
                    <div class="field">
                        <label for="data_consulta">Data</label>
                        <input type="text" name="data_consulta" id="data_consulta" value="<?php echo h($dataConsulta); ?>" placeholder="yyyymmdd" autocomplete="off">
                    </div>

                    <div class="field">
                        <label for="idt">IDT</label>
                        <input type="text" name="idt" id="idt" value="<?php echo h($idt); ?>" autocomplete="off">
                    </div>

                    <div class="field">
                        <label for="arquivo_json">Arquivo JSON</label>
                        <input type="file" name="arquivo_json" id="arquivo_json" accept=".json,application/json">
                    </div>

                    <div>
                        <button class="botao" type="submit">Consultar</button>
                    </div>
                </form>
            </section>

            <section class="telemetry">
                <div class="panel metric">
                    <div class="label">Cache ativo</div>
                    <div class="value" style="font-size: 20px;"><?php echo $cacheExiste ? 'SIM' : 'NÃO'; ?></div>
                    <div class="hint"><?php echo h($nomeCacheAtual); ?></div>
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
                    <div class="label">Colunas</div>
                    <div class="value"><?php echo (int) $totalColunasResultado; ?></div>
                    <div class="hint">Campos vindos da tabela de logs</div>
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
            <span class="cache-pill <?php echo $cacheExiste ? 'cache-local' : 'cache-empty'; ?>"><?php echo $cacheExiste ? 'Cache local disponível' : 'Sem cache salvo'; ?></span>
        </div>
    </header>

    <main class="content-shell">
        <?php if (count($mensagensOk) > 0 || count($mensagensErro) > 0 || !$consultado): ?>
        <section class="panel message-panel">
            <div class="message-stack">
                <?php if (!$consultado): ?>
                    <div class="message-line info">Informe a data, o IDT e, se quiser, envie um novo JSON antes de consultar.</div>
                <?php endif; ?>

                <?php foreach ($mensagensOk as $msg): ?>
                    <div class="message-line ok"><?php echo h($msg); ?></div>
                <?php endforeach; ?>

                <?php foreach ($mensagensErro as $erro): ?>
                    <div class="message-line erro"><?php echo h($erro); ?></div>
                <?php endforeach; ?>

                <?php if ($consultado && count($resultados) === 0 && count($mensagensErro) === 0): ?>
                    <div class="message-line info">Nenhum registro encontrado para a data e o IDT informados.</div>
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
                    <span class="counter-pill">Servidores <strong><?php echo (int) $totalServidoresComRetorno; ?></strong></span>
                    <span class="counter-pill">Registros <strong><?php echo (int) $totalResultados; ?></strong></span>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Servidor</th>
                            <th>Porta</th>
                            <th>Database</th>
                            <?php foreach ($colunasResultado as $coluna): ?>
                                <th><?php echo h($coluna); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($resultados) > 0): ?>
                            <?php foreach ($resultados as $linha): ?>
                                <tr>
                                    <td><?php echo h($linha['_servidor']); ?></td>
                                    <td><?php echo h($linha['_porta']); ?></td>
                                    <td><?php echo h($linha['_database']); ?></td>
                                    <?php foreach ($colunasResultado as $coluna): ?>
                                        <td><?php echo h(isset($linha[$coluna]) ? $linha[$coluna] : ''); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="empty-state" style="<?php echo count($resultados) > 0 ? 'display:none;' : ''; ?>">
                <?php echo h($consultado ? 'Nenhum registro encontrado para a consulta atual.' : 'Preencha os dados e clique em Consultar para carregar os logs.'); ?>
            </div>
        </section>
    </main>
</div>
</body>
</html>
