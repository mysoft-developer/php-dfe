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
$arquivoServidores = __DIR__ . '/servidores.json';
$arquivoLogPhp = __DIR__ . '/consultar_notas_servidores.log';
ini_set('log_errors', '1');
ini_set('error_log', $arquivoLogPhp);

function sairComErro(string $mensagem): void
{
    echo '<!doctype html>';
    echo '<html lang="pt-BR">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Consulta de Notas por Servidores</title>';
    echo '<style>';
    echo 'html, body { height:100%; margin:0; }';
    echo 'body { background:#eef4fb; color:#17324d; font-family:Arial,Helvetica,sans-serif; }';
    echo '.erro-wrap { padding:20px; }';
    echo '.erro-box { padding:12px; background:#fff1f1; border:1px solid #e39a9a; color:#a63f3f; font-weight:bold; }';
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

function lerJsonArquivo(string $arquivo, string $rotulo): array
{
    if (!file_exists($arquivo)) {
        sairComErro('Arquivo ' . $rotulo . ' não encontrado em: ' . $arquivo);
    }

    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        sairComErro('Não foi possível ler o ' . $rotulo . '.');
    }

    $dados = json_decode($conteudo, true);
    if (!is_array($dados)) {
        sairComErro('JSON inválido no ' . $rotulo . '.');
    }

    return $dados;
}

function localizarIndiceServidorConfig(array $servidoresConfig, string $endereco, int $porta): int
{
    foreach ($servidoresConfig as $indice => $servidor) {
        if (!is_array($servidor)) {
            continue;
        }

        $enderecoAtual = isset($servidor['endereco']) ? trim((string) $servidor['endereco']) : '';
        $portaAtual = isset($servidor['porta']) ? (int) $servidor['porta'] : 3306;
        if ($enderecoAtual === $endereco && $portaAtual === $porta) {
            return (int) $indice;
        }
    }

    return -1;
}

function normalizarTextoFiltro(string $texto): string
{
    $texto = trim($texto);
    if ($texto === '') {
        return '';
    }

    return function_exists('mb_strtolower')
        ? mb_strtolower($texto, 'UTF-8')
        : strtolower($texto);
}

function obterNomeSimplificadoFiltro(string $nome): string
{
    $nome = trim($nome);
    if ($nome === '') {
        return '';
    }

    $posicao = strpos($nome, '_');
    if ($posicao !== false) {
        $nome = substr($nome, $posicao + 1);
    }

    return $nome;
}

$configuracao = lerJsonArquivo($arquivoConfiguracao, 'config.json');
$servidoresJson = lerJsonArquivo($arquivoServidores, 'servidores.json');

if (empty($configuracao['mysql_usuario']) || !array_key_exists('mysql_senha', $configuracao)) {
    sairComErro('Configuração incompleta. Verifique mysql_usuario e mysql_senha.');
}

if (empty($servidoresJson['servidores']) || !is_array($servidoresJson['servidores'])) {
    sairComErro('Nenhum servidor encontrado no servidores.json.');
}

$diasConsulta = isset($_GET['dias']) ? (int) $_GET['dias'] : 7;
if ($diasConsulta <= 0) {
    $diasConsulta = 7;
}
$filtroCliente = isset($_GET['filtro_cliente']) ? trim((string) $_GET['filtro_cliente']) : '';
$filtroTrecho = isset($_GET['filtro_trecho']) ? trim((string) $_GET['filtro_trecho']) : '';
$filtroClienteNormalizado = normalizarTextoFiltro($filtroCliente);
$filtroTrechoNormalizado = normalizarTextoFiltro($filtroTrecho);
$executarConsulta = isset($_GET['consultar']) && $_GET['consultar'] === '1';

$servidoresConfig = isset($configuracao['servidores']) && is_array($configuracao['servidores'])
    ? $configuracao['servidores']
    : [];

$servidoresFront = [];
foreach ($servidoresJson['servidores'] as $indiceItem => $item) {
    if (!is_array($item)) {
        continue;
    }

    $nome = isset($item['nome']) ? trim((string) $item['nome']) : '';
    $grupo = isset($item['grupo']) ? trim((string) $item['grupo']) : '';
    $endereco = isset($item['endereco']) ? trim((string) $item['endereco']) : '';
    $porta = isset($item['porta']) ? (int) $item['porta'] : 3306;
    $database = isset($item['database']) ? trim((string) $item['database']) : '';

    if ($nome === '' || $endereco === '' || $database === '') {
        continue;
    }

    $nomeSimplificado = obterNomeSimplificadoFiltro($nome);
    $nomeSimplificadoNormalizado = normalizarTextoFiltro($nomeSimplificado);
    $nomeNormalizado = normalizarTextoFiltro($nome);

    if ($filtroClienteNormalizado !== '' && $nomeSimplificadoNormalizado !== $filtroClienteNormalizado) {
        continue;
    }

    if ($filtroTrechoNormalizado !== '' && strpos($nomeNormalizado, $filtroTrechoNormalizado) === false) {
        continue;
    }

    $servidoresFront[] = [
        'indiceItem' => (string) $indiceItem,
        'indiceServidor' => (string) localizarIndiceServidorConfig($servidoresConfig, $endereco, $porta),
        'nome' => $nome,
        'grupo' => $grupo,
        'endereco' => $endereco,
        'porta' => (string) $porta,
        'database' => $database,
    ];
}

$partesFiltroStatus = [];
if ($filtroCliente !== '') {
    $partesFiltroStatus[] = 'cliente: ' . $filtroCliente;
}
if ($filtroTrecho !== '') {
    $partesFiltroStatus[] = 'trecho: ' . $filtroTrecho;
}
$descricaoFiltroStatus = count($partesFiltroStatus) > 0
    ? ' com filtros ' . implode(' | ', $partesFiltroStatus)
    : '';

$textoStatusInicial = $executarConsulta
    ? 'Preparando consulta concorrente pelo servidores.json com até 15 consultas simultâneas' . $descricaoFiltroStatus . '...'
    : 'Clique em Consultar para ler o servidores.json e verificar as quantidades.';

error_log(
    'CONSULTAR_NOTAS_SERVIDORES pagina iniciada. executar=' . ($executarConsulta ? '1' : '0') .
    ' dias=' . $diasConsulta .
    ' filtro_cliente=' . $filtroCliente .
    ' filtro_trecho=' . $filtroTrecho .
    ' total_filtrado=' . count($servidoresFront)
);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Consulta de Notas por Servidores</title>
<link rel="stylesheet" href="consultar_notas_servidores.css">
<style>
.panel-form form {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) 120px auto auto;
}
.botao-secundario {
    background: linear-gradient(180deg, rgba(240, 246, 255, 0.98), rgba(226, 236, 249, 0.98));
    color: #1f3e63;
    border-color: rgba(86, 111, 145, 0.28);
}
@media (max-width: 760px) {
    .panel-form form {
        grid-template-columns: 1fr;
    }
}
</style>
<script>
var SERVIDORES_LISTA = <?= json_encode($servidoresFront, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var EXECUTAR_CONSULTA_INICIAL = <?= $executarConsulta ? 'true' : 'false' ?>;
var DIAS_CONSULTA_ATUAL = <?= (int) $diasConsulta ?>;
var URL_WORKER = 'consultar_notas_servidores_worker.php';
var LIMITE_CONCORRENCIA = 15;
var ESTADO = {
    linhas: [],
    resumo: {
        bases: 0,
        ok: 0,
        erro: 0,
        total: 0,
        fim: '-'
    },
    consultando: false,
    totalItens: SERVIDORES_LISTA.length,
    processados: 0
};

function escaparHtml(texto) {
    return String(texto == null ? '' : texto)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function atualizarStatus(texto) {
    var el = document.getElementById('statusAtual');
    if (el) {
        el.textContent = texto;
    }
}

function limparFiltros() {
    var campoCliente = document.getElementById('filtro_cliente');
    var campoTrecho = document.getElementById('filtro_trecho');
    if (campoCliente) {
        campoCliente.value = '';
    }
    if (campoTrecho) {
        campoTrecho.value = '';
    }
}

function atualizarIndicadorCache(texto, classe) {
    var badge = document.getElementById('origemDados');
    if (!badge) {
        return;
    }
    badge.className = 'cache-pill ' + (classe || '');
    badge.textContent = texto;
}

function atualizarIndicadorFim() {
    var boxCarregando = document.getElementById('fimExecucaoCarregando');
    var textoFim = document.getElementById('fimExecucao');
    if (!boxCarregando || !textoFim) {
        return;
    }
    if (ESTADO.consultando) {
        boxCarregando.classList.add('ativo');
        textoFim.classList.add('oculto');
    } else {
        boxCarregando.classList.remove('ativo');
        textoFim.classList.remove('oculto');
    }
}

function aplicarResumoNaTela() {
    var mapa = {
        totalBasesComNotas: ESTADO.resumo.bases,
        totalConsultasOk: ESTADO.resumo.ok,
        totalConsultasErro: ESTADO.resumo.erro,
        totalQuantidadeNotas: ESTADO.resumo.total,
        fimExecucao: ESTADO.resumo.fim
    };
    Object.keys(mapa).forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = mapa[id];
        }
    });
    atualizarIndicadorFim();
}

function compararTexto(a, b) {
    return String(a == null ? '' : a).toLowerCase().localeCompare(
        String(b == null ? '' : b).toLowerCase(),
        'pt-BR',
        { numeric: true, sensitivity: 'base' }
    );
}

function ordenarLinhas() {
    ESTADO.linhas.sort(function(a, b) {
        var grupo = compararTexto(a.grupo, b.grupo);
        if (grupo !== 0) {
            return grupo;
        }
        var nome = compararTexto(a.nome, b.nome);
        if (nome !== 0) {
            return nome;
        }
        return compararTexto(a.database, b.database);
    });
}

function montarLinkQuantidade(linha) {
    var url = 'consultar_notas_detalhes.php?host=' + encodeURIComponent(linha.endereco || '') +
        '&porta=' + encodeURIComponent(linha.porta || '') +
        '&nome=' + encodeURIComponent(linha.nome || '') +
        '&db=' + encodeURIComponent(linha.database || '') +
        '&dias=' + encodeURIComponent(DIAS_CONSULTA_ATUAL) +
        '&limpar_filtro=1';
    return '<a class="link-quantidade" href="' + escaparHtml(url) + '" target="_blank" rel="noopener noreferrer" onclick="window.open(this.href, \"_blank\", \"noopener,noreferrer\"); return false;">' + escaparHtml(linha.quantidade) + '</a>';
}

function htmlLinha(linha) {
    return '<tr class="' + escaparHtml(linha.classeLinha || '') + '">' +
        '<td>' + escaparHtml(linha.nome) + '</td>' +
        '<td>' + escaparHtml(linha.endereco) + '</td>' +
        '<td>' + escaparHtml(linha.porta) + '</td>' +
        '<td>' + escaparHtml(linha.database) + '</td>' +
        '<td class="col-quantidade">' + montarLinkQuantidade(linha) + '</td>' +
        '</tr>';
}

function atualizarMensagemVazia() {
    var box = document.getElementById('mensagemVazia');
    if (!box) {
        return;
    }
    box.style.display = ESTADO.linhas.length > 0 ? 'none' : 'flex';
}

function renderizarTabela() {
    var corpo = document.getElementById('corpoTabela');
    if (!corpo) {
        return;
    }
    if (!ESTADO.linhas.length) {
        corpo.innerHTML = '';
        atualizarMensagemVazia();
        return;
    }
    ordenarLinhas();
    var html = '';
    for (var i = 0; i < ESTADO.linhas.length; i++) {
        html += htmlLinha(ESTADO.linhas[i]);
    }
    corpo.innerHTML = html;
    atualizarMensagemVazia();
}

function recalcularResumoPorLinhas() {
    var total = 0;
    var bases = 0;
    for (var i = 0; i < ESTADO.linhas.length; i++) {
        var quantidade = String(ESTADO.linhas[i].quantidade == null ? '' : ESTADO.linhas[i].quantidade);
        if (/^\d+$/.test(quantidade)) {
            var valor = Number(quantidade || 0);
            total += valor;
            if (valor > 0) {
                bases += 1;
            }
        }
    }
    ESTADO.resumo.bases = bases;
    ESTADO.resumo.total = total;
    aplicarResumoNaTela();
}

function formatarHorarioAgora() {
    var agora = new Date();
    var pad = function(valor) { return String(valor).padStart(2, '0'); };
    return pad(agora.getDate()) + '/' + pad(agora.getMonth() + 1) + '/' + agora.getFullYear() + ' ' + pad(agora.getHours()) + ':' + pad(agora.getMinutes()) + ':' + pad(agora.getSeconds());
}

function montarLinhasIniciais() {
    ESTADO.linhas = [];
    for (var i = 0; i < SERVIDORES_LISTA.length; i++) {
        var item = SERVIDORES_LISTA[i];
        ESTADO.linhas.push({
            indiceItem: String(item.indiceItem || ''),
            grupo: String(item.grupo || ''),
            nome: String(item.nome || ''),
            endereco: String(item.endereco || ''),
            porta: String(item.porta || ''),
            database: String(item.database || ''),
            quantidade: '...',
            indiceServidor: String(item.indiceServidor || ''),
            classeLinha: 'linha-verde'
        });
    }
}

function reiniciarConsulta() {
    montarLinhasIniciais();
    ESTADO.resumo = { bases: 0, ok: 0, erro: 0, total: 0, fim: '-' };
    ESTADO.consultando = true;
    ESTADO.processados = 0;
    ESTADO.totalItens = SERVIDORES_LISTA.length;
    aplicarResumoNaTela();
    renderizarTabela();
    atualizarIndicadorCache('Consulta online em andamento', 'cache-online');
    atualizarStatus('Consultando ' + ESTADO.totalItens + ' item(ns) do servidores.json com até 15 consultas simultâneas...');
    var botao = document.getElementById('botaoConsultar');
    if (botao) {
        botao.disabled = true;
    }
}

function finalizarConsulta() {
    ESTADO.consultando = false;
    ESTADO.resumo.fim = formatarHorarioAgora();
    aplicarResumoNaTela();
    atualizarIndicadorCache('Resultados online', 'cache-online');
    atualizarStatus('Consulta finalizada. Processados: ' + ESTADO.processados + '/' + ESTADO.totalItens + '.');
    var botao = document.getElementById('botaoConsultar');
    if (botao) {
        botao.disabled = false;
    }
}

function atualizarLinhaResultado(indiceItem, payload) {
    var indice = -1;
    for (var i = 0; i < ESTADO.linhas.length; i++) {
        if (String(ESTADO.linhas[i].indiceItem || '') === String(indiceItem)) {
            indice = i;
            break;
        }
    }
    if (indice < 0) {
        return;
    }

    var linha = ESTADO.linhas[indice];
    linha.indiceServidor = String(payload && payload.indiceServidor != null ? payload.indiceServidor : linha.indiceServidor || '');
    if (payload && payload.ok === true) {
        var quantidadeNumero = Number(payload.quantidade || 0);
        if (quantidadeNumero <= 0) {
            ESTADO.linhas.splice(indice, 1);
            return;
        }
        linha.quantidade = String(payload.quantidade != null ? payload.quantidade : '0');
        linha.classeLinha = String(payload.classeLinha || ((quantidadeNumero > 100) ? 'linha-vermelha' : 'linha-verde'));
    } else if (payload && payload.erro_conexao) {
        linha.quantidade = 'não conecta';
        linha.classeLinha = 'linha-vermelha';
    } else {
        ESTADO.linhas.splice(indice, 1);
    }
}

async function requisitarJson(url) {
    var resposta = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
    });
    var texto = await resposta.text();
    var payload = null;
    try {
        payload = texto ? JSON.parse(texto) : null;
    } catch (erro) {
        payload = { ok: false, erro: 'Resposta inválida do worker.', detalhe: texto };
    }
    if (!resposta.ok && payload && typeof payload.ok === 'undefined') {
        payload.ok = false;
    }
    return payload || { ok: false, erro: 'Resposta vazia do worker.' };
}

async function processarItemConsulta(item) {
    atualizarStatus('Consultando ' + item.nome + ' | ' + item.database + '...');
    try {
        var payload = await requisitarJson(URL_WORKER + '?i=' + encodeURIComponent(item.indiceItem) + '&dias=' + encodeURIComponent(DIAS_CONSULTA_ATUAL));
        if (payload && payload.ok === true) {
            ESTADO.resumo.ok += Number(payload.contabilizar_ok || 0);
            atualizarLinhaResultado(item.indiceItem, payload);
        } else {
            ESTADO.resumo.erro += Number(payload && payload.contabilizar_erro ? payload.contabilizar_erro : 1);
            atualizarLinhaResultado(item.indiceItem, payload || { ok: false });
        }
    } catch (erro) {
        ESTADO.resumo.erro += 1;
        atualizarLinhaResultado(item.indiceItem, { ok: false, erro_conexao: true });
    }

    ESTADO.processados += 1;
    recalcularResumoPorLinhas();
    renderizarTabela();
    atualizarStatus('Consulta em andamento. Processados: ' + ESTADO.processados + '/' + ESTADO.totalItens + '.');
}

async function iniciarConsulta() {
    reiniciarConsulta();

    var fila = SERVIDORES_LISTA.slice();
    var concorrencia = Math.max(1, Math.min(LIMITE_CONCORRENCIA, fila.length || 1));
    var trabalhadores = [];

    for (var i = 0; i < concorrencia; i++) {
        trabalhadores.push((async function() {
            while (fila.length > 0) {
                var item = fila.shift();
                if (!item) {
                    break;
                }
                await processarItemConsulta(item);
            }
        })());
    }

    await Promise.all(trabalhadores);
    finalizarConsulta();
}

window.addEventListener('DOMContentLoaded', function() {
    if (EXECUTAR_CONSULTA_INICIAL) {
        iniciarConsulta();
    } else {
        aplicarResumoNaTela();
        renderizarTabela();
        atualizarIndicadorCache('Aguardando consulta', 'cache-empty');
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
                <p>Painel rápido lendo o servidores.json e consultando as bases do servidores.json com notas em situação irregular.</p>
            </div>
            <div class="tag-row">
                <span class="chip"><span>Início</span> <strong><?= htmlspecialchars(date('d/m/Y H:i:s'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                <span class="chip"><span>Origem</span> <strong>servidores.json</strong></span>
                <span class="chip live"><span>Dias</span> <strong><?= (int) $diasConsulta ?></strong></span>
            </div>
        </div>

        <div class="toolbar-grid">
            <section class="panel panel-form">
                <form method="get" action="">
                    <input type="hidden" name="consultar" value="1">
                    <div class="field">
                        <label for="origem">Origem</label>
                        <input type="text" id="origem" value="servidores.json" readonly>
                    </div>
                    <div class="field">
                        <label for="filtro_cliente">Cliente</label>
                        <input type="text" id="filtro_cliente" name="filtro_cliente" value="<?= htmlspecialchars($filtroCliente, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex.: pelanda22">
                    </div>
                    <div class="field">
                        <label for="filtro_trecho">Trecho</label>
                        <input type="text" id="filtro_trecho" name="filtro_trecho" value="<?= htmlspecialchars($filtroTrecho, ENT_QUOTES, 'UTF-8') ?>" placeholder="Like no nome original">
                    </div>
                    <div class="field">
                        <label for="dias">Dias</label>
                        <input type="number" id="dias" name="dias" min="1" value="<?= (int) $diasConsulta ?>">
                    </div>
                    <div>
                        <button class="botao" id="botaoConsultar" type="submit">Consultar</button>
                    </div>
                    <div>
                        <button class="botao botao-secundario" type="button" onclick="limparFiltros()">Limpar</button>
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
                <div class="panel metric metric-fim">
                    <div class="label">Fim</div>
                    <div class="value">
                        <div class="fim-carga" id="fimExecucaoCarregando">
                            <div class="fim-carga-spinner"></div>
                            <div class="fim-carga-texto">Carregando</div>
                        </div>
                        <div class="fim-execucao-texto" id="fimExecucao">-</div>
                    </div>
                    <div class="hint">Horário da última finalização</div>
                </div>
            </section>
        </div>

        <div class="status-bar">
            <span class="status-dot"></span>
            <div class="status-text" id="statusAtual"><?= htmlspecialchars((string) $textoStatusInicial, ENT_QUOTES, 'UTF-8') ?></div>
            <span class="cache-pill" id="origemDados">Preparando leitura</span>
        </div>
    </header>

    <main class="content-shell">
        <section class="surface">
            <div class="surface-head">
                <div>
                    <div class="surface-title">Bases com inconsistências</div>
                    <div class="surface-subtitle">Ordenado por grupo e nome. Clique na quantidade para abrir os detalhes em uma nova aba.</div>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>IP</th>
                            <th>Porta</th>
                            <th>Database</th>
                            <th>Quantidade</th>
                        </tr>
                    </thead>
                    <tbody id="corpoTabela"></tbody>
                </table>
            </div>
            <div class="empty-state" id="mensagemVazia"><?= htmlspecialchars($executarConsulta ? 'Nenhum resultado encontrado até o momento.' : 'Clique em Consultar para iniciar a leitura do servidores.json.', ENT_QUOTES, 'UTF-8') ?></div>
        </section>
    </main>
</div>
</body>
</html>
