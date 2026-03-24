<?php

declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');
ini_set('implicit_flush', '1');

while (ob_get_level() > 0) {
    ob_end_flush();
}

if (PHP_VERSION_ID >= 80000) {
    ob_implicit_flush(true);
} else {
    ob_implicit_flush(1);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, max-age=600');
header('X-Accel-Buffering: no');

$arquivoConfiguracao = __DIR__ . '/config.json';


$arquivoLogPhp = __DIR__ . '/consultar_notas.log';
ini_set('log_errors', '1');
ini_set('error_log', $arquivoLogPhp);

function sairComErro(string $mensagem): void
{
    echo '<!doctype html>';
    echo '<html lang="pt-BR">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Consulta de Notas</title>';
    echo '<style>';
    echo 'html, body { height:100%; margin:0; }';
    echo 'body { background:#111; color:#eee; font-family:Arial,Helvetica,sans-serif; }';
    echo '.erro-wrap { padding:20px; }';
    echo '.erro-box { padding:12px; background:#3a1515; border:1px solid #a33; color:#ffb3b3; font-weight:bold; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
echo '<div class="overlay-processando" id="overlayProcessando">';
echo '<div class="overlay-box">';
echo '<strong>Consultando bancos</strong>';
echo '<span id="textoOverlayProcessando">Aguarde...</span>';
echo '</div>';
echo '</div>';
    echo '<div class="erro-wrap">';
    echo '<div class="erro-box">ERRO: ' . htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '</div>';
    echo '</body>';
    echo '</html>';
    exit;
}

function enviarSaida(): void
{
    echo str_repeat(' ', 8192);
    @ob_flush();
    @flush();
}

function jsTexto(string $texto): string
{
    return json_encode($texto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function listarBancos(mysqli $conexao): array
{
    $bancos = [];
    $resultado = $conexao->query('SHOW DATABASES');

    if ($resultado === false) {
        return $bancos;
    }

    while ($linha = $resultado->fetch_row()) {
        if (isset($linha[0])) {
            $bancos[] = (string)$linha[0];
        }
    }

    $resultado->free();

    return $bancos;
}

function tabelaExiste(mysqli $conexao, string $nomeBanco, string $nomeTabela): bool
{
    $sql = "
        SELECT COUNT(*) AS quantidade
        FROM information_schema.tables
        WHERE table_schema = ?
          AND table_name = ?
    ";

    $stmt = $conexao->prepare($sql);
    if ($stmt === false) {
        return false;
    }

    $stmt->bind_param('ss', $nomeBanco, $nomeTabela);
    $ok = $stmt->execute();

    if ($ok === false) {
        $stmt->close();
        return false;
    }

    $resultado = $stmt->get_result();
    $existe = false;

    if ($resultado !== false) {
        $linha = $resultado->fetch_assoc();
        $existe = isset($linha['quantidade']) && (int)$linha['quantidade'] > 0;
        $resultado->free();
    }

    $stmt->close();

    return $existe;
}

if (!file_exists($arquivoConfiguracao)) {
    sairComErro('Arquivo config.json não encontrado em: ' . $arquivoConfiguracao);
}

$conteudoJson = file_get_contents($arquivoConfiguracao);
if ($conteudoJson === false) {
    sairComErro('Não foi possível ler o config.json.');
}

$configuracao = json_decode($conteudoJson, true);
if (!is_array($configuracao)) {
    sairComErro('JSON inválido no config.json.');
}

if (
    empty($configuracao['mysql_usuario']) ||
    !array_key_exists('mysql_senha', $configuracao) ||
    empty($configuracao['servidores']) ||
    !is_array($configuracao['servidores'])
) {
    sairComErro('Configuração incompleta. Verifique mysql_usuario, mysql_senha e servidores.');
}

$filtroDatabase = isset($_GET['database']) ? trim((string)$_GET['database']) : '';
$diasConsulta = isset($_GET['dias']) ? (int)$_GET['dias'] : 7;
if ($diasConsulta <= 0) {
    $diasConsulta = 7;
}
$executarConsulta = isset($_GET['consultar']) && $_GET['consultar'] === '1';

error_log('CONSULTAR_NOTAS pagina iniciada. executar=' . ($executarConsulta ? '1' : '0') . ' database=' . $filtroDatabase . ' dias=' . $diasConsulta);

$mysqlUsuario = (string)$configuracao['mysql_usuario'];
$mysqlSenha = (string)$configuracao['mysql_senha'];
$servidores = $configuracao['servidores'];

$totalConsultasOk = 0;
$totalConsultasErro = 0;
$totalQuantidadeNotas = 0;
$totalBasesComNotas = 0;

mysqli_report(MYSQLI_REPORT_OFF);

echo '<!doctype html>';
echo '<html lang="pt-BR">';
echo '<head>';
echo '<meta charset="utf-8">';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>Consulta de Notas</title>';
echo '<style>';
echo 'html, body { height:100%; margin:0; }';
echo 'body { background:#111; color:#eee; font-family:Arial,Helvetica,sans-serif; overflow:hidden; }';
echo '.pagina { display:flex; flex-direction:column; height:100vh; }';
echo '.topo-fixo { flex:0 0 auto; padding:16px 20px 12px 20px; background:#111; border-bottom:1px solid #2b2b2b; }';
echo '.topo-fixo h2 { margin:0 0 10px 0; }';
echo '.inicio { margin-bottom:10px; color:#cfcfcf; }';
echo '.filtros { margin-bottom:10px; padding:10px 12px; background:#1a1a1a; border:1px solid #333; border-radius:6px; }';
echo '.filtros form { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }';
echo '.filtro-campo { display:flex; flex-direction:column; gap:4px; }';
echo '.filtro-campo label { color:#ccc; font-size:13px; }';
echo '.filtro-campo input { min-width:180px; padding:8px 10px; background:#111; color:#eee; border:1px solid #444; border-radius:6px; }';
echo '.filtro-campo input[type=number] { width:120px; min-width:120px; }';
echo '.botao { padding:9px 14px; background:#222; color:#fff; border:1px solid #444; border-radius:6px; cursor:pointer; }';
echo '.botao:hover { background:#2d2d2d; }';
echo '.status { padding:10px 12px; background:#1a1a1a; border:1px solid #333; border-radius:6px; color:#ddd; margin-bottom:10px; }';
echo '.resumo { padding:10px 12px; background:#1a1a1a; border:1px solid #333; border-radius:6px; }';
echo '.resumo div { padding:2px 0; }';
echo '.area-tabela { flex:1 1 auto; min-height:0; padding:12px 20px 20px 20px; overflow:auto; }';
echo 'table { width:100%; border-collapse:collapse; background:#181818; }';
echo 'th, td { border:1px solid #333; padding:10px; text-align:left; }';
echo 'thead th { background:#222; color:#fff; position:sticky; top:0; z-index:2; }';
echo 'tr.linha-verde td { background:#132a13; color:#c8f7c5; }';
echo 'tr.linha-vermelha td { background:#3a1515; color:#ffb3b3; }';
echo '.mensagem-vazia { padding:12px; background:#1a1a1a; border:1px solid #333; border-radius:6px; color:#ccc; margin-top:10px; }';
echo '.ok { color:#c8f7c5; }';
echo '.erro { color:#ffb3b3; }';
echo '.link-quantidade { color:inherit; font-weight:bold; text-decoration:underline; }';
echo '.link-quantidade:hover { opacity:0.9; }';
echo '.overlay-processando { position:fixed; inset:0; background:rgba(0,0,0,0.45); display:none; align-items:center; justify-content:center; z-index:9999; }';
echo '.overlay-processando.ativo { display:flex; }';
echo '.overlay-box { min-width:320px; max-width:520px; padding:18px 22px; background:#1a1a1a; border:1px solid #3a3a3a; border-radius:8px; box-shadow:0 8px 30px rgba(0,0,0,0.45); text-align:center; }';
echo '.overlay-box strong { display:block; font-size:18px; color:#fff; margin-bottom:8px; }';
echo '.overlay-box span { color:#cfcfcf; }';
echo '</style>';
echo '<script>';
echo 'function mostrarOverlayProcessando(texto) {';
echo '  var overlay = document.getElementById("overlayProcessando");';
echo '  var textoOverlay = document.getElementById("textoOverlayProcessando");';
echo '  if (textoOverlay && typeof texto !== "undefined") { textoOverlay.textContent = texto; }';
echo '  if (overlay) { overlay.className = "overlay-processando ativo"; }';
echo '}';
echo 'function ocultarOverlayProcessando() {';
echo '  var overlay = document.getElementById("overlayProcessando");';
echo '  if (overlay) { overlay.className = "overlay-processando"; }';
echo '}';
echo 'function atualizarStatus(texto) {';
echo '  document.getElementById("statusAtual").textContent = texto;';
echo '}';
echo 'function atualizarResumo(bases, ok, erro, total, fim) {';
echo '  document.getElementById("totalBasesComNotas").textContent = bases;';
echo '  document.getElementById("totalConsultasOk").textContent = ok;';
echo '  document.getElementById("totalConsultasErro").textContent = erro;';
echo '  document.getElementById("totalQuantidadeNotas").textContent = total;';
echo '  if (fim !== null) { document.getElementById("fimExecucao").textContent = fim; }';
echo '}';
echo 'function adicionarResultado(servidor, endereco, porta, database, quantidade, classeLinha, indiceServidor, diasConsulta) {';
echo '  var tbody = document.getElementById("corpoTabela");';
echo '  var tr = document.createElement("tr");';
echo '  tr.className = classeLinha;';
echo '  var tdServidor = document.createElement("td");';
echo '  tdServidor.textContent = servidor;';
echo '  tr.appendChild(tdServidor);';
echo '  var tdEndereco = document.createElement("td");';
echo '  tdEndereco.textContent = endereco;';
echo '  tr.appendChild(tdEndereco);';
echo '  var tdPorta = document.createElement("td");';
echo '  tdPorta.textContent = porta;';
echo '  tr.appendChild(tdPorta);';
echo '  var tdDatabase = document.createElement("td");';
echo '  tdDatabase.textContent = database;';
echo '  tr.appendChild(tdDatabase);';
echo '  var tdQuantidade = document.createElement("td");';
echo '  var link = document.createElement("a");';
echo '  link.className = "link-quantidade";';
echo '  link.textContent = quantidade;';
echo '  link.href = "consultar_notas_detalhes.php?s=" + encodeURIComponent(indiceServidor) + "&db=" + encodeURIComponent(database) + "&dias=" + encodeURIComponent(diasConsulta);';
echo '  link.target = "_blank";';
echo '  link.rel = "noopener noreferrer";';
echo '  link.onclick = function(e) {';
echo '    e.preventDefault();';
echo '    window.open(link.href, "_blank", "noopener,noreferrer");';
echo '    return false;';
echo '  };';
echo '  tdQuantidade.appendChild(link);';
echo '  tr.appendChild(tdQuantidade);';
echo '  tbody.appendChild(tr);';
echo '  document.getElementById("mensagemVazia").style.display = "none";';
echo '}';
echo '</script>';
echo '</head>';
echo '<body>';
echo '<div class="pagina">';

echo '<div class="topo-fixo">';
echo '<h2>Consulta de notas fiscais</h2>';
echo '<div class="inicio">Início da página: ' . htmlspecialchars(date('d/m/Y H:i:s'), ENT_QUOTES, 'UTF-8') . '</div>';

echo '<div class="filtros">';
echo '<form method="get" action="" onsubmit="mostrarOverlayProcessando(\'Consultando bancos, aguarde...\')">';
echo '<input type="hidden" name="consultar" value="1">';
echo '<div class="filtro-campo">';
echo '<label for="database">Database (like)</label>';
echo '<input type="text" id="database" name="database" value="' . htmlspecialchars($filtroDatabase, ENT_QUOTES, 'UTF-8') . '">';
echo '</div>';
echo '<div class="filtro-campo">';
echo '<label for="dias">Dias</label>';
echo '<input type="number" id="dias" name="dias" min="1" value="' . $diasConsulta . '">';
echo '</div>';
echo '<div>';
echo '<button class="botao" type="submit">Consultar</button>';
echo '</div>';
echo '</form>';
echo '</div>';

if ($executarConsulta) {
    $textoStatusInicial = 'Preparando consulta...';
} else {
    $textoStatusInicial = 'Aguardando clique em Consultar.';
}

echo '<div class="status" id="statusAtual">' . htmlspecialchars($textoStatusInicial, ENT_QUOTES, 'UTF-8') . '</div>';
if ($executarConsulta) {
    echo '<script>mostrarOverlayProcessando("Consultando bancos, aguarde...");</script>';
    enviarSaida();
}
echo '<div class="resumo">';
echo '<div><b>Filtro database:</b> ' . htmlspecialchars($filtroDatabase !== '' ? $filtroDatabase : '(todos)', ENT_QUOTES, 'UTF-8') . '</div>';
echo '<div><b>Dias da consulta:</b> ' . $diasConsulta . '</div>';
echo '<div class="ok">Bases com notas encontradas: <b id="totalBasesComNotas">0</b></div>';
echo '<div>Consultas executadas: <b id="totalConsultasOk">0</b></div>';
echo '<div class="erro">Consultas com erro: <b id="totalConsultasErro">0</b></div>';
echo '<div>Total geral de notas: <b id="totalQuantidadeNotas">0</b></div>';
echo '<div>Fim: <b id="fimExecucao">-</b></div>';
echo '</div>';
echo '</div>';

echo '<div class="area-tabela">';
echo '<table>';
echo '<thead>';
echo '<tr>';
echo '<th>Servidor</th>';
echo '<th>Endereço</th>';
echo '<th>Porta</th>';
echo '<th>Database</th>';
echo '<th>Quantidade</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody id="corpoTabela">';
echo '</tbody>';
echo '</table>';
if ($executarConsulta) {
    echo '<div class="mensagem-vazia" id="mensagemVazia">Nenhum resultado encontrado até o momento.</div>';
} else {
    echo '<div class="mensagem-vazia" id="mensagemVazia">Preencha os filtros desejados e clique em Consultar.</div>';
}
echo '</div>';

echo '</div>';
echo '</body>';
echo '</html>';

enviarSaida();

if (!$executarConsulta) {
    exit;
}

foreach ($servidores as $indiceServidor => $servidor) {
    $identificacao = isset($servidor['identificacao']) ? trim((string)$servidor['identificacao']) : '';
    $endereco = isset($servidor['endereco']) ? trim((string)$servidor['endereco']) : '';
    $porta = isset($servidor['porta']) ? (int)$servidor['porta'] : 3306;

    if ($identificacao === '' || $endereco === '') {
        $totalConsultasErro++;
        echo '<script>';
        echo 'atualizarStatus(' . jsTexto('Servidor ignorado por falta de configuração.') . ');';
        echo 'atualizarResumo(' . $totalBasesComNotas . ',' . $totalConsultasOk . ',' . $totalConsultasErro . ',' . $totalQuantidadeNotas . ', null);';
        echo '</script>';
        enviarSaida();
        continue;
    }

    echo '<script>';
    echo 'atualizarStatus(' . jsTexto('Conectando em servidor: ' . $identificacao . ' (' . $endereco . ':' . $porta . ')...') . ');';
    echo '</script>';
    enviarSaida();

    error_log('CONSULTAR_NOTAS conectando servidor=' . $identificacao . ' host=' . $endereco . ' porta=' . $porta);
    $conexao = @new mysqli($endereco, $mysqlUsuario, $mysqlSenha, '', $porta);

    if ($conexao->connect_errno) {
        $totalConsultasErro++;
        echo '<script>';
        echo 'atualizarStatus(' . jsTexto('Erro ao conectar em ' . $identificacao . ': ' . $conexao->connect_error) . ');';
        echo 'atualizarResumo(' . $totalBasesComNotas . ',' . $totalConsultasOk . ',' . $totalConsultasErro . ',' . $totalQuantidadeNotas . ', null);';
        echo '</script>';
        enviarSaida();
        continue;
    }

    $conexao->set_charset('utf8mb4');

    $bancos = listarBancos($conexao);

    foreach ($bancos as $nomeBanco) {
        if (strcasecmp($nomeBanco, 'posto_teste') === 0) {
            continue;
        }

        error_log('CONSULTAR_NOTAS verificando servidor=' . $identificacao . ' database=' . $nomeBanco);

        if ($filtroDatabase !== '' && stripos($nomeBanco, $filtroDatabase) === false) {
            continue;
        }

        echo '<script>';
        echo 'atualizarStatus(' . jsTexto('Verificando ' . $identificacao . ' / database: ' . $nomeBanco) . ');';
        echo '</script>';
        enviarSaida();

        $temNotasEletronicas = tabelaExiste($conexao, $nomeBanco, 'notas_fiscais_eletronicas');
        if (!$temNotasEletronicas) {
            continue;
        }

        $temNotas = tabelaExiste($conexao, $nomeBanco, 'notas_fiscais');
        if (!$temNotas) {
            $totalConsultasErro++;
            echo '<script>';
            echo 'atualizarResumo(' . $totalBasesComNotas . ',' . $totalConsultasOk . ',' . $totalConsultasErro . ',' . $totalQuantidadeNotas . ', null);';
            echo '</script>';
            enviarSaida();
            continue;
        }

        $nomeBancoEscapado = str_replace('`', '``', $nomeBanco);

        $sqlConsulta = "
            SELECT COUNT(*) AS quantidade
            FROM `{$nomeBancoEscapado}`.`notas_fiscais_eletronicas` a
            INNER JOIN `{$nomeBancoEscapado}`.`notas_fiscais` b
                ON a.idt = b.idt and a.origem=b.origem
            WHERE a.status NOT IN (100, 150, 101, 102)
              AND b.emissao >= DATE_SUB(NOW(), INTERVAL {$diasConsulta} DAY)
        ";

        $resultadoConsulta = $conexao->query($sqlConsulta);

        if ($resultadoConsulta === false) {
            $totalConsultasErro++;
            echo '<script>';
            echo 'atualizarResumo(' . $totalBasesComNotas . ',' . $totalConsultasOk . ',' . $totalConsultasErro . ',' . $totalQuantidadeNotas . ', null);';
            echo '</script>';
            enviarSaida();
            continue;
        }

        $linhaConsulta = $resultadoConsulta->fetch_assoc();
        $quantidade = isset($linhaConsulta['quantidade']) ? (int)$linhaConsulta['quantidade'] : 0;
        $resultadoConsulta->free();

        $totalConsultasOk++;

        if ($quantidade > 0) {
            error_log('CONSULTAR_NOTAS resultado servidor=' . $identificacao . ' database=' . $nomeBanco . ' quantidade=' . $quantidade);
            $totalQuantidadeNotas += $quantidade;
            $totalBasesComNotas++;

            $classeLinha = ($quantidade > 100) ? 'linha-vermelha' : 'linha-verde';

            echo '<script>';
            echo 'adicionarResultado('
                . jsTexto($identificacao) . ','
                . jsTexto($endereco) . ','
                . jsTexto((string)$porta) . ','
                . jsTexto($nomeBanco) . ','
                . jsTexto((string)$quantidade) . ','
                . jsTexto($classeLinha) . ','
                . jsTexto((string)$indiceServidor) . ','
                . jsTexto((string)$diasConsulta)
                . ');';
            echo 'atualizarResumo(' . $totalBasesComNotas . ',' . $totalConsultasOk . ',' . $totalConsultasErro . ',' . $totalQuantidadeNotas . ', null);';
            echo '</script>';
            enviarSaida();
        } else {
            echo '<script>';
            echo 'atualizarResumo(' . $totalBasesComNotas . ',' . $totalConsultasOk . ',' . $totalConsultasErro . ',' . $totalQuantidadeNotas . ', null);';
            echo '</script>';
            enviarSaida();
        }
    }

    $conexao->close();
}

error_log('CONSULTAR_NOTAS finalizada bases=' . $totalBasesComNotas . ' ok=' . $totalConsultasOk . ' erro=' . $totalConsultasErro . ' total=' . $totalQuantidadeNotas);

echo '<script>';
echo 'atualizarStatus(' . jsTexto('Consulta finalizada.') . ');';
echo 'ocultarOverlayProcessando();';
echo 'atualizarResumo('
    . $totalBasesComNotas . ','
    . $totalConsultasOk . ','
    . $totalConsultasErro . ','
    . $totalQuantidadeNotas . ','
    . jsTexto(date('d/m/Y H:i:s'))
    . ');';
echo '</script>';

enviarSaida();
