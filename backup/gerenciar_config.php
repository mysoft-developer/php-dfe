<?php

declare(strict_types=1);

const ARQUIVO_CONFIG = __DIR__ . '/config.json';

function responderJson(array $dados, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function h(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
}

function lerCorpoJson(): array
{
    $corpo = file_get_contents('php://input');
    if ($corpo === false || trim($corpo) === '') {
        return [];
    }

    $dados = json_decode($corpo, true);
    if (!is_array($dados)) {
        responderJson([
            'ok' => false,
            'mensagem' => 'JSON inválido no corpo da requisição.'
        ], 400);
    }

    return $dados;
}

function carregarConfiguracao(): array
{
    if (!is_file(ARQUIVO_CONFIG)) {
        responderJson([
            'ok' => false,
            'mensagem' => 'Arquivo config.json não encontrado em ' . ARQUIVO_CONFIG
        ], 500);
    }

    $conteudo = file_get_contents(ARQUIVO_CONFIG);
    if ($conteudo === false) {
        responderJson([
            'ok' => false,
            'mensagem' => 'Não foi possível ler o arquivo config.json.'
        ], 500);
    }

    $config = json_decode($conteudo, true);
    if (!is_array($config)) {
        responderJson([
            'ok' => false,
            'mensagem' => 'O arquivo config.json está inválido.'
        ], 500);
    }

    if (!isset($config['servidores']) || !is_array($config['servidores'])) {
        $config['servidores'] = [];
    }

    return $config;
}

function salvarConfiguracao(array $config): void
{
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        responderJson([
            'ok' => false,
            'mensagem' => 'Falha ao converter a configuração para JSON.'
        ], 500);
    }

    $fp = fopen(ARQUIVO_CONFIG, 'c+');
    if ($fp === false) {
        responderJson([
            'ok' => false,
            'mensagem' => 'Não foi possível abrir o arquivo config.json para gravação.'
        ], 500);
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            responderJson([
                'ok' => false,
                'mensagem' => 'Não foi possível bloquear o arquivo config.json.'
            ], 500);
        }

        ftruncate($fp, 0);
        rewind($fp);

        $gravado = fwrite($fp, $json . PHP_EOL);
        if ($gravado === false) {
            responderJson([
                'ok' => false,
                'mensagem' => 'Falha ao gravar o arquivo config.json.'
            ], 500);
        }

        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
}

function normalizarServidor(array $dados): array
{
    $identificacao = trim((string)($dados['identificacao'] ?? ''));
    $endereco = trim((string)($dados['endereco'] ?? ''));
    $porta = (int)($dados['porta'] ?? 0);

    if ($identificacao === '') {
        responderJson([
            'ok' => false,
            'mensagem' => 'Informe a identificação do servidor.'
        ], 400);
    }

    if ($endereco === '') {
        responderJson([
            'ok' => false,
            'mensagem' => 'Informe o endereço do servidor.'
        ], 400);
    }

    if ($porta <= 0 || $porta > 65535) {
        responderJson([
            'ok' => false,
            'mensagem' => 'Informe uma porta válida entre 1 e 65535.'
        ], 400);
    }

    return [
        'identificacao' => $identificacao,
        'endereco' => $endereco,
        'porta' => $porta,
    ];
}

function indiceServidor(array $servidores, int $indice): int
{
    if (!array_key_exists($indice, $servidores)) {
        responderJson([
            'ok' => false,
            'mensagem' => 'Servidor não encontrado para o índice informado.'
        ], 404);
    }

    return $indice;
}

function processarApi(): void
{
    $acao = $_GET['acao'] ?? $_POST['acao'] ?? '';
    $config = carregarConfiguracao();

    if ($acao === 'listar') {
        responderJson([
            'ok' => true,
            'servidores' => array_values($config['servidores']),
            'total' => count($config['servidores'])
        ]);
    }

    $entrada = $_POST;
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
        $entrada = lerCorpoJson();
    }

    if ($acao === 'incluir') {
        $novoServidor = normalizarServidor($entrada);
        $config['servidores'][] = $novoServidor;
        $config['servidores'] = array_values($config['servidores']);
        salvarConfiguracao($config);

        responderJson([
            'ok' => true,
            'mensagem' => 'Servidor incluído com sucesso.',
            'servidores' => $config['servidores']
        ]);
    }

    if ($acao === 'alterar') {
        $indice = isset($entrada['indice']) ? (int)$entrada['indice'] : -1;
        $indice = indiceServidor($config['servidores'], $indice);
        $config['servidores'][$indice] = normalizarServidor($entrada);
        $config['servidores'] = array_values($config['servidores']);
        salvarConfiguracao($config);

        responderJson([
            'ok' => true,
            'mensagem' => 'Servidor alterado com sucesso.',
            'servidores' => $config['servidores']
        ]);
    }

    if ($acao === 'excluir') {
        $indice = isset($entrada['indice']) ? (int)$entrada['indice'] : -1;
        $indice = indiceServidor($config['servidores'], $indice);
        $servidorExcluido = $config['servidores'][$indice];
        unset($config['servidores'][$indice]);
        $config['servidores'] = array_values($config['servidores']);
        salvarConfiguracao($config);

        responderJson([
            'ok' => true,
            'mensagem' => 'Servidor excluído com sucesso.',
            'servidor_excluido' => $servidorExcluido,
            'servidores' => $config['servidores']
        ]);
    }

    responderJson([
        'ok' => false,
        'mensagem' => 'Ação inválida. Use listar, incluir, alterar ou excluir.'
    ], 400);
}

if (isset($_GET['api']) || isset($_POST['api']) || isset($_GET['acao']) || isset($_POST['acao'])) {
    processarApi();
}

$config = carregarConfiguracao();
$servidores = array_values($config['servidores']);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gerenciar Servidores do config.json</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f5f7fb;
            color: #1f2937;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .cabecalho, .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
            padding: 20px;
            margin-bottom: 20px;
        }
        h1 {
            margin: 0 0 10px;
            font-size: 28px;
        }
        .subtitulo {
            margin: 0;
            color: #4b5563;
        }
        .grid {
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 20px;
        }
        .linha {
            margin-bottom: 14px;
        }
        label {
            display: block;
            font-weight: bold;
            margin-bottom: 6px;
        }
        input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
        }
        .acoes {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        button {
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
        }
        .btn-salvar { background: #2563eb; color: #fff; }
        .btn-limpar { background: #6b7280; color: #fff; }
        .btn-excluir { background: #dc2626; color: #fff; }
        .btn-editar { background: #f59e0b; color: #fff; }
        .btn-remover { background: #dc2626; color: #fff; }
        .info {
            font-size: 13px;
            color: #6b7280;
        }
        .mensagem {
            display: none;
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            font-weight: bold;
        }
        .mensagem.sucesso {
            display: block;
            background: #dcfce7;
            color: #166534;
        }
        .mensagem.erro {
            display: block;
            background: #fee2e2;
            color: #991b1b;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 10px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
        }
        th {
            background: #f9fafb;
        }
        .col-acoes {
            width: 180px;
        }
        .topo-tabela {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        .contador {
            color: #4b5563;
            font-size: 14px;
            font-weight: bold;
        }
        code {
            background: #eef2ff;
            padding: 2px 6px;
            border-radius: 6px;
        }
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
            .topo-tabela { align-items: flex-start; flex-direction: column; }
            .col-acoes { width: auto; }
            table, thead, tbody, th, td, tr { display: block; }
            thead { display: none; }
            tr {
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                margin-bottom: 12px;
                overflow: hidden;
                background: #fff;
            }
            td {
                border-bottom: 1px solid #f1f5f9;
            }
            td::before {
                content: attr(data-label);
                display: block;
                font-weight: bold;
                color: #6b7280;
                margin-bottom: 4px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="cabecalho">
        <h1>Gerenciar servidores do config.json</h1>
        <p class="subtitulo">Este arquivo mantém as outras chaves intactas e altera apenas a lista <code>servidores</code>.</p>
        <p class="info">Arquivo utilizado: <strong><?= h(ARQUIVO_CONFIG) ?></strong></p>
    </div>

    <div class="grid">
        <div class="card">
            <h2 id="titulo-formulario">Incluir servidor</h2>
            <form id="form-servidor">
                <input type="hidden" id="indice" name="indice" value="">

                <div class="linha">
                    <label for="identificacao">Identificação</label>
                    <input type="text" id="identificacao" name="identificacao" placeholder="Ex.: Servidor Novo - Local" required>
                </div>

                <div class="linha">
                    <label for="endereco">Endereço</label>
                    <input type="text" id="endereco" name="endereco" placeholder="Ex.: 10.8.0.200" required>
                </div>

                <div class="linha">
                    <label for="porta">Porta</label>
                    <input type="number" id="porta" name="porta" min="1" max="65535" value="3306" required>
                </div>

                <div class="acoes">
                    <button type="submit" class="btn-salvar" id="btn-salvar">Salvar</button>
                    <button type="button" class="btn-limpar" id="btn-limpar">Limpar</button>
                </div>

                <div class="mensagem" id="mensagem"></div>
            </form>
        </div>

        <div class="card">
            <div class="topo-tabela">
                <h2 style="margin:0;">Servidores cadastrados</h2>
                <div class="contador" id="contador">Total: <?= count($servidores) ?></div>
            </div>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Identificação</th>
                            <th>Endereço</th>
                            <th>Porta</th>
                            <th class="col-acoes">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tabela-servidores">
                    <?php foreach ($servidores as $indice => $servidor): ?>
                        <tr>
                            <td data-label="#"><?= (int)$indice ?></td>
                            <td data-label="Identificação"><?= h((string)($servidor['identificacao'] ?? '')) ?></td>
                            <td data-label="Endereço"><?= h((string)($servidor['endereco'] ?? '')) ?></td>
                            <td data-label="Porta"><?= h((string)($servidor['porta'] ?? '')) ?></td>
                            <td data-label="Ações">
                                <button type="button" class="btn-editar" onclick="editarServidor(<?= (int)$indice ?>)">Alterar</button>
                                <button type="button" class="btn-remover" onclick="excluirServidor(<?= (int)$indice ?>)">Excluir</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const servidores = <?= json_encode($servidores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const form = document.getElementById('form-servidor');
const campoIndice = document.getElementById('indice');
const campoIdentificacao = document.getElementById('identificacao');
const campoEndereco = document.getElementById('endereco');
const campoPorta = document.getElementById('porta');
const mensagem = document.getElementById('mensagem');
const tituloFormulario = document.getElementById('titulo-formulario');

function mostrarMensagem(texto, tipo) {
    mensagem.textContent = texto;
    mensagem.className = 'mensagem ' + tipo;
}

function limparMensagem() {
    mensagem.textContent = '';
    mensagem.className = 'mensagem';
}

function limparFormulario() {
    campoIndice.value = '';
    campoIdentificacao.value = '';
    campoEndereco.value = '';
    campoPorta.value = '3306';
    tituloFormulario.textContent = 'Incluir servidor';
    limparMensagem();
}

function editarServidor(indice) {
    const servidor = servidores[indice];
    if (!servidor) {
        mostrarMensagem('Servidor não encontrado para edição.', 'erro');
        return;
    }

    campoIndice.value = String(indice);
    campoIdentificacao.value = servidor.identificacao || '';
    campoEndereco.value = servidor.endereco || '';
    campoPorta.value = servidor.porta || 3306;
    tituloFormulario.textContent = 'Alterar servidor';
    limparMensagem();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function enviarRequisicao(acao, dados) {
    const resposta = await fetch('?api=1&acao=' + encodeURIComponent(acao), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(dados)
    });

    const json = await resposta.json();
    if (!resposta.ok || !json.ok) {
        throw new Error(json.mensagem || 'Erro ao processar a solicitação.');
    }

    return json;
}

form.addEventListener('submit', async function (evento) {
    evento.preventDefault();
    limparMensagem();

    const dados = {
        indice: campoIndice.value,
        identificacao: campoIdentificacao.value,
        endereco: campoEndereco.value,
        porta: campoPorta.value
    };

    try {
        const acao = campoIndice.value === '' ? 'incluir' : 'alterar';
        const resposta = await enviarRequisicao(acao, dados);
        mostrarMensagem(resposta.mensagem || 'Operação concluída com sucesso.', 'sucesso');
        setTimeout(function () {
            window.location.reload();
        }, 500);
    } catch (erro) {
        mostrarMensagem(erro.message, 'erro');
    }
});

document.getElementById('btn-limpar').addEventListener('click', limparFormulario);

async function excluirServidor(indice) {
    const servidor = servidores[indice];
    if (!servidor) {
        mostrarMensagem('Servidor não encontrado para exclusão.', 'erro');
        return;
    }

    const confirmado = window.confirm('Deseja realmente excluir o servidor "' + (servidor.identificacao || '') + '"?');
    if (!confirmado) {
        return;
    }

    try {
        const resposta = await enviarRequisicao('excluir', { indice: indice });
        mostrarMensagem(resposta.mensagem || 'Servidor excluído com sucesso.', 'sucesso');
        setTimeout(function () {
            window.location.reload();
        }, 500);
    } catch (erro) {
        mostrarMensagem(erro.message, 'erro');
    }
}
</script>
</body>
</html>
