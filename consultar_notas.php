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
    echo '<div class="erro-wrap">';
    echo '<div class="erro-box">ERRO: ' . htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '</div>';
    echo '</body>';
    echo '</html>';
    exit;
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

$servidoresFront = [];
foreach ($configuracao['servidores'] as $indiceServidor => $servidor) {
    if (!is_array($servidor)) {
        continue;
    }

    $servidoresFront[] = [
        'indiceServidor' => (string)$indiceServidor,
        'identificacao' => isset($servidor['identificacao']) ? trim((string)$servidor['identificacao']) : '',
        'endereco' => isset($servidor['endereco']) ? trim((string)$servidor['endereco']) : '',
        'porta' => isset($servidor['porta']) ? (string)((int)$servidor['porta']) : '3306',
    ];
}

$textoStatusInicial = $executarConsulta
    ? 'Preparando consulta concorrente...'
    : 'Preencha os filtros desejados e clique em Consultar.';

error_log('CONSULTAR_NOTAS pagina iniciada. executar=' . ($executarConsulta ? '1' : '0') . ' database=' . $filtroDatabase . ' dias=' . $diasConsulta);

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
.botao:disabled {
    cursor: wait;
    opacity: 0.7;
}
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
    recebeuOnline: false,
    consulta: {
        totalServidores: 0,
        servidoresFinalizados: 0,
        bancosDescobertos: 0,
        bancosProcessados: 0,
        bancosIgnorados: 0,
        ativos: 0,
        fila: 0,
        finalizado: false,
        iniciadaEm: null
    }
};
var FILTRO_DATABASE_RESUMO = <?= json_encode(strtolower($filtroDatabase), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || '';
var CANAL_ATUALIZACAO_RESUMO = 'consulta_erros_nfe_resumo';
var STORAGE_ATUALIZACAO_RESUMO = 'consulta_erros_nfe_resumo_update';
var canalResumoAtualizacao = null;
var DB_UI_NFE = 'consulta_erros_nfe_ui';
var DB_VERSAO_UI_NFE = 2;
var dbUiPromise = null;
var EXECUTAR_CONSULTA_INICIAL = <?= $executarConsulta ? 'true' : 'false' ?>;
var DIAS_CONSULTA_ATUAL = <?= (int) $diasConsulta ?>;
var LIMITE_CONCORRENCIA_BANCOS = 4;
var LIMITE_CONCORRENCIA_SERVIDORES = 2;
var URL_WORKER_RESUMO = 'consultar_notas_worker.php';
var SERVIDORES_RESUMO = <?= json_encode($servidoresFront, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
function obterChaveResumoLocal() {
    var filtro = <?= json_encode($filtroDatabase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || '';
    return 'resumo::' + window.location.pathname + '::' + filtro.toLowerCase() + '::' + DIAS_CONSULTA_ATUAL;
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
function compararTextoOrdenacao(a, b) {
    var textoA = String(a == null ? '' : a).toLowerCase();
    var textoB = String(b == null ? '' : b).toLowerCase();
    return textoA.localeCompare(textoB, 'pt-BR', { numeric: true, sensitivity: 'base' });
}
function ordenarLinhasResumo() {
    ESTADO_RESUMO.linhas.sort(function(a, b) {
        var comparacao = compararTextoOrdenacao(a.database, b.database);
        if (comparacao !== 0) {
            return comparacao;
        }
        return compararTextoOrdenacao(a.servidor, b.servidor);
    });
}
function renderizarTabelaResumo() {
    var corpo = document.getElementById('corpoTabela');
    if (!corpo) { return; }
    if (!ESTADO_RESUMO.linhas.length) {
        corpo.innerHTML = '';
        atualizarMensagemVazia();
        return;
    }
    ordenarLinhasResumo();
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
        diasConsulta: DIAS_CONSULTA_ATUAL
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
function removerResultado(indiceServidor, database, porta) {
    var chave = [String(indiceServidor || ''), String(database || '').toLowerCase(), String(porta || '')].join('::');
    for (var i = 0; i < ESTADO_RESUMO.linhas.length; i++) {
        if (obterChaveLinhaResumo(ESTADO_RESUMO.linhas[i]) === chave) {
            ESTADO_RESUMO.linhas.splice(i, 1);
            break;
        }
    }
    renderizarTabelaResumo();
    persistirResumoDebounce();
}
function atualizarLinhaResumoPorEvento(payload) {
    if (!payload || String(payload.tipo || '') !== 'quantidade_detalhes_atualizada') {
        return;
    }
    if (Number(payload.diasConsulta || 0) !== DIAS_CONSULTA_ATUAL) {
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
            aplicarResumoNaTela();
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
function formatarHorarioAgora() {
    var agora = new Date();
    var pad = function(valor) { return String(valor).padStart(2, '0'); };
    return pad(agora.getDate()) + '/' + pad(agora.getMonth() + 1) + '/' + agora.getFullYear() + ' ' + pad(agora.getHours()) + ':' + pad(agora.getMinutes()) + ':' + pad(agora.getSeconds());
}
function atualizarStatusConsulta() {
    var c = ESTADO_RESUMO.consulta;
    if (!ESTADO_RESUMO.consultando && !c.finalizado) {
        return;
    }
    if (c.finalizado) {
        atualizarStatus('Consulta finalizada. Servidores: ' + c.servidoresFinalizados + '/' + c.totalServidores + ' | Bancos processados: ' + c.bancosProcessados + '/' + c.bancosDescobertos + ' | Ignorados: ' + c.bancosIgnorados + '.');
        return;
    }
    atualizarStatus(
        'Consultando sem travar a tela. ' +
        'Servidores: ' + c.servidoresFinalizados + '/' + c.totalServidores +
        ' | Bancos descobertos: ' + c.bancosDescobertos +
        ' | Processados: ' + c.bancosProcessados +
        ' | Em execução: ' + c.ativos +
        ' | Na fila: ' + c.fila +
        ' | Ignorados: ' + c.bancosIgnorados
    );
}
function reiniciarConsultaNaTela() {
    ESTADO_RESUMO.consultando = true;
    ESTADO_RESUMO.recebeuOnline = false;
    ESTADO_RESUMO.linhas = [];
    ESTADO_RESUMO.resumo = {
        bases: 0,
        ok: 0,
        erro: 0,
        total: 0,
        fim: '-'
    };
    ESTADO_RESUMO.consulta = {
        totalServidores: SERVIDORES_RESUMO.length,
        servidoresFinalizados: 0,
        bancosDescobertos: 0,
        bancosProcessados: 0,
        bancosIgnorados: 0,
        ativos: 0,
        fila: 0,
        finalizado: false,
        iniciadaEm: new Date().toISOString()
    };
    aplicarResumoNaTela();
    renderizarTabelaResumo();
    atualizarMensagemVazia();
    atualizarIndicadorCache('Consulta online em andamento', 'cache-online');
    atualizarStatusConsulta();
    var botao = document.getElementById('botaoConsultar');
    if (botao) {
        botao.disabled = true;
    }
}
function finalizarConsultaOnline() {
    ESTADO_RESUMO.consultando = false;
    ESTADO_RESUMO.consulta.finalizado = true;
    ESTADO_RESUMO.resumo.fim = formatarHorarioAgora();
    aplicarResumoNaTela();
    atualizarStatusConsulta();
    atualizarIndicadorCache('Resultados online + cache local', 'cache-online');
    persistirResumoAgora();
    var botao = document.getElementById('botaoConsultar');
    if (botao) {
        botao.disabled = false;
    }
}
async function requisitarJson(url) {
    var resposta = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        },
        cache: 'no-store'
    });

    var texto = await resposta.text();
    var payload = null;
    try {
        payload = texto ? JSON.parse(texto) : null;
    } catch (erro) {
        payload = {
            ok: false,
            erro: 'Resposta inválida do worker.',
            detalhe: texto
        };
    }

    if (!resposta.ok && payload && typeof payload.ok === 'undefined') {
        payload.ok = false;
    }

    return payload || {
        ok: false,
        erro: 'Resposta vazia do worker.'
    };
}
async function executarFilaComLimite(lista, limite, worker) {
    var indice = 0;
    async function consumir() {
        while (true) {
            var atual = indice;
            indice += 1;
            if (atual >= lista.length) {
                return;
            }
            await worker(lista[atual], atual);
        }
    }
    var tarefas = [];
    var quantidade = Math.max(1, Math.min(limite, lista.length || 1));
    for (var i = 0; i < quantidade; i++) {
        tarefas.push(consumir());
    }
    await Promise.all(tarefas);
}
function montarUrlWorker(params) {
    var qs = new URLSearchParams(params);
    return URL_WORKER_RESUMO + '?' + qs.toString();
}
async function listarBancosServidor(servidor) {
    var url = montarUrlWorker({
        acao: 'listar_bancos',
        s: servidor.indiceServidor,
        database: <?= json_encode($filtroDatabase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    });
    var payload = await requisitarJson(url);
    if (!payload || payload.ok !== true) {
        ESTADO_RESUMO.resumo.erro += Number(payload && payload.contabilizar_erro ? payload.contabilizar_erro : 1);
        aplicarResumoNaTela();
        marcarAtualizacaoOnline();
        atualizarIndicadorCache('Consulta online com falhas', 'cache-online');
        return [];
    }
    var bancos = Array.isArray(payload.bancos) ? payload.bancos : [];
    return bancos.map(function(database) {
        return {
            indiceServidor: String(payload.indiceServidor || servidor.indiceServidor || ''),
            servidor: String(payload.servidor || servidor.identificacao || ''),
            endereco: String(payload.endereco || servidor.endereco || ''),
            porta: String(payload.porta || servidor.porta || ''),
            database: String(database || '')
        };
    });
}
async function consultarBancoFila(tarefa) {
    ESTADO_RESUMO.consulta.ativos += 1;
    ESTADO_RESUMO.consulta.fila = Math.max(0, ESTADO_RESUMO.consulta.fila - 1);
    atualizarStatusConsulta();

    var url = montarUrlWorker({
        acao: 'consultar_banco',
        s: tarefa.indiceServidor,
        db: tarefa.database,
        dias: DIAS_CONSULTA_ATUAL
    });

    try {
        var payload = await requisitarJson(url);
        if (payload && payload.ok === true) {
            if (payload.ignorado) {
                ESTADO_RESUMO.consulta.bancosIgnorados += 1;
                removerResultado(payload.indiceServidor, payload.database, payload.porta);
            } else {
                ESTADO_RESUMO.resumo.ok += Number(payload.contabilizar_ok || 0);
                if (Number(payload.quantidade || 0) > 0) {
                    adicionarResultado(
                        String(payload.servidor || tarefa.servidor || ''),
                        String(payload.endereco || tarefa.endereco || ''),
                        String(payload.porta || tarefa.porta || ''),
                        String(payload.database || tarefa.database || ''),
                        String(payload.quantidade || '0'),
                        String(payload.classeLinha || 'linha-verde'),
                        String(payload.indiceServidor || tarefa.indiceServidor || ''),
                        String(payload.diasConsulta || DIAS_CONSULTA_ATUAL)
                    );
                } else {
                    removerResultado(payload.indiceServidor, payload.database, payload.porta);
                }
            }
            recalcularResumoPorLinhas();
            aplicarResumoNaTela();
            marcarAtualizacaoOnline();
            atualizarIndicadorCache('Resultados online + cache local', 'cache-online');
        } else {
            ESTADO_RESUMO.resumo.erro += Number(payload && payload.contabilizar_erro ? payload.contabilizar_erro : 1);
            aplicarResumoNaTela();
            marcarAtualizacaoOnline();
            atualizarIndicadorCache('Consulta online com falhas', 'cache-online');
        }
    } catch (erro) {
        ESTADO_RESUMO.resumo.erro += 1;
        aplicarResumoNaTela();
        marcarAtualizacaoOnline();
        atualizarIndicadorCache('Consulta online com falhas', 'cache-online');
    } finally {
        ESTADO_RESUMO.consulta.ativos = Math.max(0, ESTADO_RESUMO.consulta.ativos - 1);
        ESTADO_RESUMO.consulta.bancosProcessados += 1;
        atualizarStatusConsulta();
        persistirResumoDebounce();
    }
}
async function iniciarConsultaOnline() {
    reiniciarConsultaNaTela();

    var tarefasBancos = [];
    await executarFilaComLimite(SERVIDORES_RESUMO, LIMITE_CONCORRENCIA_SERVIDORES, async function(servidor) {
        var bancos = await listarBancosServidor(servidor);
        if (bancos.length) {
            for (var i = 0; i < bancos.length; i++) {
                tarefasBancos.push(bancos[i]);
            }
            ESTADO_RESUMO.consulta.bancosDescobertos += bancos.length;
            ESTADO_RESUMO.consulta.fila = tarefasBancos.length;
        }
        ESTADO_RESUMO.consulta.servidoresFinalizados += 1;
        atualizarStatusConsulta();
        persistirResumoDebounce();
    });

    if (tarefasBancos.length > 0) {
        await executarFilaComLimite(tarefasBancos, LIMITE_CONCORRENCIA_BANCOS, async function(tarefa) {
            await consultarBancoFila(tarefa);
        });
    }

    finalizarConsultaOnline();
}
window.addEventListener('DOMContentLoaded', function() {
    ESTADO_RESUMO.cacheKey = obterChaveResumoLocal();
    ESTADO_RESUMO.consultando = EXECUTAR_CONSULTA_INICIAL;
    iniciarEscutaAtualizacaoResumo();
    if (EXECUTAR_CONSULTA_INICIAL) {
        iniciarConsultaOnline();
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
                <form method="get" action="">
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
                        <button class="botao" id="botaoConsultar" type="submit">Consultar</button>
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
</body>
</html>
