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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Consulta Logs AutoNFe</title>
<style>
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 14px;
        margin: 20px;
        background: #f5f5f5;
        color: #222;
    }
    h1 {
        margin: 0 0 15px 0;
        font-size: 22px;
    }
    .box {
        background: #fff;
        border: 1px solid #d9d9d9;
        border-radius: 6px;
        padding: 12px;
        margin-bottom: 15px;
    }
    .linha {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    label {
        font-weight: bold;
    }
    input[type="text"], input[type="file"] {
        padding: 8px 10px;
        border: 1px solid #bbb;
        border-radius: 4px;
        font-size: 14px;
        background: #fff;
    }
    #data_consulta {
        width: 130px;
    }
    #idt {
        width: 180px;
    }
    button {
        padding: 8px 14px;
        border: 1px solid #999;
        border-radius: 4px;
        background: #efefef;
        cursor: pointer;
        font-size: 14px;
    }
    button:hover {
        background: #e4e4e4;
    }
    .erro {
        color: #b30000;
        margin: 4px 0;
    }
    .ok {
        color: #0b6b0b;
        font-weight: bold;
        margin: 4px 0;
    }
    .info {
        color: #333;
        margin: 4px 0;
    }
    .tabela-wrap {
        overflow-x: auto;
        background: #fff;
        border: 1px solid #d9d9d9;
        border-radius: 6px;
    }
    table {
        border-collapse: collapse;
        width: 100%;
        min-width: 1200px;
    }
    th, td {
        border: 1px solid #dcdcdc;
        padding: 6px 8px;
        text-align: left;
        vertical-align: top;
        white-space: nowrap;
    }
    th {
        background: #f0f0f0;
    }
    tr:nth-child(even) td {
        background: #fafafa;
    }
</style>
</head>
<body>

<h1>Consulta Logs AutoNFe</h1>

<div class="box">
    <form method="post" enctype="multipart/form-data">
        <div class="linha">
            <label for="data_consulta">Data:</label>
            <input type="text" name="data_consulta" id="data_consulta" value="<?php echo h($dataConsulta); ?>" placeholder="yyyymmdd" autocomplete="off">

            <label for="idt">IDT:</label>
            <input type="text" name="idt" id="idt" value="<?php echo h($idt); ?>" autocomplete="off">

            <label for="arquivo_json">Arquivo JSON:</label>
            <input type="file" name="arquivo_json" id="arquivo_json" accept=".json,application/json">

            <button type="submit">Consultar</button>
        </div>
    </form>

    <div class="info">
        <?php if (file_exists($arquivoCacheJson)): ?>
            Cache atual: <strong><?php echo h(basename($arquivoCacheJson)); ?></strong>
        <?php else: ?>
            Cache atual: <strong>nenhum arquivo salvo</strong>
        <?php endif; ?>
    </div>
</div>

<?php if (count($mensagensOk) > 0): ?>
<div class="box">
    <?php foreach ($mensagensOk as $msg): ?>
        <div class="ok"><?php echo h($msg); ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (count($mensagensErro) > 0): ?>
<div class="box">
    <?php foreach ($mensagensErro as $erro): ?>
        <div class="erro"><?php echo h($erro); ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($consultado): ?>
<div class="box">
    <?php if (count($resultados) > 0): ?>
        <div class="ok">Foram encontrados <?php echo count($resultados); ?> registro(s).</div>
    <?php elseif (count($mensagensErro) === 0): ?>
        <div>Nenhum registro encontrado para a data e IDT informados.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (count($resultados) > 0): ?>
<div class="tabela-wrap">
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
        </tbody>
    </table>
</div>
<?php endif; ?>

</body>
</html>
