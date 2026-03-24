<?php

declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(true);
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

function listarBancos(mysqli $conexao, string $filtroDatabase = ''): array
{
    $bancos = [];

    if ($filtroDatabase !== '') {
        $sql = '
            SELECT schema_name
            FROM information_schema.schemata
            WHERE schema_name LIKE ?
            ORDER BY schema_name
        ';

        $stmt = $conexao->prepare($sql);
        if ($stmt === false) {
            return $bancos;
        }

        $like = '%' . $filtroDatabase . '%';
        $stmt->bind_param('s', $like);
        $ok = $stmt->execute();

        if ($ok === false) {
            $stmt->close();
            return $bancos;
        }

        $resultado = $stmt->get_result();
        if ($resultado !== false) {
            while ($linha = $resultado->fetch_row()) {
                if (isset($linha[0])) {
                    $bancos[] = (string)$linha[0];
                }
            }
            $resultado->free();
        }

        $stmt->close();

        return $bancos;
    }

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

function criarConexaoMysql(string $endereco, string $usuario, string $senha, int $porta)
{
    $conexao = mysqli_init();
    if ($conexao === false) {
        return false;
    }

    if (defined('MYSQLI_OPT_CONNECT_TIMEOUT')) {
        @mysqli_options($conexao, MYSQLI_OPT_CONNECT_TIMEOUT, 120);
    }

    if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
        @mysqli_options($conexao, MYSQLI_OPT_READ_TIMEOUT, 600);
    }

    $ok = @mysqli_real_connect($conexao, $endereco, $usuario, $senha, '', $porta);
    if ($ok !== true) {
        @mysqli_close($conexao);
        return false;
    }

    @$conexao->set_charset('utf8mb4');
    @$conexao->query('SET SESSION wait_timeout = 28800');
    @$conexao->query('SET SESSION net_read_timeout = 600');
    @$conexao->query('SET SESSION net_write_timeout = 600');
    @$conexao->query('SET SESSION innodb_lock_wait_timeout = 600');

    return $conexao;
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
$textoStatusInicial = $executarConsulta
    ? 'Preparando consulta...'
    : 'Preencha os filtros desejados e clique em Consultar.';

mysqli_report(MYSQLI_REPORT_OFF);

?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Consulta de Notas</title>
<style>
:root {
    --bg: #05070b;
    --bg-alt: #0b1018;
    --panel: rgba(15, 22, 34, 0.92);
    --panel-strong: rgba(19, 28, 43, 0.96);
    --line: rgba(130, 155, 190, 0.18);
    --line-strong: rgba(130, 155, 190, 0.32);
    --text: #eef3ff;
    --muted: #95a6c5;
    --accent: #53a7ff;
    --accent-soft: rgba(83, 167, 255, 0.18);
    --ok: #73d98b;
    --ok-soft: rgba(115, 217, 139, 0.16);
    --warn: #ffd166;
    --warn-soft: rgba(255, 209, 102, 0.16);
    --danger: #ff6d6d;
    --danger-soft: rgba(255, 109, 109, 0.16);
    --shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
}
* { box-sizing: border-box; }
html, body { height: 100%; margin: 0; }
body {
    min-height: 100%;
    background:
        radial-gradient(circle at top right, rgba(57, 114, 197, 0.22), transparent 34%),
        radial-gradient(circle at top left, rgba(255, 109, 109, 0.14), transparent 26%),
        linear-gradient(180deg, #04060a 0%, #0a0e15 100%);
    color: var(--text);
    font-family: Inter, Segoe UI, Arial, Helvetica, sans-serif;
    overflow: hidden;
    zoom: 0.8;
}
.app-shell {
    height: 125vh;
    display: grid;
    grid-template-rows: auto 1fr;
}
@supports not (zoom: 1) {
    body {
        zoom: 1;
    }
    .app-shell {
        transform: scale(0.8);
        transform-origin: top left;
        width: 125%;
        height: 125vh;
    }
}
.topbar {
    position: relative;
    padding: 22px 24px 16px 24px;
    border-bottom: 1px solid var(--line);
    background: linear-gradient(180deg, rgba(7, 10, 16, 0.96), rgba(7, 10, 16, 0.88));
    backdrop-filter: blur(16px);
}
.topbar::after {
    content: "";
    position: absolute;
    left: 24px;
    right: 24px;
    bottom: 0;
    height: 2px;
    background: linear-gradient(90deg, rgba(83, 167, 255, 0.05), rgba(83, 167, 255, 0.58), rgba(255, 109, 109, 0.05));
}
.title-row {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
}
.title-block h1 {
    margin: 0;
    font-size: 28px;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.title-block p {
    margin: 6px 0 0 0;
    color: var(--muted);
    font-size: 14px;
}
.tag-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: flex-end;
}
.chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 38px;
    padding: 0 14px;
    border-radius: 999px;
    border: 1px solid var(--line);
    background: rgba(12, 17, 27, 0.72);
    color: var(--muted);
    font-size: 13px;
}
.chip strong { color: var(--text); font-weight: 700; }
.chip.live { border-color: rgba(83, 167, 255, 0.4); color: #cfe6ff; }
.toolbar-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 14px;
    align-items: stretch;
}
.panel {
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 20px;
    box-shadow: var(--shadow);
}
.panel-form {
    padding: 18px;
}
.panel-form form {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 120px auto;
    gap: 12px;
    align-items: end;
}
.field label {
    display: block;
    margin: 0 0 8px 0;
    font-size: 12px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--muted);
}
.field input {
    width: 100%;
    height: 46px;
    padding: 0 14px;
    border-radius: 14px;
    border: 1px solid rgba(132, 150, 181, 0.22);
    background: rgba(6, 11, 19, 0.96);
    color: var(--text);
    outline: none;
}
.field input:focus {
    border-color: rgba(83, 167, 255, 0.58);
    box-shadow: 0 0 0 4px rgba(83, 167, 255, 0.08);
}
.botao {
    height: 46px;
    padding: 0 20px;
    border: 1px solid rgba(83, 167, 255, 0.4);
    border-radius: 14px;
    background: linear-gradient(180deg, rgba(31, 76, 130, 0.95), rgba(18, 52, 96, 0.95));
    color: #fff;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    cursor: pointer;
}
.botao:hover { filter: brightness(1.06); }
.telemetry {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}
.metric {
    position: relative;
    padding: 16px 18px;
    min-height: 92px;
    overflow: hidden;
}
.metric::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 4px;
    background: linear-gradient(180deg, rgba(83, 167, 255, 0.9), rgba(83, 167, 255, 0.16));
}
.metric.ok::before { background: linear-gradient(180deg, rgba(115, 217, 139, 0.95), rgba(115, 217, 139, 0.16)); }
.metric.warn::before { background: linear-gradient(180deg, rgba(255, 209, 102, 0.95), rgba(255, 209, 102, 0.18)); }
.metric.danger::before { background: linear-gradient(180deg, rgba(255, 109, 109, 0.95), rgba(255, 109, 109, 0.16)); }
.metric .label {
    font-size: 11px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
.metric .value {
    margin-top: 10px;
    font-size: 30px;
    font-weight: 800;
    letter-spacing: -0.03em;
}
.metric .hint {
    margin-top: 6px;
    font-size: 12px;
    color: var(--muted);
}
.status-bar {
    margin-top: 14px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-radius: 16px;
    background: rgba(8, 13, 21, 0.9);
    border: 1px solid var(--line);
}
.status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #53a7ff;
    box-shadow: 0 0 18px rgba(83, 167, 255, 0.75);
}
.status-text {
    flex: 1 1 320px;
    min-width: 0;
    color: #dbe7ff;
    font-size: 14px;
    white-space: normal;
    overflow-wrap: anywhere;
}
.cache-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 34px;
    padding: 0 12px;
    border-radius: 999px;
    border: 1px solid var(--line);
    background: rgba(12, 17, 27, 0.76);
    color: var(--muted);
    font-size: 12px;
}
.cache-pill.cache-online { border-color: rgba(83, 167, 255, 0.48); color: #d8ebff; }
.cache-pill.cache-local { border-color: rgba(115, 217, 139, 0.42); color: #d9ffe3; }
.cache-pill.cache-empty { border-color: rgba(255, 209, 102, 0.38); color: #fff0b8; }
.content-shell {
    min-height: 0;
    padding: 18px 24px 24px 24px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.surface {
    flex: 1 1 auto;
    height: 100%;
    display: flex;
    flex-direction: column;
    min-height: 0;
    background: var(--panel-strong);
    border: 1px solid var(--line);
    border-radius: 22px;
    box-shadow: var(--shadow);
    overflow: hidden;
}
.surface-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--line);
    background: linear-gradient(180deg, rgba(11, 16, 25, 0.98), rgba(11, 16, 25, 0.9));
}
.surface-title {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: 0.03em;
    text-transform: uppercase;
}
.surface-subtitle {
    margin-top: 4px;
    color: var(--muted);
    font-size: 13px;
}
.table-wrap {
    flex: 1;
    min-height: 0;
    overflow: auto;
}
.table-wrap table {
    width: 100%;
    border-collapse: collapse;
}
.table-wrap th,
.table-wrap td {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(128, 148, 180, 0.12);
    text-align: left;
}
.table-wrap thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: rgba(8, 13, 21, 0.98);
    color: #dbe7ff;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: 11px;
}
.table-wrap tbody tr:nth-child(even) td { background: rgba(255, 255, 255, 0.01); }
.table-wrap tbody tr:hover td { background: rgba(83, 167, 255, 0.05); }
.table-wrap tbody tr.linha-verde td { background: rgba(22, 52, 31, 0.72); }
.table-wrap tbody tr.linha-vermelha td { background: rgba(76, 22, 22, 0.72); }
.table-wrap td.col-quantidade { width: 170px; }
.table-wrap td .link-quantidade {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 86px;
    height: 38px;
    padding: 0 14px;
    border-radius: 999px;
    border: 1px solid rgba(83, 167, 255, 0.28);
    background: rgba(83, 167, 255, 0.09);
    color: #f3f7ff;
    text-decoration: none;
    font-weight: 800;
}
.table-wrap td .link-quantidade:hover {
    background: rgba(83, 167, 255, 0.18);
    border-color: rgba(83, 167, 255, 0.48);
}
.empty-state {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 120px;
    padding: 22px;
    color: var(--muted);
    text-align: center;
    border-top: 1px solid var(--line);
}
.overlay-processando {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(3, 5, 8, 0.72);
    backdrop-filter: blur(12px);
    z-index: 9999;
}
.overlay-processando.ativo { display: flex; }
.overlay-box {
    width: min(460px, calc(100vw - 36px));
    padding: 22px 24px;
    border-radius: 22px;
    border: 1px solid rgba(83, 167, 255, 0.26);
    background: rgba(10, 16, 26, 0.96);
    box-shadow: var(--shadow);
    text-align: left;
}
.overlay-box strong {
    display: block;
    margin-bottom: 8px;
    font-size: 20px;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.overlay-box span { color: var(--muted); }
@media (max-width: 1200px) {
    .telemetry { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 760px) {
    body { overflow: auto; }
    .app-shell { height: auto; min-height: 100vh; grid-template-rows: auto auto; }
    .title-row { flex-direction: column; align-items: flex-start; }
    .tag-row { justify-content: flex-start; }
    .panel-form form { grid-template-columns: 1fr; }
    .telemetry { grid-template-columns: 1fr; }
    .content-shell { padding-top: 12px; }
    .table-wrap { overflow: auto; }
}
</style>
<script>
var ESTADO_RESUMO = {
    linhas: [],
    resumo: {
        bases: 0,
        ok: 0,
        erro: 0,
        total: 0,
        fim: '-'
    },
    cacheKey: null,
    consultando: false,
    persistenciaPendente: null,
    recebeuOnline: false
};
var FILTRO_DATABASE_RESUMO = <?= json_encode(strtolower($filtroDatabase), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || '';
var CANAL_ATUALIZACAO_RESUMO = 'consulta_erros_nfe_resumo';
var STORAGE_ATUALIZACAO_RESUMO = 'consulta_erros_nfe_resumo_update';
var canalResumoAtualizacao = null;
var DB_UI_NFE = 'consulta_erros_nfe_ui';
var DB_VERSAO_UI_NFE = 2;
var dbUiPromise = null;
function obterChaveResumoLocal() {
    var filtro = <?= json_encode($filtroDatabase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || '';
    return 'resumo::' + window.location.pathname + '::' + filtro.toLowerCase() + '::' + <?= (int) $diasConsulta ?>;
}
function abrirBancoUi() {
    if (!window.indexedDB) {
        return Promise.resolve(null);
    }
    if (dbUiPromise) {
        return dbUiPromise;
    }
    dbUiPromise = new Promise(function(resolve) {
        try {
            var request = window.indexedDB.open(DB_UI_NFE, DB_VERSAO_UI_NFE);
            request.onupgradeneeded = function(evento) {
                var db = evento.target.result;
                if (!db.objectStoreNames.contains('resumo')) {
                    db.createObjectStore('resumo', { keyPath: 'cacheKey' });
                }
                if (!db.objectStoreNames.contains('detalhes')) {
                    db.createObjectStore('detalhes', { keyPath: 'cacheKey' });
                }
                if (!db.objectStoreNames.contains('ui_estado')) {
                    db.createObjectStore('ui_estado', { keyPath: 'cacheKey' });
                }
            };
            request.onsuccess = function() { resolve(request.result); };
            request.onerror = function() { resolve(null); };
        } catch (erro) {
            resolve(null);
        }
    });
    return dbUiPromise;
}
function salvarRegistro(storeName, payload) {
    return abrirBancoUi().then(function(db) {
        if (!db) { return false; }
        return new Promise(function(resolve) {
            try {
                var tx = db.transaction(storeName, 'readwrite');
                tx.objectStore(storeName).put(payload);
                tx.oncomplete = function() { resolve(true); };
                tx.onerror = function() { resolve(false); };
            } catch (erro) {
                resolve(false);
            }
        });
    });
}
function lerRegistro(storeName, cacheKey) {
    return abrirBancoUi().then(function(db) {
        if (!db) { return null; }
        return new Promise(function(resolve) {
            try {
                var tx = db.transaction(storeName, 'readonly');
                var request = tx.objectStore(storeName).get(cacheKey);
                request.onsuccess = function() { resolve(request.result || null); };
                request.onerror = function() { resolve(null); };
            } catch (erro) {
                resolve(null);
            }
        });
    });
}
function escaparHtml(texto) {
    return String(texto == null ? '' : texto)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
function mostrarOverlayProcessando(texto) {
    var overlay = document.getElementById('overlayProcessando');
    var textoOverlay = document.getElementById('textoOverlayProcessando');
    if (textoOverlay && typeof texto !== 'undefined') {
        textoOverlay.textContent = texto;
    }
    if (overlay) {
        overlay.className = 'overlay-processando ativo';
    }
    atualizarIndicadorCache('Consulta online em andamento', 'cache-online');
}
function ocultarOverlayProcessando() {
    var overlay = document.getElementById('overlayProcessando');
    if (overlay) {
        overlay.className = 'overlay-processando';
    }
}
function atualizarIndicadorCache(texto, classe) {
    var badge = document.getElementById('origemDados');
    if (!badge) { return; }
    badge.className = 'cache-pill ' + (classe || '');
    badge.textContent = texto;
}
function atualizarStatus(texto) {
    var el = document.getElementById('statusAtual');
    if (el) {
        el.textContent = texto;
    }
}

function marcarAtualizacaoOnline() {
    ESTADO_RESUMO.recebeuOnline = true;
}

function atualizarMensagemVazia() {
    var box = document.getElementById('mensagemVazia');
    if (!box) { return; }
    if (ESTADO_RESUMO.linhas.length > 0) {
        box.style.display = 'none';
    } else {
        box.style.display = 'flex';
    }
}
function aplicarResumoNaTela() {
    var mapa = {
        totalBasesComNotas: ESTADO_RESUMO.resumo.bases,
        totalConsultasOk: ESTADO_RESUMO.resumo.ok,
        totalConsultasErro: ESTADO_RESUMO.resumo.erro,
        totalQuantidadeNotas: ESTADO_RESUMO.resumo.total,
        fimExecucao: ESTADO_RESUMO.resumo.fim
    };
    Object.keys(mapa).forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = mapa[id];
        }
    });
}
function recalcularResumoPorLinhas() {
    var total = 0;
    for (var i = 0; i < ESTADO_RESUMO.linhas.length; i++) {
        total += Number(ESTADO_RESUMO.linhas[i].quantidade || 0);
    }
    ESTADO_RESUMO.resumo.bases = ESTADO_RESUMO.linhas.length;
    ESTADO_RESUMO.resumo.total = total;
    aplicarResumoNaTela();
}
function databaseCombinaComFiltroAtual(database) {
    var nome = String(database == null ? '' : database).toLowerCase();
    if (!FILTRO_DATABASE_RESUMO) {
        return true;
    }
    return nome.indexOf(FILTRO_DATABASE_RESUMO) !== -1;
}
function atualizarResumo(bases, ok, erro, total, fim) {
    ESTADO_RESUMO.resumo.bases = Number(bases || 0);
    ESTADO_RESUMO.resumo.ok = Number(ok || 0);
    ESTADO_RESUMO.resumo.erro = Number(erro || 0);
    ESTADO_RESUMO.resumo.total = Number(total || 0);
    if (fim !== null && typeof fim !== 'undefined') {
        ESTADO_RESUMO.resumo.fim = fim;
    }
    aplicarResumoNaTela();
    persistirResumoDebounce();
}
function obterChaveLinhaResumo(linha) {
    return [
        String(linha.indiceServidor == null ? '' : linha.indiceServidor),
        String(linha.database == null ? '' : linha.database).toLowerCase(),
        String(linha.porta == null ? '' : linha.porta)
    ].join('::');
}
function htmlLinhaResumo(linha) {
    var url = 'consultar_notas_detalhes.php?s=' + encodeURIComponent(linha.indiceServidor) + '&db=' + encodeURIComponent(linha.database) + '&dias=' + encodeURIComponent(linha.diasConsulta) + '&limpar_filtro=1';
    return '<tr class="' + escaparHtml(linha.classeLinha) + '">' +
        '<td>' + escaparHtml(linha.servidor) + '</td>' +
        '<td>' + escaparHtml(linha.endereco) + '</td>' +
        '<td>' + escaparHtml(linha.porta) + '</td>' +
        '<td>' + escaparHtml(linha.database) + '</td>' +
        '<td class="col-quantidade"><a class="link-quantidade" href="' + escaparHtml(url) + '" target="_blank" rel="noopener noreferrer" onclick="window.open(this.href, \"_blank\", \"noopener,noreferrer\"); return false;">' + escaparHtml(linha.quantidade) + '</a></td>' +
        '</tr>';
}
function renderizarTabelaResumo() {
    var corpo = document.getElementById('corpoTabela');
    if (!corpo) { return; }
    if (!ESTADO_RESUMO.linhas.length) {
        corpo.innerHTML = '';
        atualizarMensagemVazia();
        return;
    }
    var html = '';
    for (var i = 0; i < ESTADO_RESUMO.linhas.length; i++) {
        html += htmlLinhaResumo(ESTADO_RESUMO.linhas[i]);
    }
    corpo.innerHTML = html;
    atualizarMensagemVazia();
}
function persistirResumoDebounce() {
    if (ESTADO_RESUMO.persistenciaPendente) {
        window.clearTimeout(ESTADO_RESUMO.persistenciaPendente);
    }
    ESTADO_RESUMO.persistenciaPendente = window.setTimeout(function() {
        persistirResumoAgora();
    }, 180);
}
function persistirResumoAgora() {
    salvarRegistro('resumo', {
        cacheKey: ESTADO_RESUMO.cacheKey,
        linhas: ESTADO_RESUMO.linhas,
        resumo: ESTADO_RESUMO.resumo,
        atualizadoEm: new Date().toISOString(),
        filtroDatabase: <?= json_encode($filtroDatabase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        diasConsulta: <?= (int) $diasConsulta ?>
    }).then(function(ok) {
        if (!ok || ESTADO_RESUMO.consultando) {
            return;
        }
        atualizarIndicadorCache('Cache local atualizado', 'cache-local');
    });
}
function adicionarResultado(servidor, endereco, porta, database, quantidade, classeLinha, indiceServidor, diasConsulta) {
    var linha = {
        servidor: servidor,
        endereco: endereco,
        porta: porta,
        database: database,
        quantidade: quantidade,
        classeLinha: classeLinha,
        indiceServidor: indiceServidor,
        diasConsulta: diasConsulta
    };
    var chave = obterChaveLinhaResumo(linha);
    var indiceExistente = -1;
    for (var i = 0; i < ESTADO_RESUMO.linhas.length; i++) {
        if (obterChaveLinhaResumo(ESTADO_RESUMO.linhas[i]) === chave) {
            indiceExistente = i;
            break;
        }
    }
    if (indiceExistente >= 0) {
        ESTADO_RESUMO.linhas[indiceExistente] = linha;
    } else {
        ESTADO_RESUMO.linhas.push(linha);
    }
    renderizarTabelaResumo();
    atualizarIndicadorCache('Resultados online + cache local', 'cache-online');
    persistirResumoDebounce();
}

function atualizarLinhaResumoPorEvento(payload) {
    if (!payload || String(payload.tipo || '') !== 'quantidade_detalhes_atualizada') {
        return;
    }
    if (Number(payload.diasConsulta || 0) !== <?= (int) $diasConsulta ?>) {
        return;
    }
    if (!databaseCombinaComFiltroAtual(payload.database || '')) {
        return;
    }
    var linha = {
        servidor: String(payload.servidor || ''),
        endereco: String(payload.endereco || ''),
        porta: String(payload.porta || ''),
        database: String(payload.database || ''),
        quantidade: String(payload.quantidade || '0'),
        classeLinha: Number(payload.quantidade || 0) > 100 ? 'linha-vermelha' : 'linha-verde',
        indiceServidor: String(payload.indiceServidor || ''),
        diasConsulta: String(payload.diasConsulta || '')
    };
    var chave = obterChaveLinhaResumo(linha);
    var indiceExistente = -1;
    for (var i = 0; i < ESTADO_RESUMO.linhas.length; i++) {
        if (obterChaveLinhaResumo(ESTADO_RESUMO.linhas[i]) === chave) {
            indiceExistente = i;
            break;
        }
    }
    var quantidade = Number(payload.quantidade || 0);
    if (quantidade > 0) {
        if (indiceExistente >= 0) {
            ESTADO_RESUMO.linhas[indiceExistente] = linha;
        } else {
            ESTADO_RESUMO.linhas.push(linha);
        }
    } else if (indiceExistente >= 0) {
        ESTADO_RESUMO.linhas.splice(indiceExistente, 1);
    }
    recalcularResumoPorLinhas();
    renderizarTabelaResumo();
    persistirResumoDebounce();
    atualizarStatus('Resumo atualizado automaticamente a partir dos detalhes para ' + String(payload.database || '') + '.');
    atualizarIndicadorCache('Resumo sincronizado dos detalhes', 'cache-local');
}
function iniciarEscutaAtualizacaoResumo() {
    if (typeof window.BroadcastChannel === 'function') {
        try {
            canalResumoAtualizacao = new BroadcastChannel(CANAL_ATUALIZACAO_RESUMO);
            canalResumoAtualizacao.onmessage = function(evento) {
                if (evento && evento.data) {
                    atualizarLinhaResumoPorEvento(evento.data);
                }
            };
        } catch (erro) {}
    }
    window.addEventListener('storage', function(evento) {
        if (!evento || evento.key !== STORAGE_ATUALIZACAO_RESUMO || !evento.newValue) {
            return;
        }
        try {
            atualizarLinhaResumoPorEvento(JSON.parse(evento.newValue));
        } catch (erro) {}
    });
}
function carregarResumoDoCache() {
    if (ESTADO_RESUMO.consultando) {
        return;
    }
    lerRegistro('resumo', ESTADO_RESUMO.cacheKey).then(function(registro) {
        if (ESTADO_RESUMO.recebeuOnline || ESTADO_RESUMO.consultando) {
            return;
        }
        if (!registro) {
            atualizarIndicadorCache('Sem cache local para este filtro', 'cache-empty');
            return;
        }
        ESTADO_RESUMO.linhas = Array.isArray(registro.linhas) ? registro.linhas : [];
        if (registro.resumo) {
            ESTADO_RESUMO.resumo.bases = Number(registro.resumo.bases || 0);
            ESTADO_RESUMO.resumo.ok = Number(registro.resumo.ok || 0);
            ESTADO_RESUMO.resumo.erro = Number(registro.resumo.erro || 0);
            ESTADO_RESUMO.resumo.total = Number(registro.resumo.total || 0);
            ESTADO_RESUMO.resumo.fim = registro.resumo.fim || '-';
            var mapa = {
                totalBasesComNotas: ESTADO_RESUMO.resumo.bases,
                totalConsultasOk: ESTADO_RESUMO.resumo.ok,
                totalConsultasErro: ESTADO_RESUMO.resumo.erro,
                totalQuantidadeNotas: ESTADO_RESUMO.resumo.total,
                fimExecucao: ESTADO_RESUMO.resumo.fim
            };
            Object.keys(mapa).forEach(function(id) {
                var el = document.getElementById(id);
                if (el) {
                    el.textContent = mapa[id];
                }
            });
        }
        renderizarTabelaResumo();
        if (ESTADO_RESUMO.linhas.length > 0) {
            atualizarStatus('Exibindo último resultado salvo localmente para este filtro.');
            atualizarIndicadorCache('Exibindo cache local', 'cache-local');
        } else {
            atualizarIndicadorCache('Cache local sem linhas para este filtro', 'cache-empty');
        }
    });
}
window.addEventListener('DOMContentLoaded', function() {
    ESTADO_RESUMO.cacheKey = obterChaveResumoLocal();
    ESTADO_RESUMO.consultando = <?= $executarConsulta ? 'true' : 'false' ?>;
    iniciarEscutaAtualizacaoResumo();
    if (ESTADO_RESUMO.consultando) {
        atualizarStatus('Atualizando consulta online para este filtro...');
        atualizarIndicadorCache('Consulta online em andamento', 'cache-online');
    } else {
        carregarResumoDoCache();
    }
});
</script>
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <div class="title-row">
            <div class="title-block">
                <h1>Consulta de Notas Fiscais</h1>
                <p>Painel rápido para localizar bases com notas em situação irregular.</p>
            </div>
            <div class="tag-row">
                <span class="chip"><span>Início</span> <strong><?= htmlspecialchars(date('d/m/Y H:i:s'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                <span class="chip"><span>Filtro</span> <strong><?= htmlspecialchars($filtroDatabase !== '' ? $filtroDatabase : '(todos)', ENT_QUOTES, 'UTF-8') ?></strong></span>
                <span class="chip live"><span>Dias</span> <strong><?= (int) $diasConsulta ?></strong></span>
            </div>
        </div>

        <div class="toolbar-grid">
            <section class="panel panel-form">
                <form method="get" action="" onsubmit="mostrarOverlayProcessando('Consultando bancos, aguarde...')">
                    <input type="hidden" name="consultar" value="1">
                    <div class="field">
                        <label for="database">Database (like)</label>
                        <input type="text" id="database" name="database" value="<?= htmlspecialchars($filtroDatabase, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                    </div>
                    <div class="field">
                        <label for="dias">Dias</label>
                        <input type="number" id="dias" name="dias" min="1" value="<?= (int) $diasConsulta ?>">
                    </div>
                    <div>
                        <button class="botao" type="submit">Consultar</button>
                    </div>
                </form>
            </section>

            <section class="telemetry">
                <div class="panel metric ok">
                    <div class="label">Bases com notas</div>
                    <div class="value" id="totalBasesComNotas">0</div>
                    <div class="hint">Bases com quantidade maior que zero</div>
                </div>
                <div class="panel metric">
                    <div class="label">Consultas ok</div>
                    <div class="value" id="totalConsultasOk">0</div>
                    <div class="hint">Consultas concluídas com sucesso</div>
                </div>
                <div class="panel metric danger">
                    <div class="label">Consultas com erro</div>
                    <div class="value" id="totalConsultasErro">0</div>
                    <div class="hint">Conexão, tabela ou consulta falhada</div>
                </div>
                <div class="panel metric warn">
                    <div class="label">Total de notas</div>
                    <div class="value" id="totalQuantidadeNotas">0</div>
                    <div class="hint">Soma geral das bases exibidas</div>
                </div>
                <div class="panel metric">
                    <div class="label">Fim</div>
                    <div class="value" id="fimExecucao">-</div>
                    <div class="hint">Horário da última finalização</div>
                </div>
            </section>
        </div>

        <div class="status-bar">
            <span class="status-dot"></span>
            <div class="status-text" id="statusAtual"><?= htmlspecialchars((string) $textoStatusInicial, ENT_QUOTES, 'UTF-8') ?></div>
            <span class="cache-pill" id="origemDados">Preparando cache local</span>
        </div>
    </header>

    <main class="content-shell">
        <section class="surface">
            <div class="surface-head">
                <div>
                    <div class="surface-title">Bases com inconsistências</div>
                    <div class="surface-subtitle">Clique na quantidade para abrir os detalhes em uma nova aba.</div>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Servidor</th>
                            <th>Endereço</th>
                            <th>Porta</th>
                            <th>Database</th>
                            <th>Quantidade</th>
                        </tr>
                    </thead>
                    <tbody id="corpoTabela"></tbody>
                </table>
            </div>
            <div class="empty-state" id="mensagemVazia"><?= htmlspecialchars($executarConsulta ? 'Nenhum resultado encontrado até o momento.' : 'Preencha os filtros desejados e clique em Consultar.', ENT_QUOTES, 'UTF-8') ?></div>
        </section>
    </main>
</div>

<div class="overlay-processando" id="overlayProcessando">
    <div class="overlay-box">
        <strong>Consultando bancos</strong>
        <span id="textoOverlayProcessando">Aguarde...</span>
    </div>
</div>
<?php if ($executarConsulta): ?>
<script>mostrarOverlayProcessando('Consultando bancos, aguarde...');</script>
<?php endif; ?>
<?php

enviarSaida();

if (!$executarConsulta) {
    ?>
</body>
</html>
<?php
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
        echo 'marcarAtualizacaoOnline();';
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
    $conexao = criarConexaoMysql($endereco, $mysqlUsuario, $mysqlSenha, $porta);

    if ($conexao === false) {
        $totalConsultasErro++;
        $erroConexao = mysqli_connect_error();
        if (!is_string($erroConexao) || $erroConexao === '') {
            $erroConexao = 'falha ao conectar no servidor MySQL';
        }
        echo '<script>';
        echo 'atualizarStatus(' . jsTexto('Erro ao conectar em ' . $identificacao . ': ' . $erroConexao) . ');';
        echo 'marcarAtualizacaoOnline();';
            echo 'atualizarResumo(' . $totalBasesComNotas . ',' . $totalConsultasOk . ',' . $totalConsultasErro . ',' . $totalQuantidadeNotas . ', null);';
        echo '</script>';
        enviarSaida();
        continue;
    }

    $bancos = listarBancos($conexao, $filtroDatabase);

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
            echo 'marcarAtualizacaoOnline();';
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
            echo 'marcarAtualizacaoOnline();';
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
            echo 'marcarAtualizacaoOnline();';
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
            echo 'marcarAtualizacaoOnline();';
            echo 'atualizarResumo(' . $totalBasesComNotas . ',' . $totalConsultasOk . ',' . $totalConsultasErro . ',' . $totalQuantidadeNotas . ', null);';
            echo '</script>';
            enviarSaida();
        } else {
            echo '<script>';
            echo 'marcarAtualizacaoOnline();';
            echo 'atualizarResumo(' . $totalBasesComNotas . ',' . $totalConsultasOk . ',' . $totalConsultasErro . ',' . $totalQuantidadeNotas . ', null);';
            echo '</script>';
            enviarSaida();
        }
    }

    $conexao->close();
}

error_log('CONSULTAR_NOTAS finalizada bases=' . $totalBasesComNotas . ' ok=' . $totalConsultasOk . ' erro=' . $totalConsultasErro . ' total=' . $totalQuantidadeNotas);

echo '<script>';
echo 'ESTADO_RESUMO.consultando = false;';
echo 'marcarAtualizacaoOnline();';
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


echo '</body>';
echo '</html>';
