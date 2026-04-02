<?php
// consultar_emissores_json.php

date_default_timezone_set('America/Sao_Paulo');
@ini_set('max_execution_time', '0');
@set_time_limit(0);
@ini_set('default_socket_timeout', '600');
mysqli_report(MYSQLI_REPORT_OFF);

$arquivoCacheJson = __DIR__ . '/cache_consulta_emissores.json';
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

$mensagensErro = array();
$mensagensOk = array();
$resultados = array();
$consultado = false;
$jsonEmUso = '';
$origensDisponiveis = array();
$modelosDisponiveis = array();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        @set_time_limit(0);

        if (isset($_FILES['arquivo_json']) && isset($_FILES['arquivo_json']['tmp_name']) && $_FILES['arquivo_json']['tmp_name'] !== '') {
            if ((int)$_FILES['arquivo_json']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Erro no upload do arquivo JSON.');
            }

            salvarCacheJson($_FILES['arquivo_json']['tmp_name'], $arquivoCacheJson);
            $mensagensOk[] = 'Arquivo JSON salvo em cache com sucesso.';
        }

        $jsonEmUso = lerConteudoArquivo($arquivoCacheJson);

        if (trim($jsonEmUso) === '') {
            throw new Exception('Nenhum JSON em cache. Informe um arquivo JSON para consultar.');
        }

        $servidores = lerJsonServidoresDoConteudo($jsonEmUso);
        $consultado = true;

        foreach ($servidores as $item) {
            @set_time_limit(0);

            $nome        = isset($item['nome']) ? trim((string)$item['nome']) : '';
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

            try {
                $connSeq = @new mysqli($enderecoSeq, $usuarioMysql, $senhaMysql, 'mysoft_emissor', $portaSeq);

                if (!$connSeq || $connSeq->connect_errno) {
                    $textoErro = $connSeq ? $connSeq->connect_error : 'falha ao criar conexão';
                    $mensagensErro[] = 'Erro em ' . $localConexaoSeq . ' - ' . $textoErro;
                    if ($connSeq) {
                        $connSeq->close();
                    }
                    continue;
                }
            } catch (Throwable $e) {
                $mensagensErro[] = 'Erro em ' . $localConexaoSeq . ' - ' . $e->getMessage();
                continue;
            }

            @$connSeq->set_charset('utf8mb4');

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

            try {
                $connCad = @new mysqli($endereco, $usuarioMysql, $senhaMysql, $database, $porta);

                if (!$connCad || $connCad->connect_errno) {
                    $textoErro = $connCad ? $connCad->connect_error : 'falha ao criar conexão';
                    $mensagensErro[] = 'Erro em ' . $localConexaoCad . ' - ' . $textoErro;
                    if ($connCad) {
                        $connCad->close();
                    }
                    continue;
                }
            } catch (Throwable $e) {
                $mensagensErro[] = 'Erro em ' . $localConexaoCad . ' - ' . $e->getMessage();
                continue;
            }

            @$connCad->set_charset('utf8mb4');

            $placeholders = implode(',', array_fill(0, count($idtsSeq), '?'));
            $sqlCad =
                'select idt,webservice,serie_nota_fiscal,modelo_nota_fiscal,empresa,vencimento,origem,validar_nfe,cancelar_nfe,inutilizar_nfe,consultar_nfe,offline_nfe ' .
                'from cad_emissor ' .
                'where ativo = "S" ' .
                'and idt in (' . $placeholders . ')';

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
                $linha['_json_nome'] = $nome;
                $linha['_json_endereco'] = $endereco;
                $linha['_json_porta'] = $porta;
                $linha['_json_database'] = $database;
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

            $dbA = isset($a['_json_database']) ? (string)$a['_json_database'] : '';
            $dbB = isset($b['_json_database']) ? (string)$b['_json_database'] : '';
            $cmp = strcmp($dbA, $dbB);
            if ($cmp !== 0) {
                return $cmp;
            }

            $endA = isset($a['_json_endereco']) ? (string)$a['_json_endereco'] : '';
            $endB = isset($b['_json_endereco']) ? (string)$b['_json_endereco'] : '';
            return strcmp($endA, $endB);
        });
    } catch (Exception $e) {
        $mensagensErro[] = $e->getMessage();
    }
} else {
    $jsonEmUso = lerConteudoArquivo($arquivoCacheJson);
}
$versaoCssBase = @filemtime(__DIR__ . '/consultar_notas_servidores.css');
$versaoCssLocal = @filemtime(__DIR__ . '/consultar_emissores.css');
$totalResultados = count($resultados);
$totalOrigens = count($origensDisponiveis);
$totalModelos = count($modelosDisponiveis);
$totalErros = count($mensagensErro);
$cacheExiste = file_exists($arquivoCacheJson);
$nomeCacheAtual = $cacheExiste ? basename($arquivoCacheJson) : 'nenhum arquivo salvo';
$statusPainel = 'Envie um JSON ou use o arquivo em cache para consultar os emissores.';
if ($consultado) {
    if ($totalResultados > 0) {
        $statusPainel = 'Consulta concluída com ' . $totalResultados . ' registro(s) encontrados.';
    } elseif ($totalErros === 0) {
        $statusPainel = 'Consulta concluída. Nenhum registro encontrado.';
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
<title>Consulta de Emissores por JSON</title>
<link rel="stylesheet" href="consultar_notas_servidores.css<?php echo $versaoCssBase ? '?v=' . $versaoCssBase : ''; ?>">
<link rel="stylesheet" href="consultar_emissores.css<?php echo $versaoCssLocal ? '?v=' . $versaoCssLocal : ''; ?>">
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <div class="title-row">
            <div class="title-block">
                <h1>Consulta de Emissores por JSON</h1>
                <p>Mesmo padrão visual dos painéis principais, mantendo a consulta original de emissores e os filtros locais da tabela.</p>
            </div>
            <div class="tag-row">
                <span class="chip"><span>Cache</span> <strong><?php echo h($nomeCacheAtual); ?></strong></span>
                <span class="chip"><span>Origens</span> <strong><?php echo (int) $totalOrigens; ?></strong></span>
                <span class="chip"><span>Modelos</span> <strong><?php echo (int) $totalModelos; ?></strong></span>
                <span class="chip live"><span>Registros</span> <strong><?php echo (int) $totalResultados; ?></strong></span>
            </div>
        </div>

        <div class="toolbar-grid">
            <section class="panel panel-form">
                <form class="form-upload-emissores" method="post" enctype="multipart/form-data">
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
                    <div class="hint">Total retornado na última consulta</div>
                </div>
                <div class="panel metric warn">
                    <div class="label">Origens</div>
                    <div class="value"><?php echo (int) $totalOrigens; ?></div>
                    <div class="hint">Valores únicos disponíveis no filtro</div>
                </div>
                <div class="panel metric">
                    <div class="label">Modelos</div>
                    <div class="value"><?php echo (int) $totalModelos; ?></div>
                    <div class="hint">Modelos únicos encontrados</div>
                </div>
                <div class="panel metric danger">
                    <div class="label">Alertas</div>
                    <div class="value"><?php echo (int) $totalErros; ?></div>
                    <div class="hint">Mensagens de erro durante a leitura</div>
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
                    <div class="message-line info">Envie um arquivo JSON novo ou reaproveite o cache salvo para iniciar a consulta.</div>
                <?php endif; ?>

                <?php foreach ($mensagensOk as $msg): ?>
                    <div class="message-line ok"><?php echo h($msg); ?></div>
                <?php endforeach; ?>

                <?php foreach ($mensagensErro as $erro): ?>
                    <div class="message-line erro"><?php echo h($erro); ?></div>
                <?php endforeach; ?>

                <?php if ($consultado && count($resultados) === 0 && count($mensagensErro) === 0): ?>
                    <div class="message-line info">Nenhum registro encontrado para os emissores consultados.</div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if (count($resultados) > 0): ?>
        <section class="panel panel-form-local">
            <div class="local-filter-row">
                <div class="field">
                    <label for="filtro_origem">Origem</label>
                    <select id="filtro_origem">
                        <option value="">Todas</option>
                        <?php foreach ($origensDisponiveis as $origem): ?>
                            <option value="<?php echo h($origem); ?>"><?php echo h($origem); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="filtro_modelo">Modelo</label>
                    <select id="filtro_modelo">
                        <option value="">Todos</option>
                        <?php foreach ($modelosDisponiveis as $modelo): ?>
                            <option value="<?php echo h($modelo); ?>"><?php echo h($modelo); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <button class="botao" type="button" onclick="limparFiltrosTabela()">Limpar</button>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="surface">
            <div class="surface-head">
                <div>
                    <div class="surface-title">Resultado da consulta</div>
                    <div class="surface-subtitle">Agrupado por origem. Quando o mesmo IDT aparece mais de uma vez, as linhas recebem a mesma cor para facilitar a identificação.</div>
                </div>
                <div class="toolbar-inline">
                    <span class="counter-pill">Cache <strong><?php echo h($nomeCacheAtual); ?></strong></span>
                    <span class="counter-pill">Total <strong><?php echo (int) $totalResultados; ?></strong></span>
                </div>
            </div>

            <div class="table-wrap">
                <table id="tabela_resultados">
                    <thead>
                        <tr>
                            <th>Nome JSON</th>
                            <th>Endereço JSON</th>
                            <th>Porta JSON</th>
                            <th>Database JSON</th>
                            <?php foreach ($colunasResultado as $coluna): ?>
                                <th><?php echo h(tituloColuna($coluna)); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($resultados) > 0): ?>
                            <?php $origemAtual = null; ?>
                            <?php foreach ($resultados as $linha): ?>
                                <?php $origemLinha = isset($linha['origem']) ? formatarOrigem($linha['origem']) : ''; ?>
                                <?php if ($origemAtual !== $origemLinha): ?>
                                    <tr class="linha-grupo" data-grupo-origem="<?php echo h($origemLinha); ?>">
                                        <td colspan="<?php echo 4 + count($colunasResultado); ?>">Origem: <?php echo h($origemLinha === '' ? '(sem origem)' : $origemLinha); ?></td>
                                    </tr>
                                    <?php $origemAtual = $origemLinha; ?>
                                <?php endif; ?>
                                <tr data-idt="<?php echo h(isset($linha['idt']) ? $linha['idt'] : ''); ?>" data-origem="<?php echo h($origemLinha); ?>" data-modelo="<?php echo h(isset($linha['modelo_nota_fiscal']) ? $linha['modelo_nota_fiscal'] : ''); ?>">
                                    <td><?php echo h($linha['_json_nome']); ?></td>
                                    <td><?php echo h($linha['_json_endereco']); ?></td>
                                    <td><?php echo h($linha['_json_porta']); ?></td>
                                    <td><?php echo h($linha['_json_database']); ?></td>
                                    <?php foreach ($colunasResultado as $coluna): ?>
                                        <td><?php echo h(formatarValorColuna($coluna, isset($linha[$coluna]) ? $linha[$coluna] : '')); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="empty-state" id="mensagemVazia" style="<?php echo count($resultados) > 0 ? 'display:none;' : ''; ?>">
                <?php echo h($consultado ? 'Nenhum registro encontrado.' : 'Envie um JSON e clique em Consultar para carregar os emissores.'); ?>
            </div>
        </section>
    </main>
</div>

<?php if (count($resultados) > 0): ?>
<script>
function aplicarFiltrosTabela() {
    var filtroOrigem = document.getElementById('filtro_origem').value;
    var filtroModelo = document.getElementById('filtro_modelo').value;
    var linhas = document.querySelectorAll('#tabela_resultados tbody tr');

    linhas.forEach(function(linha) {
        if (linha.classList.contains('linha-grupo')) {
            return;
        }

        var origem = linha.getAttribute('data-origem') || '';
        var modelo = linha.getAttribute('data-modelo') || '';
        var mostrar = true;

        if (filtroOrigem !== '' && origem !== filtroOrigem) {
            mostrar = false;
        }

        if (filtroModelo !== '' && modelo !== filtroModelo) {
            mostrar = false;
        }

        linha.style.display = mostrar ? '' : 'none';
    });

    var grupos = document.querySelectorAll('#tabela_resultados tbody tr.linha-grupo');
    grupos.forEach(function(grupo) {
        var mostrarGrupo = false;
        var proximaLinha = grupo.nextElementSibling;

        while (proximaLinha && !proximaLinha.classList.contains('linha-grupo')) {
            if (proximaLinha.style.display !== 'none') {
                mostrarGrupo = true;
                break;
            }
            proximaLinha = proximaLinha.nextElementSibling;
        }

        grupo.style.display = mostrarGrupo ? '' : 'none';
    });
}

function limparFiltrosTabela() {
    document.getElementById('filtro_origem').value = '';
    document.getElementById('filtro_modelo').value = '';
    aplicarFiltrosTabela();
}

function colorirIdtsIguais() {
    var linhas = document.querySelectorAll('#tabela_resultados tbody tr');
    var contagem = {};
    var cores = [
        '#fff4cc',
        '#dff3ff',
        '#e7f9e7',
        '#f6e3ff',
        '#ffe6d9',
        '#e6fff7',
        '#fbe8d3',
        '#e8ecff'
    ];
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

document.getElementById('filtro_origem').addEventListener('change', aplicarFiltrosTabela);
document.getElementById('filtro_modelo').addEventListener('change', aplicarFiltrosTabela);
colorirIdtsIguais();
aplicarFiltrosTabela();
</script>
<?php endif; ?>

</body>
</html>
