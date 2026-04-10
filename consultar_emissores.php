<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');
@ini_set('max_execution_time', '0');
@set_time_limit(0);
@ini_set('default_socket_timeout', '600');
mysqli_report(MYSQLI_REPORT_OFF);

$hostMysqlCentral = '10.8.0.6';
$portaMysqlCentral = 3306;
$bancoMysqlCentral = 'mysoft';
$usuarioMysql = 'mysoftweb';
$senhaMysql   = 'g3108f88';

function h($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function tituloColuna($coluna)
{
    $titulos = array(
        'serie_nota_fiscal' => 'serie',
        'modelo_nota_fiscal' => 'modelo',
        'validar_nfe' => 'validar',
        'cancelar_nfe' => 'cancelar',
        'inutilizar_nfe' => 'inutilizar',
        'consultar_nfe' => 'consultar',
        'offline_nfe' => 'offline'
    );

    if (isset($titulos[$coluna])) {
        return $titulos[$coluna];
    }

    return $coluna;
}

function classeColunaTabela($coluna)
{
    $classes = array(
        'nome' => 'coluna-nome',
        'endereco' => 'coluna-endereco',
        'porta' => 'coluna-porta',
        'database' => 'coluna-database',
        'idt' => 'coluna-idt',
        'webservice' => 'coluna-webservice',
        'serie_nota_fiscal' => 'coluna-serie',
        'modelo_nota_fiscal' => 'coluna-modelo',
        'empresa' => 'coluna-empresa',
        'vencimento' => 'coluna-vencimento',
        'origem' => 'coluna-origem',
        'validar_nfe' => 'coluna-validar',
        'cancelar_nfe' => 'coluna-cancelar',
        'inutilizar_nfe' => 'coluna-inutilizar',
        'consultar_nfe' => 'coluna-consultar',
        'offline_nfe' => 'coluna-offline'
    );

    if (isset($classes[$coluna])) {
        return $classes[$coluna];
    }

    return '';
}

function formatarOrigem($origem)
{
    $origem = trim((string)$origem);

    if ($origem === '') {
        return '';
    }

    if (ctype_digit($origem)) {
        return str_pad($origem, 3, '0', STR_PAD_LEFT);
    }

    return $origem;
}

function formatarValorColuna($coluna, $valor)
{
    if ($coluna === 'origem') {
        return formatarOrigem($valor);
    }

    return $valor;
}

function responderJson($dados, $status = 200)
{
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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

function bindParamDinamico($stmt, $tipos, $valores)
{
    $parametros = array();
    $parametros[] = $tipos;

    foreach ($valores as $indice => $valor) {
        $valores[$indice] = $valor;
        $parametros[] = &$valores[$indice];
    }

    return call_user_func_array(array($stmt, 'bind_param'), $parametros);
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

    $sql = 'select nome, grupo, endereco, porta, `database`, endereco_seq, porta_seq '
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

function consultarGrupo($grupoSelecionado, $gruposDisponiveis, $hostMysqlCentral, $portaMysqlCentral, $bancoMysqlCentral, $usuarioMysql, $senhaMysql)
{
    $mensagensErro = array();
    $resultados = array();
    $origensDisponiveis = array();
    $modelosDisponiveis = array();

    if ($grupoSelecionado === '') {
        $mensagensErro[] = 'Selecione um grupo para consultar.';
    } elseif (!in_array($grupoSelecionado, $gruposDisponiveis, true)) {
        $mensagensErro[] = 'Grupo inválido.';
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
        foreach ($servidores as $item) {
            @set_time_limit(0);

            $nome        = isset($item['nome']) ? trim((string)$item['nome']) : '';
            $grupo       = isset($item['grupo']) ? trim((string)$item['grupo']) : '';
            $endereco    = isset($item['endereco']) ? trim((string)$item['endereco']) : '';
            $porta       = isset($item['porta']) ? (int)$item['porta'] : 3306;
            $database    = isset($item['database']) ? trim((string)$item['database']) : '';
            $enderecoSeq = isset($item['endereco_seq']) ? trim((string)$item['endereco_seq']) : '';
            $portaSeq    = isset($item['porta_seq']) ? (int)$item['porta_seq'] : 3306;

            $localConexaoSeq = $enderecoSeq . ':' . $portaSeq . '/mysoft_emissor';
            $localConexaoCad = $endereco . ':' . $porta . '/' . $database;

            if ($endereco === '' || $database === '' || $enderecoSeq === '') {
                $mensagensErro[] = 'Configuração incompleta em ' . $localConexaoCad;
                continue;
            }

            $connSeq = conectarMysql($enderecoSeq, $usuarioMysql, $senhaMysql, 'mysoft_emissor', $portaSeq);
            if (!$connSeq instanceof mysqli) {
                $mensagensErro[] = 'Erro em ' . $localConexaoSeq . ' - falha ao criar conexão';
                continue;
            }

            $sqlSeq = 'select idt from seq_emissor where basedados = ?';
            $stmtSeq = $connSeq->prepare($sqlSeq);

            if (!$stmtSeq) {
                $mensagensErro[] = 'Erro ao preparar consulta em ' . $localConexaoSeq . ' - ' . $connSeq->error;
                $connSeq->close();
                continue;
            }

            if (!bindParamDinamico($stmtSeq, 's', array($database))) {
                $mensagensErro[] = 'Erro ao vincular parâmetros em ' . $localConexaoSeq . ' - ' . $stmtSeq->error;
                $stmtSeq->close();
                $connSeq->close();
                continue;
            }

            if (!$stmtSeq->execute()) {
                $mensagensErro[] = 'Erro ao executar consulta em ' . $localConexaoSeq . ' - ' . $stmtSeq->error;
                $stmtSeq->close();
                $connSeq->close();
                continue;
            }

            $linhasSeq = buscarResultadosStatement($stmtSeq);
            $stmtSeq->close();
            $connSeq->close();

            if (count($linhasSeq) === 0) {
                continue;
            }

            $idtsSeq = array();
            foreach ($linhasSeq as $linhaSeq) {
                if (isset($linhaSeq['idt']) && ctype_digit((string)$linhaSeq['idt'])) {
                    $idtsSeq[] = (int)$linhaSeq['idt'];
                }
            }

            $idtsSeq = array_values(array_unique($idtsSeq));
            if (count($idtsSeq) === 0) {
                continue;
            }

            $connCad = conectarMysql($endereco, $usuarioMysql, $senhaMysql, $database, $porta);
            if (!$connCad instanceof mysqli) {
                $mensagensErro[] = 'Erro em ' . $localConexaoCad . ' - falha ao criar conexão';
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($idtsSeq), '?'));
            $sqlCad = 'select idt,webservice,serie_nota_fiscal,modelo_nota_fiscal,empresa,vencimento,origem,validar_nfe,cancelar_nfe,inutilizar_nfe,consultar_nfe,offline_nfe '
                    . 'from cad_emissor '
                    . 'where ativo = "S" '
                    . 'and idt in (' . $placeholders . ')';

            $stmtCad = $connCad->prepare($sqlCad);
            if (!$stmtCad) {
                $mensagensErro[] = 'Erro ao preparar consulta em ' . $localConexaoCad . ' - ' . $connCad->error;
                $connCad->close();
                continue;
            }

            $tipos = str_repeat('i', count($idtsSeq));
            if (!bindParamDinamico($stmtCad, $tipos, $idtsSeq)) {
                $mensagensErro[] = 'Erro ao vincular parâmetros em ' . $localConexaoCad . ' - ' . $stmtCad->error;
                $stmtCad->close();
                $connCad->close();
                continue;
            }

            if (!$stmtCad->execute()) {
                $mensagensErro[] = 'Erro ao executar consulta em ' . $localConexaoCad . ' - ' . $stmtCad->error;
                $stmtCad->close();
                $connCad->close();
                continue;
            }

            $linhasCad = buscarResultadosStatement($stmtCad);
            $stmtCad->close();
            $connCad->close();

            foreach ($linhasCad as $linha) {
                $linha['_grupo'] = $grupo;
                $linha['_nome'] = $nome;
                $linha['_endereco'] = $endereco;
                $linha['_porta'] = $porta;
                $linha['_database'] = $database;
                $resultados[] = $linha;

                if (isset($linha['origem']) && trim((string)$linha['origem']) !== '') {
                    $origensDisponiveis[] = formatarOrigem($linha['origem']);
                }

                if (isset($linha['modelo_nota_fiscal']) && trim((string)$linha['modelo_nota_fiscal']) !== '') {
                    $modelosDisponiveis[] = (string)$linha['modelo_nota_fiscal'];
                }
            }
        }

        $origensDisponiveis = array_values(array_unique($origensDisponiveis));
        $modelosDisponiveis = array_values(array_unique($modelosDisponiveis));
        sort($origensDisponiveis);
        sort($modelosDisponiveis);

        usort($resultados, function ($a, $b) {
            $origemA = isset($a['origem']) ? formatarOrigem($a['origem']) : '';
            $origemB = isset($b['origem']) ? formatarOrigem($b['origem']) : '';

            $cmp = strcmp($origemA, $origemB);
            if ($cmp !== 0) {
                return $cmp;
            }

            $idtA = isset($a['idt']) ? (int)$a['idt'] : 0;
            $idtB = isset($b['idt']) ? (int)$b['idt'] : 0;
            if ($idtA !== $idtB) {
                return ($idtA < $idtB) ? -1 : 1;
            }

            $dbA = isset($a['_database']) ? (string)$a['_database'] : '';
            $dbB = isset($b['_database']) ? (string)$b['_database'] : '';
            $cmp = strcmp($dbA, $dbB);
            if ($cmp !== 0) {
                return $cmp;
            }

            $endA = isset($a['_endereco']) ? (string)$a['_endereco'] : '';
            $endB = isset($b['_endereco']) ? (string)$b['_endereco'] : '';
            return strcmp($endA, $endB);
        });
    }

    $totalResultados = count($resultados);
    $totalOrigens = count($origensDisponiveis);
    $totalModelos = count($modelosDisponiveis);
    $totalErros = count($mensagensErro);

    $statusPainel = 'Selecione o grupo e clique em Consultar para carregar os emissores pela tabela central de servidores.';
    if ($grupoSelecionado !== '') {
        if ($totalResultados > 0) {
            $statusPainel = 'Consulta concluída para o grupo ' . $grupoSelecionado . ' com ' . $totalResultados . ' registro(s) encontrados.';
        } elseif ($totalErros === 0) {
            $statusPainel = 'Consulta concluída para o grupo ' . $grupoSelecionado . '. Nenhum registro encontrado.';
        } else {
            $statusPainel = 'Consulta concluída com alertas. Verifique as mensagens exibidas abaixo.';
        }
    }

    return array(
        'ok' => count($mensagensErro) === 0,
        'mensagensErro' => $mensagensErro,
        'resultados' => $resultados,
        'origensDisponiveis' => $origensDisponiveis,
        'modelosDisponiveis' => $modelosDisponiveis,
        'grupoSelecionado' => $grupoSelecionado,
        'statusPainel' => $statusPainel,
        'totalResultados' => $totalResultados,
        'totalOrigens' => $totalOrigens,
        'totalModelos' => $totalModelos,
        'totalErros' => $totalErros
    );
}

$colunasResultado = array(
    'idt',
    'webservice',
    'serie_nota_fiscal',
    'modelo_nota_fiscal',
    'empresa',
    'vencimento',
    'origem',
    'validar_nfe',
    'cancelar_nfe',
    'inutilizar_nfe',
    'consultar_nfe',
    'offline_nfe'
);

$classesColunasFixas = array(
    'nome' => classeColunaTabela('nome'),
    'endereco' => classeColunaTabela('endereco'),
    'porta' => classeColunaTabela('porta'),
    'database' => classeColunaTabela('database')
);

$classesColunasResultado = array();
foreach ($colunasResultado as $colunaResultado) {
    $classesColunasResultado[$colunaResultado] = classeColunaTabela($colunaResultado);
}

$grupoSelecionado = isset($_GET['grupo']) ? trim((string)$_GET['grupo']) : '';
$origemFiltroInicial = isset($_GET['origem']) ? formatarOrigem((string)$_GET['origem']) : '';
$consultaAutomatica = $grupoSelecionado !== '';
$gruposDisponiveis = array();
$mensagensErro = array();

if (isset($_GET['ajax_grupos']) && (string)$_GET['ajax_grupos'] === '1') {
    list($gruposAjax, $erroGruposAjax) = carregarGrupos($hostMysqlCentral, $portaMysqlCentral, $bancoMysqlCentral, $usuarioMysql, $senhaMysql);

    if ($erroGruposAjax !== '') {
        responderJson(array(
            'ok' => false,
            'mensagensErro' => array($erroGruposAjax),
            'grupos' => array()
        ), 500);
    }

    responderJson(array(
        'ok' => true,
        'mensagensErro' => array(),
        'grupos' => array_values($gruposAjax)
    ), 200);
}

if (isset($_GET['ajax_consultar']) && (string)$_GET['ajax_consultar'] === '1') {
    list($gruposConsulta, $erroGruposConsulta) = carregarGrupos($hostMysqlCentral, $portaMysqlCentral, $bancoMysqlCentral, $usuarioMysql, $senhaMysql);

    if ($erroGruposConsulta !== '') {
        responderJson(array(
            'ok' => false,
            'mensagensErro' => array($erroGruposConsulta),
            'resultados' => array(),
            'origensDisponiveis' => array(),
            'modelosDisponiveis' => array(),
            'grupoSelecionado' => $grupoSelecionado,
            'statusPainel' => 'Erro ao carregar grupos.',
            'totalResultados' => 0,
            'totalOrigens' => 0,
            'totalModelos' => 0,
            'totalErros' => 1
        ), 500);
    }

    $payload = consultarGrupo($grupoSelecionado, $gruposConsulta, $hostMysqlCentral, $portaMysqlCentral, $bancoMysqlCentral, $usuarioMysql, $senhaMysql);
    responderJson($payload, 200);
}

$versaoCssBase = @filemtime(__DIR__ . '/consultar_notas_servidores.css');
$versaoCssLocal = @filemtime(__DIR__ . '/consultar_emissores.css');
$totalGrupos = 0;
$statusPainel = 'Selecione o grupo e clique em Consultar para carregar os emissores pela tabela central de servidores.';
if ($origemFiltroInicial !== '') {
    $statusPainel .= ' Filtro inicial de origem: ' . $origemFiltroInicial . '.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Consulta de Emissores</title>
<link rel="stylesheet" href="consultar_notas_servidores.css<?php echo $versaoCssBase ? '?v=' . $versaoCssBase : ''; ?>">
<link rel="stylesheet" href="consultar_emissores.css<?php echo $versaoCssLocal ? '?v=' . $versaoCssLocal : ''; ?>">
<style>
.overlay-consulta-dados {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(233, 239, 247, 0.84);
    backdrop-filter: blur(6px);
}
.overlay-consulta-dados[hidden] {
    display: none !important;
}
.overlay-consulta-box {
    width: min(560px, 100%);
    padding: 28px 26px;
    border-radius: 22px;
    border: 1px solid rgba(47, 125, 225, 0.24);
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(245, 249, 255, 0.98));
    box-shadow: 0 18px 42px rgba(71, 95, 128, 0.14);
    text-align: center;
}
.overlay-consulta-titulo {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #2b4058;
}
.overlay-consulta-texto {
    margin-top: 10px;
    font-size: 22px;
    font-weight: 800;
    color: #2f9d57;
}
.overlay-consulta-subtexto {
    margin-top: 10px;
    color: #5f738c;
    font-size: 13px;
}
.overlay-consulta-spinner {
    width: 54px;
    height: 54px;
    margin: 0 auto 16px auto;
    border-radius: 50%;
    border: 4px solid rgba(47, 125, 225, 0.18);
    border-top-color: rgba(47, 125, 225, 0.92);
    animation: giro-consulta-dados 0.9s linear infinite;
}
@keyframes giro-consulta-dados {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
</head>
<body>
<div id="overlayConsultaDados" class="overlay-consulta-dados" hidden>
    <div class="overlay-consulta-box">
        <div class="overlay-consulta-spinner"></div>
        <div class="overlay-consulta-titulo">Carregando</div>
        <div id="overlayConsultaDadosTexto" class="overlay-consulta-texto">Aguarde Consultado...</div>
        <div id="overlayConsultaDadosSubtexto" class="overlay-consulta-subtexto">Aguarde enquanto as informações são carregadas.</div>
    </div>
</div>

<div class="app-shell">
    <header class="topbar">
        <div class="title-row">
            <div class="title-block">
                <h1>Consulta de Emissores</h1>
                <p>Mesma lógica da consulta anterior, agora lendo os servidores diretamente da tabela mysql central e filtrando antes pelo grupo.</p>
            </div>
            <div class="tag-row">
                <span class="chip"><span>Origem</span> <strong>mysql / servidores</strong></span>
                <span class="chip"><span>Grupo</span> <strong id="tagGrupoSelecionado"><?php echo h($grupoSelecionado !== '' ? $grupoSelecionado : '---'); ?></strong></span>
                <span class="chip"><span>Grupos</span> <strong id="tagTotalGrupos"><?php echo (int)$totalGrupos; ?></strong></span>
                <span class="chip live"><span>Registros</span> <strong id="tagTotalResultados">0</strong></span>
            </div>
        </div>

        <div class="toolbar-grid">
            <section class="panel panel-form">
                <form class="form-upload-emissores" method="get" action="">
                    <div class="field">
                        <label for="grupo">Grupo</label>
                        <select name="grupo" id="grupo">
                            <option value="">Selecione</option>
                            <?php if ($grupoSelecionado !== ''): ?>
                                <option value="<?php echo h($grupoSelecionado); ?>" selected><?php echo h($grupoSelecionado); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <button class="botao" type="submit">Consultar</button>
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
                    <div class="value" id="infoTotalResultados">0</div>
                    <div class="hint">Total retornado na última consulta</div>
                </div>
                <div class="panel metric warn">
                    <div class="label">Origens</div>
                    <div class="value" id="infoTotalOrigens">0</div>
                    <div class="hint">Valores únicos disponíveis no filtro</div>
                </div>
                <div class="panel metric">
                    <div class="label">Modelos</div>
                    <div class="value" id="infoTotalModelos">0</div>
                    <div class="hint">Modelos únicos encontrados</div>
                </div>
                <div class="panel metric danger">
                    <div class="label">Alertas</div>
                    <div class="value" id="infoTotalErros"><?php echo (int)count($mensagensErro); ?></div>
                    <div class="hint">Mensagens de erro durante a leitura</div>
                </div>
            </section>
        </div>

        <div class="status-bar">
            <span class="status-dot"></span>
            <div class="status-text" id="statusPainel"><?php echo h($statusPainel); ?></div>
            <span class="cache-pill cache-local">Leitura direta do MySQL</span>
        </div>
    </header>

    <main class="content-shell">
        <section class="panel message-panel">
            <div class="message-stack" id="messageStack">
                <?php if (count($mensagensErro) > 0): ?>
                    <?php foreach ($mensagensErro as $erro): ?>
                        <div class="message-line erro"><?php echo h($erro); ?></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="message-line info" id="mensagemInicial">Selecione um grupo e clique em Consultar para iniciar a leitura dos emissores.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel panel-form-local" id="painelFiltrosLocais" style="display:none;">
            <div class="local-filter-row">
                <div class="field">
                    <label for="filtro_origem">Origem</label>
                    <select id="filtro_origem">
                        <option value="">Todas</option>
                    </select>
                </div>

                <div class="field">
                    <label for="filtro_modelo">Modelo</label>
                    <select id="filtro_modelo">
                        <option value="">Todos</option>
                    </select>
                </div>

                <div>
                    <button class="botao" type="button" onclick="limparFiltrosTabela()">Limpar</button>
                </div>
            </div>
        </section>

        <section class="surface">
            <div class="surface-head">
                <div>
                    <div class="surface-title">Resultado da consulta</div>
                    <div class="surface-subtitle">Agrupado por origem. Quando o mesmo IDT aparece mais de uma vez, as linhas recebem a mesma cor para facilitar a identificação.</div>
                </div>
                <div class="toolbar-inline">
                    <span class="counter-pill">Grupo <strong id="toolbarGrupo"><?php echo h($grupoSelecionado !== '' ? $grupoSelecionado : '---'); ?></strong></span>
                    <span class="counter-pill">Total <strong id="toolbarTotal">0</strong></span>
                </div>
            </div>

            <div class="table-wrap">
                <table id="tabela_resultados">
                    <thead>
                        <tr>
                            <th class="<?php echo h($classesColunasFixas['nome']); ?>">Nome</th>
                            <th class="<?php echo h($classesColunasFixas['endereco']); ?>">Endereço</th>
                            <th class="<?php echo h($classesColunasFixas['porta']); ?>">Porta</th>
                            <th class="<?php echo h($classesColunasFixas['database']); ?>">Database</th>
                            <?php foreach ($colunasResultado as $coluna): ?>
                                <th class="<?php echo h(isset($classesColunasResultado[$coluna]) ? $classesColunasResultado[$coluna] : ''); ?>"><?php echo h(tituloColuna($coluna)); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody id="resultadoBody"></tbody>
                </table>
            </div>

            <div class="empty-state" id="mensagemVazia">Selecione o grupo e clique em Consultar para carregar os emissores.</div>
        </section>
    </main>
</div>

<script>
var COLUNAS_RESULTADO = <?php echo json_encode($colunasResultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var CLASSES_COLUNAS_RESULTADO = <?php echo json_encode($classesColunasResultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var ORIGEM_FILTRO_INICIAL = <?php echo json_encode($origemFiltroInicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var GRUPO_INICIAL = <?php echo json_encode($grupoSelecionado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var CONSULTA_AUTOMATICA = <?php echo $consultaAutomatica ? 'true' : 'false'; ?>;
var ESTADO_EMISSORES = {
    resultados: [],
    resultadosFiltrados: [],
    grupoSelecionado: GRUPO_INICIAL
};

function escaparHtml(texto) {
    return String(texto == null ? '' : texto)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatarSerieHtml(valor) {
    var texto = String(valor == null ? '' : valor);
    if (texto === '') {
        return '';
    }

    var partes = texto.split(',');
    for (var i = 0; i < partes.length; i++) {
        partes[i] = escaparHtml(partes[i]);
    }

    return partes.join(',<wbr>');
}

function mostrarOverlayConsultaDados(texto, subtexto) {
    var overlay = document.getElementById('overlayConsultaDados');
    if (!overlay) { return; }
    document.getElementById('overlayConsultaDadosTexto').textContent = texto || 'Aguarde Consultado...';
    document.getElementById('overlayConsultaDadosSubtexto').textContent = subtexto || 'Aguarde enquanto as informações são carregadas.';
    overlay.hidden = false;
}

function ocultarOverlayConsultaDados() {
    var overlay = document.getElementById('overlayConsultaDados');
    if (!overlay) { return; }
    overlay.hidden = true;
}

function definirMensagemPainel(texto) {
    var el = document.getElementById('statusPainel');
    if (el) {
        el.textContent = texto || '';
    }
}

function renderizarMensagens(erros, infos) {
    var box = document.getElementById('messageStack');
    if (!box) { return; }
    var html = '';
    erros = Array.isArray(erros) ? erros : [];
    infos = Array.isArray(infos) ? infos : [];

    for (var i = 0; i < infos.length; i++) {
        html += '<div class="message-line info">' + escaparHtml(infos[i]) + '</div>';
    }
    for (var j = 0; j < erros.length; j++) {
        html += '<div class="message-line erro">' + escaparHtml(erros[j]) + '</div>';
    }
    if (!html) {
        html = '<div class="message-line info">Consulta concluída.</div>';
    }
    box.innerHTML = html;
}

function preencherSelect(selectId, valores, valorInicial, textoTodos) {
    var select = document.getElementById(selectId);
    if (!select) { return; }

    var html = '<option value="">' + escaparHtml(textoTodos) + '</option>';
    valores = Array.isArray(valores) ? valores : [];
    for (var i = 0; i < valores.length; i++) {
        var valor = String(valores[i] || '');
        html += '<option value="' + escaparHtml(valor) + '"' + (valorInicial === valor ? ' selected' : '') + '>' + escaparHtml(valor) + '</option>';
    }
    select.innerHTML = html;
}

function carregarGruposAssincrono() {
    return fetch('consultar_emissores.php?ajax_grupos=1', {
        method: 'GET',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
        .then(function(resposta) {
            if (!resposta.ok) {
                throw new Error('Falha HTTP ao carregar grupos');
            }
            return resposta.json();
        })
        .then(function(payload) {
            if (!payload || payload.ok !== true || !Array.isArray(payload.grupos)) {
                throw new Error(payload && payload.mensagensErro && payload.mensagensErro.length ? String(payload.mensagensErro[0]) : 'Falha ao carregar grupos.');
            }
            preencherSelect('grupo', payload.grupos || [], ESTADO_EMISSORES.grupoSelecionado || '', 'Selecione');
            var tag = document.getElementById('tagTotalGrupos');
            if (tag) {
                tag.textContent = String((payload.grupos || []).length);
            }
            return payload;
        })
        .catch(function(erro) {
            var tag = document.getElementById('tagTotalGrupos');
            if (tag) {
                tag.textContent = '0';
            }
            renderizarMensagens([erro && erro.message ? erro.message : 'Erro ao carregar grupos.'], []);
            throw erro;
        });
}

function aplicarFiltrosTabela() {
    var filtroOrigem = document.getElementById('filtro_origem').value;
    var filtroModelo = document.getElementById('filtro_modelo').value;

    ESTADO_EMISSORES.resultadosFiltrados = ESTADO_EMISSORES.resultados.filter(function(item) {
        var origem = String(item.origem_formatada || '');
        var modelo = String(item.modelo_nota_fiscal || '');
        if (filtroOrigem !== '' && origem !== filtroOrigem) {
            return false;
        }
        if (filtroModelo !== '' && modelo !== filtroModelo) {
            return false;
        }
        return true;
    });

    renderizarTabela();
}

function limparFiltrosTabela() {
    document.getElementById('filtro_origem').value = '';
    document.getElementById('filtro_modelo').value = '';
    aplicarFiltrosTabela();
}

function colorirIdtsIguais() {
    var linhas = document.querySelectorAll('#tabela_resultados tbody tr');
    var contagem = {};
    var cores = ['#fff4cc','#dff3ff','#e7f9e7','#f6e3ff','#ffe6d9','#e6fff7','#fbe8d3','#e8ecff'];
    var mapaCores = {};
    var indiceCor = 0;

    linhas.forEach(function(linha) {
        if (linha.classList.contains('linha-grupo')) {
            return;
        }
        var idt = linha.getAttribute('data-idt') || '';
        if (idt === '') {
            return;
        }
        if (!contagem[idt]) {
            contagem[idt] = 0;
        }
        contagem[idt]++;
    });

    linhas.forEach(function(linha) {
        if (linha.classList.contains('linha-grupo')) {
            return;
        }
        var idt = linha.getAttribute('data-idt') || '';
        if (idt === '' || !contagem[idt] || contagem[idt] < 2) {
            return;
        }
        if (!mapaCores[idt]) {
            mapaCores[idt] = cores[indiceCor % cores.length];
            indiceCor++;
        }
        linha.classList.add('linha-idt-destacada');
        Array.prototype.forEach.call(linha.cells, function(celula) {
            celula.style.backgroundColor = mapaCores[idt];
        });
    });
}

function renderizarTabela() {
    var corpo = document.getElementById('resultadoBody');
    var mensagemVazia = document.getElementById('mensagemVazia');
    if (!corpo || !mensagemVazia) { return; }

    var linhas = ESTADO_EMISSORES.resultadosFiltrados;
    if (!Array.isArray(linhas) || linhas.length === 0) {
        corpo.innerHTML = '';
        mensagemVazia.style.display = 'flex';
        mensagemVazia.textContent = ESTADO_EMISSORES.resultados.length === 0
            ? 'Nenhum registro encontrado.'
            : 'Nenhum registro corresponde aos filtros informados.';
        document.getElementById('toolbarTotal').textContent = '0';
        return;
    }

    var html = '';
    var origemAtual = null;

    for (var i = 0; i < linhas.length; i++) {
        var item = linhas[i] || {};
        var origemLinha = String(item.origem_formatada || '');
        if (origemAtual !== origemLinha) {
            html += '<tr class="linha-grupo" data-grupo-origem="' + escaparHtml(origemLinha) + '">';
            html += '<td colspan="' + String(4 + COLUNAS_RESULTADO.length) + '">Origem: ' + escaparHtml(origemLinha === '' ? '(sem origem)' : origemLinha) + '</td>';
            html += '</tr>';
            origemAtual = origemLinha;
        }

        html += '<tr data-idt="' + escaparHtml(item.idt || '') + '" data-origem="' + escaparHtml(origemLinha) + '" data-modelo="' + escaparHtml(item.modelo_nota_fiscal || '') + '">';
        html += '<td class="coluna-nome">' + escaparHtml(item._nome || '') + '</td>';
        html += '<td class="coluna-endereco">' + escaparHtml(item._endereco || '') + '</td>';
        html += '<td class="coluna-porta">' + escaparHtml(item._porta || '') + '</td>';
        html += '<td class="coluna-database">' + escaparHtml(item._database || '') + '</td>';

        for (var c = 0; c < COLUNAS_RESULTADO.length; c++) {
            var coluna = COLUNAS_RESULTADO[c];
            var valor = item[coluna] == null ? '' : String(item[coluna]);
            var classeColuna = CLASSES_COLUNAS_RESULTADO[coluna] || '';
            if (coluna === 'origem') {
                valor = String(item.origem_formatada || '');
            }
            if (coluna === 'serie_nota_fiscal') {
                html += '<td' + (classeColuna ? ' class="' + escaparHtml(classeColuna) + '"' : '') + '>' + formatarSerieHtml(valor) + '</td>';
            } else {
                html += '<td' + (classeColuna ? ' class="' + escaparHtml(classeColuna) + '"' : '') + '>' + escaparHtml(valor) + '</td>';
            }
        }

        html += '</tr>';
    }

    corpo.innerHTML = html;
    mensagemVazia.style.display = 'none';
    document.getElementById('toolbarTotal').textContent = String(linhas.length);
    colorirIdtsIguais();
}

function aplicarResumo(payload) {
    document.getElementById('tagGrupoSelecionado').textContent = payload.grupoSelecionado || '---';
    document.getElementById('toolbarGrupo').textContent = payload.grupoSelecionado || '---';
    document.getElementById('tagTotalResultados').textContent = String(payload.totalResultados || 0);
    document.getElementById('infoTotalResultados').textContent = String(payload.totalResultados || 0);
    document.getElementById('infoTotalOrigens').textContent = String(payload.totalOrigens || 0);
    document.getElementById('infoTotalModelos').textContent = String(payload.totalModelos || 0);
    document.getElementById('infoTotalErros').textContent = String(payload.totalErros || 0);
    definirMensagemPainel(payload.statusPainel || '');
}

function executarConsultaGrupo(grupo) {
    if (!grupo) {
        return;
    }

    ESTADO_EMISSORES.grupoSelecionado = grupo;
    mostrarOverlayConsultaDados('Aguarde Consultado...', 'Consultando dados dos emissores.');
    definirMensagemPainel('Consultando emissores do grupo ' + grupo + '...');
    document.getElementById('painelFiltrosLocais').style.display = 'none';
    document.getElementById('resultadoBody').innerHTML = '';
    document.getElementById('mensagemVazia').style.display = 'flex';
    document.getElementById('mensagemVazia').textContent = 'Consultando dados dos emissores...';

    var url = 'consultar_emissores.php?ajax_consultar=1&grupo=' + encodeURIComponent(grupo);

    fetch(url, {
        method: 'GET',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
        .then(function(resposta) {
            if (!resposta.ok) {
                throw new Error('Falha HTTP ao consultar emissores');
            }
            return resposta.json();
        })
        .then(function(payload) {
            if (!payload || !Array.isArray(payload.resultados)) {
                throw new Error(payload && payload.statusPainel ? String(payload.statusPainel) : 'Falha ao consultar emissores.');
            }

            ESTADO_EMISSORES.resultados = payload.resultados.map(function(item) {
                item = item || {};
                item.origem_formatada = String(item.origem == null ? '' : item.origem);
                if (item.origem_formatada !== '') {
                    item.origem_formatada = item.origem_formatada.replace(/^\s+|\s+$/g, '');
                    if (/^\d+$/.test(item.origem_formatada)) {
                        item.origem_formatada = item.origem_formatada.padStart(3, '0');
                    }
                }
                return item;
            });

            aplicarResumo(payload);
            renderizarMensagens(payload.mensagensErro || [], payload.totalErros > 0 ? [] : ['Consulta concluída.']);
            preencherSelect('filtro_origem', payload.origensDisponiveis || [], ORIGEM_FILTRO_INICIAL, 'Todas');
            preencherSelect('filtro_modelo', payload.modelosDisponiveis || [], '', 'Todos');
            document.getElementById('painelFiltrosLocais').style.display = ESTADO_EMISSORES.resultados.length > 0 ? '' : 'none';
            aplicarFiltrosTabela();
        })
        .catch(function(erro) {
            ESTADO_EMISSORES.resultados = [];
            ESTADO_EMISSORES.resultadosFiltrados = [];
            renderizarMensagens([erro && erro.message ? erro.message : 'Erro ao consultar emissores.'], []);
            document.getElementById('painelFiltrosLocais').style.display = 'none';
            document.getElementById('resultadoBody').innerHTML = '';
            document.getElementById('mensagemVazia').style.display = 'flex';
            document.getElementById('mensagemVazia').textContent = 'Erro ao consultar emissores.';
        })
        .finally(function() {
            ocultarOverlayConsultaDados();
        });
}

document.getElementById('filtro_origem').addEventListener('change', aplicarFiltrosTabela);
document.getElementById('filtro_modelo').addEventListener('change', aplicarFiltrosTabela);

document.querySelector('.form-upload-emissores').addEventListener('submit', function(evento) {
    evento.preventDefault();
    var grupo = document.getElementById('grupo').value;
    if (!grupo) {
        definirMensagemPainel('Selecione um grupo para consultar.');
        renderizarMensagens(['Selecione um grupo para consultar.'], []);
        return;
    }

    var novaUrl = 'consultar_emissores.php?grupo=' + encodeURIComponent(grupo);
    history.replaceState(null, '', novaUrl);
    ORIGEM_FILTRO_INICIAL = '';
    executarConsultaGrupo(grupo);
});

window.addEventListener('DOMContentLoaded', function() {
    carregarGruposAssincrono().catch(function() {});
    if (CONSULTA_AUTOMATICA && GRUPO_INICIAL !== '') {
        executarConsultaGrupo(GRUPO_INICIAL);
    }
});
</script>
</body>
</html>
