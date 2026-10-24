<?php

declare(strict_types=1);

session_start();

const LOGIN_USUARIO_FIXO = 'mysoft';
const LOGIN_SENHA_FIXA = 'Papaleguas@2026';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_usuario'])) {
    $usuarioLogin = trim((string) ($_POST['login_usuario'] ?? ''));
    $senhaLogin = (string) ($_POST['login_senha'] ?? '');
    if ($usuarioLogin === LOGIN_USUARIO_FIXO && $senhaLogin === LOGIN_SENHA_FIXA) {
        session_regenerate_id(true);
        $_SESSION['consultar_notas_autenticado'] = true;
        header('Location: ' . (string) ($_SERVER['REQUEST_URI'] ?? 'consultar_notas_todos_servidores.php'));
        exit;
    }
    $erroLogin = 'Usuário ou senha inválidos.';
} else {
    $erroLogin = '';
}

$autenticado = !empty($_SESSION['consultar_notas_autenticado']);
if (!$autenticado && (isset($_GET['ajax']) || (string) ($_POST['ajax_action'] ?? '') === '1')) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'erro' => 'Autenticação necessária.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$autenticado) {
    $erroLoginHtml = htmlspecialchars($erroLogin, ENT_QUOTES, 'UTF-8');
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acesso - Correção de notas fiscais</title>
    <style>
    :root { color-scheme: light; font-family: Arial, sans-serif; }
    * { box-sizing: border-box; }
    body { min-height:100vh; margin:0; display:flex; align-items:center; justify-content:center; padding:24px; background:linear-gradient(135deg,#eef4fb,#dce8f6); color:#24364b; }
    .login-card { width:min(100%,430px); padding:34px; border:1px solid rgba(132,150,181,.3); border-radius:24px; background:#fff; box-shadow:0 20px 50px rgba(37,65,100,.16); text-align:center; }
    .login-icone { width:82px; height:82px; margin:0 auto 18px; display:block; }
    h1 { margin:0; font-size:21px; letter-spacing:.04em; text-transform:uppercase; }
    p { margin:9px 0 24px; color:#6b7d94; font-size:13px; line-height:1.45; }
    .login-form { display:flex; flex-direction:column; gap:12px; text-align:left; }
    label { color:#5d7088; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
    input { width:100%; height:44px; margin-top:6px; padding:0 13px; border:1px solid #cbd7e5; border-radius:12px; outline:none; font-size:14px; color:#24364b; }
    input:focus { border-color:#2f7de1; box-shadow:0 0 0 4px rgba(47,125,225,.12); }
    button { height:46px; margin-top:7px; border:0; border-radius:12px; background:linear-gradient(180deg,#3984df,#2668be); color:#fff; font-weight:700; cursor:pointer; }
    .login-erro { margin:0 0 12px; padding:10px; border-radius:10px; background:#fff0f0; color:#b33e3e; font-size:12px; }
    </style>
    </head>
    <body>
    <main class="login-card">
    <img class="login-icone" src="consultar_notas_todos_servidores.svg" alt="Correção de notas fiscais eletrônicas">
    <h1>Correção de notas fiscais</h1>
    <p>Informe suas credenciais para consultar e corrigir notas fiscais eletrônicas com problemas.</p>
    <?= $erroLoginHtml !== '' ? '<div class="login-erro">' . $erroLoginHtml . '</div>' : '' ?>
    <form class="login-form" method="post" autocomplete="off">
    <label>Usuário<input type="text" name="login_usuario" required autofocus></label>
    <label>Senha<input type="password" name="login_senha" required></label>
    <button type="submit">Entrar</button>
    </form>
    </main>
    </body>
    </html>
    <?php
    exit;
}

session_write_close();

set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(true);

const SERVIDORES_TABELA_HOST = '10.8.0.6';
const SERVIDORES_TABELA_PORTA = 3306;
const SERVIDORES_TABELA_USUARIO = 'mysoftweb';
const SERVIDORES_TABELA_SENHA = 'g3108f88';
const SERVIDORES_TABELA_DATABASE = 'mysoft';

function responderJson(array $dados, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lerConfiguracao(): array
{
    $arquivo = __DIR__ . '/config.json';
    $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
    if (!is_array($dados)) {
        responderJson(['ok' => false, 'erro' => 'JSON inválido no config.json.'], 500);
    }
    return $dados;
}

function conectar(string $host, string $usuario, string $senha, int $porta, string $database = '')
{
    $conexao = mysqli_init();
    if ($conexao === false) {
        return false;
    }
    @mysqli_options($conexao, MYSQLI_OPT_CONNECT_TIMEOUT, 60);
    @mysqli_options($conexao, MYSQLI_OPT_READ_TIMEOUT, 600);
    if (!@mysqli_real_connect($conexao, $host, $usuario, $senha, $database, $porta)) {
        @mysqli_close($conexao);
        return false;
    }
    @$conexao->set_charset('utf8mb4');
    @$conexao->query('SET SESSION net_read_timeout = 600');
    @$conexao->query('SET SESSION net_write_timeout = 600');
    return $conexao;
}

function carregarServidores(): array
{
    $conexao = conectar(SERVIDORES_TABELA_HOST, SERVIDORES_TABELA_USUARIO, SERVIDORES_TABELA_SENHA, SERVIDORES_TABELA_PORTA, SERVIDORES_TABELA_DATABASE);
    if (!$conexao instanceof mysqli) {
        responderJson(['ok' => false, 'erro' => 'Não foi possível conectar na tabela servidores.'], 500);
    }
    $sql = "SELECT nome, grupo, endereco, porta, `database` FROM `servidores`
            WHERE ativo = 'S' ORDER BY grupo, nome, `database`, endereco, porta";
    $resultado = $conexao->query($sql);
    if ($resultado === false) {
        $erro = $conexao->error;
        $conexao->close();
        responderJson(['ok' => false, 'erro' => 'Erro ao consultar tabela servidores: ' . $erro], 500);
    }
    $servidores = [];
    while ($linha = $resultado->fetch_assoc()) {
        $servidores[] = [
            'nome' => trim((string) ($linha['nome'] ?? '')),
            'grupo' => trim((string) ($linha['grupo'] ?? '')),
            'endereco' => trim((string) ($linha['endereco'] ?? '')),
            'porta' => (int) ($linha['porta'] ?? 3306),
            'database' => trim((string) ($linha['database'] ?? ''))
        ];
    }
    $resultado->free();
    $conexao->close();
    return $servidores;
}

function nomeCampoOrigem(mysqli $conexao, string $database, string $tabelaNotas): string
{
    $stmt = $conexao->prepare("SELECT column_name FROM information_schema.columns
        WHERE table_schema = ? AND table_name = ?
        AND column_name IN ('empresa', 'origem')
        ORDER BY CASE column_name WHEN 'empresa' THEN 0 ELSE 1 END LIMIT 1");
    if ($stmt === false) {
        return 'origem';
    }
    $stmt->bind_param('ss', $database, $tabelaNotas);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $linha = $resultado ? $resultado->fetch_assoc() : null;
    if ($resultado) {
        $resultado->free();
    }
    $stmt->close();
    return is_array($linha) && !empty($linha['column_name']) ? (string) $linha['column_name'] : 'origem';
}

function normalizarData(string $valor, string $padrao): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
        return $padrao;
    }
    $data = DateTime::createFromFormat('!Y-m-d', $valor);
    $erros = DateTime::getLastErrors();
    if (!$data || ($erros !== false && ($erros['warning_count'] > 0 || $erros['error_count'] > 0))) {
        return $padrao;
    }
    return $data->format('Y-m-d');
}

function ajustarPeriodo(string $inicio, string $fim): array
{
    if ($inicio > $fim) {
        return [$fim, $inicio];
    }
    return [$inicio, $fim];
}

function chaveNota(array $linha): string
{
    return implode('|', array_map(
        static fn ($valor): string => strtolower(trim((string) $valor)),
        [
            $linha['idt'] ?? '',
            $linha['origem'] ?? '',
            $linha['serie'] ?? '',
            $linha['modelo'] ?? ''
        ]
    ));
}

function carregarNotas(mysqli $conexao, string $database, string $dataInicio, string $dataFim): array
{
    $databaseEscapado = str_replace('`', '``', $database);
    $erros = [];
    $linhasPorChave = [];
    $paresTabelas = [
        ['eletronicas' => 'notas_fiscais_eletronicas', 'notas' => 'notas_fiscais'],
        ['eletronicas' => 'nota_fiscal_eletronica', 'notas' => 'nota_fiscal']
    ];

    foreach ($paresTabelas as $tabelas) {
        $tabelaEletronicas = $tabelas['eletronicas'];
        $tabelaNotas = $tabelas['notas'];
        $campoOrigem = str_replace('`', '``', nomeCampoOrigem($conexao, $database, $tabelaNotas));
        $sql = "SELECT a.idt, b.cancelado, b.emissao, b.hora, a.nota, a.serie,
            a.modelo, a.origem, a.chave, a.operacao, a.status, a.status_descricao
            FROM `{$databaseEscapado}`.`{$tabelaEletronicas}` a
            INNER JOIN `{$databaseEscapado}`.`{$tabelaNotas}` b
                ON a.idt = b.idt AND a.origem = b.`{$campoOrigem}`
            WHERE a.status NOT IN (100, 150, 101, 155, 102)
              AND b.emissao >= '{$dataInicio} 00:00:00'
              AND b.emissao < DATE_ADD('{$dataFim}', INTERVAL 1 DAY)
            ORDER BY b.emissao DESC, a.idt DESC";
        $resultado = $conexao->query($sql);
        if ($resultado === false) {
            $erros[] = $tabelaEletronicas . '/' . $tabelaNotas . ': ' . $conexao->error;
            continue;
        }
        while ($linha = $resultado->fetch_assoc()) {
            $nota = [
                'idt' => (string) ($linha['idt'] ?? ''),
                'cancelado' => (string) ($linha['cancelado'] ?? ''),
                'emissao' => (string) ($linha['emissao'] ?? ''),
                'hora' => (string) ($linha['hora'] ?? ''),
                'nota' => (string) ($linha['nota'] ?? ''),
                'serie' => (string) ($linha['serie'] ?? ''),
                'modelo' => (string) ($linha['modelo'] ?? ''),
                'origem' => (string) ($linha['origem'] ?? ''),
                'chave' => (string) ($linha['chave'] ?? ''),
                'operacao' => (string) ($linha['operacao'] ?? ''),
                'status' => (string) ($linha['status'] ?? ''),
                'descricao' => (string) ($linha['status_descricao'] ?? '')
            ];
            $linhasPorChave[chaveNota($nota)] = $nota;
        }
        $resultado->free();
    }

    if (count($linhasPorChave) > 0 || count($erros) < count($paresTabelas)) {
        return array_values($linhasPorChave);
    }
    throw new RuntimeException('Erro ao consultar notas nas tabelas disponíveis: ' . implode(' | ', $erros));
}

function carregarNotasPorIdts(mysqli $conexao, string $database, array $idts): array
{
    $idtsEscapados = [];
    foreach ($idts as $idt) {
        $idt = trim((string) $idt);
        if ($idt !== '' && preg_match('/^[A-Za-z0-9_.:-]+$/', $idt) === 1) {
            $idtsEscapados[] = "'" . $conexao->real_escape_string($idt) . "'";
        }
    }
    if (count($idtsEscapados) === 0) {
        return [];
    }

    $databaseEscapado = str_replace('`', '``', $database);
    $erros = [];
    $linhasPorChave = [];
    $paresTabelas = [
        ['eletronicas' => 'notas_fiscais_eletronicas', 'notas' => 'notas_fiscais'],
        ['eletronicas' => 'nota_fiscal_eletronica', 'notas' => 'nota_fiscal']
    ];

    foreach ($paresTabelas as $tabelas) {
        $tabelaEletronicas = $tabelas['eletronicas'];
        $tabelaNotas = $tabelas['notas'];
        $campoOrigem = str_replace('`', '``', nomeCampoOrigem($conexao, $database, $tabelaNotas));
        $sql = "SELECT a.idt, b.cancelado, b.emissao, b.hora, a.nota, a.serie,
            a.modelo, a.origem, a.chave, a.operacao, a.status, a.status_descricao
            FROM `{$databaseEscapado}`.`{$tabelaEletronicas}` a
            INNER JOIN `{$databaseEscapado}`.`{$tabelaNotas}` b
                ON a.idt = b.idt AND a.origem = b.`{$campoOrigem}`
            WHERE a.idt IN (" . implode(', ', $idtsEscapados) . ")";
        $resultado = $conexao->query($sql);
        if ($resultado === false) {
            $erros[] = $tabelaEletronicas . '/' . $tabelaNotas . ': ' . $conexao->error;
            continue;
        }
        while ($linha = $resultado->fetch_assoc()) {
            $nota = [
                'idt' => (string) ($linha['idt'] ?? ''),
                'cancelado' => (string) ($linha['cancelado'] ?? ''),
                'emissao' => (string) ($linha['emissao'] ?? ''),
                'hora' => (string) ($linha['hora'] ?? ''),
                'nota' => (string) ($linha['nota'] ?? ''),
                'serie' => (string) ($linha['serie'] ?? ''),
                'modelo' => (string) ($linha['modelo'] ?? ''),
                'origem' => (string) ($linha['origem'] ?? ''),
                'chave' => (string) ($linha['chave'] ?? ''),
                'operacao' => (string) ($linha['operacao'] ?? ''),
                'status' => (string) ($linha['status'] ?? ''),
                'descricao' => (string) ($linha['status_descricao'] ?? '')
            ];
            $linhasPorChave[chaveNota($nota)] = $nota;
        }
        $resultado->free();
    }

    if (count($linhasPorChave) > 0 || count($erros) < count($paresTabelas)) {
        return array_values($linhasPorChave);
    }
    throw new RuntimeException('Erro ao consultar IDTs executados nas tabelas disponíveis: ' . implode(' | ', $erros));
}

function montarComando(string $executavel, string $modelo, string $idt, string $acao, array $servidor, int $versaoMysql): string
{
    $valores = [$modelo, $idt, $acao, 'xml', $servidor['endereco'], $servidor['database'], (string) $servidor['porta'], (string) $versaoMysql];
    foreach ($valores as $valor) {
        if (!is_string($valor) || preg_match('/^[A-Za-z0-9_.:\/-]+$/', $valor) !== 1) {
            throw new RuntimeException('Parâmetro inválido para execução do AutoNFe.');
        }
    }
    return '"' . trim($executavel, '"') . '" 0 ' . implode(' ', $valores);
}

function obterCaminhoAutonfe(array $configuracao): string
{
    $caminhos = isset($configuracao['autonfe']) && is_array($configuracao['autonfe'])
        ? $configuracao['autonfe']
        : [];

    if (PHP_OS_FAMILY === 'Windows') {
        return trim((string) ($caminhos['windows'] ?? ''));
    }

    if (PHP_OS_FAMILY === 'Linux') {
        $maquina = strtolower((string) php_uname('m'));
        $chave = (strpos($maquina, 'arm') !== false || strpos($maquina, 'aarch64') !== false)
            ? 'linux_arm'
            : 'linux';
        return trim((string) ($caminhos[$chave] ?? ''));
    }

    return '';
}

function prepararExecucaoAcao(array $configuracao, array $servidor, string $idt, string $modelo, string $acao): array
{
    $permitidas = ['consultarX', 'validarX', 'acerto_w', 'acerto_v', 'enviarX', 'gerarEnviar', 'cancelarX', 'inutilizarX', 'cancelarInutilizar', 'removercancelamento'];
    if (!in_array($acao, $permitidas, true)) {
        throw new RuntimeException('Ação inválida.');
    }
    $modelo = preg_match('/^\d+$/', $modelo) === 1 ? $modelo : '65';
    if ($acao === 'acerto_w' && $modelo === '55') {
        throw new RuntimeException('Ação Offline não permitida para notas do modelo 55.');
    }
    $executavel = obterCaminhoAutonfe($configuracao);
    $caminhoExecutavel = trim($executavel, '"');
    if ($caminhoExecutavel === '' || !is_file($caminhoExecutavel)) {
        throw new RuntimeException('Executável do AutoNFe não encontrado no config.json.');
    }
    if (PHP_OS_FAMILY !== 'Windows' && !is_executable($caminhoExecutavel)) {
        throw new RuntimeException('Executável do AutoNFe sem permissão de execução: ' . $caminhoExecutavel);
    }
    $versaoMysql = 5;
    foreach (($configuracao['servidores'] ?? []) as $item) {
        if (is_array($item) && trim((string) ($item['endereco'] ?? '')) === $servidor['endereco']
            && (int) ($item['porta'] ?? 3306) === (int) $servidor['porta']) {
            $versaoMysql = (int) ($item['mysql_versao'] ?? 5);
            break;
        }
    }
    return [
        'comando' => montarComando($executavel, $modelo, $idt, $acao, $servidor, $versaoMysql),
        'diretorio' => dirname(trim($executavel, '"')),
        'modelo' => $modelo,
        'idt' => $idt,
        'acao' => $acao
    ];
}

function executarAcao(array $configuracao, array $servidor, string $idt, string $modelo, string $acao): array
{
    $preparacao = prepararExecucaoAcao($configuracao, $servidor, $idt, $modelo, $acao);
    $pipes = [];
    $processo = @proc_open($preparacao['comando'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $preparacao['diretorio']);
    if (!is_resource($processo)) {
        throw new RuntimeException('Não foi possível iniciar o AutoNFe.');
    }
    fclose($pipes[0]);
    $saida = stream_get_contents($pipes[1]);
    $erro = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($processo);
    return ['idt' => $idt, 'acao' => $acao, 'codigo_retorno' => $codigo, 'saida' => trim((string) $saida . (($erro ?? '') !== '' ? "\n" . $erro : ''))];
}

function executarAcoesConcorrentes(array $configuracao, array $servidores, array $linhas, string $acao): array
{
    $maximoParalelo = 10;
    $fila = array_values($linhas);
    $indiceFila = 0;
    $ativos = [];
    $resultados = [];

    while ($indiceFila < count($fila) || count($ativos) > 0) {
        while ($indiceFila < count($fila) && count($ativos) < $maximoParalelo) {
            $linha = $fila[$indiceFila++];
            try {
                $indice = (int) ($linha['i'] ?? -1);
                $idt = trim((string) ($linha['idt'] ?? ''));
                if (!isset($servidores[$indice]) || preg_match('/^[A-Za-z0-9_.:-]+$/', $idt) !== 1) {
                    throw new RuntimeException('Nota ou servidor inválido.');
                }
                $preparacao = prepararExecucaoAcao(
                    $configuracao,
                    $servidores[$indice],
                    $idt,
                    (string) ($linha['modelo'] ?? '65'),
                    $acao
                );
                $pipes = [];
                $diretorioTrabalho = $preparacao['diretorio'];
                $cwdAnterior = getcwd();
                $chdirAplicado = $diretorioTrabalho !== '' && is_dir($diretorioTrabalho)
                    ? @chdir($diretorioTrabalho)
                    : false;
                $ambiente = null;
                if (PHP_OS_FAMILY === 'Linux') {
                    $ambiente = $_ENV;
                    $ambiente['DISPLAY'] = ':99';
                }
                $processo = @proc_open(
                    $preparacao['comando'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    $diretorioTrabalho !== '' ? $diretorioTrabalho : null,
                    $ambiente
                );
                if ($chdirAplicado && $cwdAnterior !== false) {
                    @chdir($cwdAnterior);
                }
                if (!is_resource($processo)) {
                    throw new RuntimeException('Não foi possível iniciar o AutoNFe.');
                }
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $ativos[] = [
                    'processo' => $processo,
                    'pipes' => $pipes,
                    'idt' => $idt,
                    'acao' => $acao,
                    'saida' => '',
                    'erro' => ''
                ];
            } catch (Throwable $e) {
                $resultados[] = [
                    'idt' => (string) ($linha['idt'] ?? ''),
                    'acao' => $acao,
                    'codigo_retorno' => -1,
                    'saida' => $e->getMessage()
                ];
            }
        }

        if (count($ativos) === 0) {
            continue;
        }

        usleep(200000);
        foreach ($ativos as $indiceAtivo => &$ativo) {
            $trechoSaida = stream_get_contents($ativo['pipes'][1]);
            $trechoErro = stream_get_contents($ativo['pipes'][2]);
            if ($trechoSaida !== false) {
                $ativo['saida'] .= $trechoSaida;
            }
            if ($trechoErro !== false) {
                $ativo['erro'] .= $trechoErro;
            }

            $statusProcesso = proc_get_status($ativo['processo']);
            if (is_array($statusProcesso) && !empty($statusProcesso['running'])) {
                continue;
            }

            $trechoSaida = stream_get_contents($ativo['pipes'][1]);
            $trechoErro = stream_get_contents($ativo['pipes'][2]);
            if ($trechoSaida !== false) {
                $ativo['saida'] .= $trechoSaida;
            }
            if ($trechoErro !== false) {
                $ativo['erro'] .= $trechoErro;
            }
            fclose($ativo['pipes'][1]);
            fclose($ativo['pipes'][2]);
            $codigo = proc_close($ativo['processo']);
            $resultados[] = [
                'idt' => $ativo['idt'],
                'acao' => $ativo['acao'],
                'codigo_retorno' => $codigo,
                'saida' => trim($ativo['saida'] . ($ativo['erro'] !== '' ? "\n" . $ativo['erro'] : ''))
            ];
            unset($ativos[$indiceAtivo]);
        }
        unset($ativo);
        $ativos = array_values($ativos);
    }

    return $resultados;
}

function obterOperacaoWrapper(string $acao): string
{
    $operacoes = [
        'consultarX' => 'consultar',
        'enviarX' => 'enviar',
        'gerarEnviar' => 'gerarEnviar',
        'cancelarX' => 'cancelar',
        'inutilizarX' => 'inutilizar',
        'cancelarInutilizar' => 'cancelarInutilizar',
        'removercancelamento' => 'cancelarinutilizar',
        'acerto_w' => 'gerar',
        'acerto_v' => 'gerar'
    ];
    if (!isset($operacoes[$acao])) {
        throw new RuntimeException('Ação inválida.');
    }
    return $operacoes[$acao];
}

function prepararRequisicaoWrapper(array $configuracao, array $servidor, string $idt, string $acao, string $modelo): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL não está habilitada no PHP.');
    }
    if (preg_match('/^[0-9]+$/', $idt) !== 1 || $idt === '0') {
        throw new RuntimeException('IDT inválido para o wrapper.');
    }
    if ($acao === 'acerto_w' && $modelo === '55') {
        throw new RuntimeException('Ação Offline não permitida para notas do modelo 55.');
    }
    $url = trim((string) ($configuracao['autonfe_wrapper_url'] ?? ''));
    if ($url === '') {
        throw new RuntimeException('URL do wrapper não configurada no config.json.');
    }
    return [
        'url' => $url,
        'payload' => json_encode([
            'idt' => $idt,
            'host' => (string) ($servidor['endereco'] ?? ''),
            'banco' => (string) ($servidor['database'] ?? ''),
            'porta' => (string) ($servidor['porta'] ?? 3306),
            'operacao' => obterOperacaoWrapper($acao)
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    ];
}

function interpretarRespostaWrapper(string $corpo, int $codigoHttp, string $erroCurl, string $idt, string $acao, int $indiceServidor): array
{
    if ($erroCurl !== '') {
        return ['i' => $indiceServidor, 'idt' => $idt, 'acao' => $acao, 'codigo_retorno' => -1, 'saida' => $erroCurl];
    }
    $dados = json_decode($corpo, true);
    if (!is_array($dados)) {
        return ['i' => $indiceServidor, 'idt' => $idt, 'acao' => $acao, 'codigo_retorno' => -1, 'saida' => 'Resposta inválida do wrapper (HTTP ' . $codigoHttp . ').'];
    }
    $sucesso = !empty($dados['sucesso']) && $codigoHttp >= 200 && $codigoHttp < 300;
    $mensagens = [];
    foreach (['mensagem', 'saida', 'erro_processo'] as $campo) {
        if (isset($dados[$campo]) && trim((string) $dados[$campo]) !== '') {
            $mensagens[] = trim((string) $dados[$campo]);
        }
    }
    $resultado = [
        'i' => $indiceServidor,
        'idt' => $idt,
        'acao' => $acao,
        'codigo_retorno' => $sucesso ? 0 : (isset($dados['codigo']) && (int) $dados['codigo'] !== 0 ? (int) $dados['codigo'] : -1),
        'saida' => implode("\n", $mensagens)
    ];
    if (isset($dados['retorno']) && is_array($dados['retorno'])) {
        unset($dados['retorno']['xml']);
        $resultado['retorno'] = $dados['retorno'];
    }
    return $resultado;
}

function executarAcoesWrapperConcorrentes(array $configuracao, array $servidores, array $linhas, string $acao): array
{
    if (!function_exists('curl_multi_init')) {
        throw new RuntimeException('A extensão cURL não está habilitada no PHP.');
    }
    $maximoParalelo = 10;
    $fila = array_values($linhas);
    $indiceFila = 0;
    $ativos = [];
    $resultados = [];
    $multi = curl_multi_init();

    try {
        while ($indiceFila < count($fila) || count($ativos) > 0) {
            while ($indiceFila < count($fila) && count($ativos) < $maximoParalelo) {
                $linha = $fila[$indiceFila++];
                try {
                    $indice = (int) ($linha['i'] ?? -1);
                    $idt = trim((string) ($linha['idt'] ?? ''));
                    if (!isset($servidores[$indice])) {
                        throw new RuntimeException('Servidor inválido.');
                    }
                    $acaoExecutada = $acao;
                    if ($acao === 'acerto_w' && (string) ($linha['modelo'] ?? '') === '55') {
                        $acaoExecutada = 'acerto_v';
                    }
                    $requisicao = prepararRequisicaoWrapper(
                        $configuracao,
                        $servidores[$indice],
                        $idt,
                        $acaoExecutada,
                        (string) ($linha['modelo'] ?? '')
                    );
                    $curl = curl_init($requisicao['url']);
                    curl_setopt_array($curl, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $requisicao['payload'],
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CONNECTTIMEOUT => 30,
                        CURLOPT_TIMEOUT => 900
                    ]);
                    curl_multi_add_handle($multi, $curl);
                    $ativos[spl_object_id($curl)] = [
                        'curl' => $curl,
                        'i' => $indice,
                        'idt' => $idt,
                        'acao' => $acaoExecutada
                    ];
                } catch (Throwable $e) {
                    $resultados[] = [
                        'idt' => (string) ($linha['idt'] ?? ''),
                        'i' => (int) ($linha['i'] ?? -1),
                        'acao' => $acaoExecutada ?? $acao,
                        'codigo_retorno' => -1,
                        'saida' => $e->getMessage()
                    ];
                }
            }

            do {
                $status = curl_multi_exec($multi, $executando);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($executando > 0) {
                $selecionado = curl_multi_select($multi, 1.0);
                if ($selecionado === -1) {
                    usleep(100000);
                }
            }

            while ($informacao = curl_multi_info_read($multi)) {
                $curl = $informacao['handle'];
                $chave = spl_object_id($curl);
                $ativo = $ativos[$chave];
                $resultados[] = interpretarRespostaWrapper(
                    (string) curl_multi_getcontent($curl),
                    (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
                    (string) curl_error($curl),
                    $ativo['idt'],
                    $ativo['acao'],
                    $ativo['i']
                );
                curl_multi_remove_handle($multi, $curl);
                curl_close($curl);
                unset($ativos[$chave]);
            }
        }
    } finally {
        foreach ($ativos as $ativo) {
            curl_multi_remove_handle($multi, $ativo['curl']);
            curl_close($ativo['curl']);
        }
        curl_multi_close($multi);
    }

    return $resultados;
}

mysqli_report(MYSQLI_REPORT_OFF);
$configuracao = lerConfiguracao();
$servidores = carregarServidores();

if (isset($_GET['ajax']) && (string) $_GET['ajax'] === '1') {
    $indice = isset($_GET['i']) ? (int) $_GET['i'] : -1;
    $dataInicio = normalizarData((string) ($_GET['data_inicio'] ?? ''), date('Y-m-d', strtotime('-1 day')));
    $dataFim = normalizarData((string) ($_GET['data_fim'] ?? ''), date('Y-m-d'));
    [$dataInicio, $dataFim] = ajustarPeriodo($dataInicio, $dataFim);
    if (!isset($servidores[$indice])) {
        responderJson(['ok' => false, 'erro' => 'Servidor inválido.'], 400);
    }
    $servidor = $servidores[$indice];
    $conexao = conectar((string) $servidor['endereco'], (string) $configuracao['mysql_usuario'], (string) $configuracao['mysql_senha'], (int) $servidor['porta']);
    if (!$conexao instanceof mysqli) {
        responderJson(['ok' => false, 'i' => $indice, 'erro' => 'Não foi possível conectar ao servidor.'], 500);
    }
    try {
        $idtsJson = trim((string) ($_GET['idts'] ?? ''));
        $idts = $idtsJson !== '' ? json_decode($idtsJson, true) : null;
        $notas = is_array($idts) && count($idts) > 0
            ? carregarNotasPorIdts($conexao, (string) $servidor['database'], $idts)
            : carregarNotas($conexao, (string) $servidor['database'], $dataInicio, $dataFim);
        $conexao->close();
        foreach ($notas as &$nota) {
            $nota['i'] = $indice;
            $nota['servidor'] = $servidor['nome'];
            $nota['grupo'] = $servidor['grupo'];
            $nota['database'] = $servidor['database'];
            $nota['endereco'] = $servidor['endereco'];
            $nota['porta'] = (string) $servidor['porta'];
        }
        unset($nota);
        responderJson(['ok' => true, 'i' => $indice, 'linhas' => $notas]);
    } catch (Throwable $e) {
        $conexao->close();
        responderJson(['ok' => false, 'i' => $indice, 'erro' => $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['ajax_action'] ?? '') === '1') {
    try {
        $acao = trim((string) ($_POST['acao'] ?? ''));
        $linhas = json_decode((string) ($_POST['linhas'] ?? '[]'), true);
        if (!is_array($linhas) || count($linhas) === 0) {
            responderJson(['ok' => false, 'erro' => 'Selecione pelo menos uma nota.'], 400);
        }
        $resultados = executarAcoesWrapperConcorrentes($configuracao, $servidores, $linhas, $acao);
        $acaoOk = true;
        foreach ($resultados as $resultado) {
            if ((int) ($resultado['codigo_retorno'] ?? -1) !== 0) {
                $acaoOk = false;
                break;
            }
        }
        responderJson(['ok' => $acaoOk, 'resultados' => $resultados]);
    } catch (Throwable $e) {
        error_log('Erro ao executar ação em consultar_notas_todos_servidores.php: ' . $e->getMessage());
        responderJson(['ok' => false, 'erro' => 'Erro interno ao executar a ação: ' . $e->getMessage()], 500);
    }
}

function filtroNome(string $nome): string
{
    $posicao = strpos($nome, '_');
    return $posicao === false ? $nome : substr($nome, $posicao + 1);
}

$dataInicio = normalizarData((string) ($_GET['data_inicio'] ?? ''), date('Y-m-d', strtotime('-1 day')));
$dataFim = normalizarData((string) ($_GET['data_fim'] ?? ''), date('Y-m-d'));
[$dataInicio, $dataFim] = ajustarPeriodo($dataInicio, $dataFim);
$dataInicioExibicao = date('d/m/Y', strtotime($dataInicio));
$dataFimExibicao = date('d/m/Y', strtotime($dataFim));
$filtroCliente = trim((string) ($_GET['filtro_cliente'] ?? ''));
$filtroTrecho = trim((string) ($_GET['filtro_trecho'] ?? ''));
$executarConsulta = isset($_GET['consultar']) && (string) $_GET['consultar'] === '1';
$clienteNormalizado = function_exists('mb_strtolower') ? mb_strtolower($filtroCliente, 'UTF-8') : strtolower($filtroCliente);
$trechoNormalizado = function_exists('mb_strtolower') ? mb_strtolower($filtroTrecho, 'UTF-8') : strtolower($filtroTrecho);
$servidoresFront = [];
foreach ($servidores as $indice => $servidor) {
    $nome = (string) $servidor['nome'];
    $nomeNormalizado = function_exists('mb_strtolower') ? mb_strtolower($nome, 'UTF-8') : strtolower($nome);
    $clienteNome = filtroNome($nome);
    $clienteNomeNormalizado = function_exists('mb_strtolower') ? mb_strtolower($clienteNome, 'UTF-8') : strtolower($clienteNome);
    if ($clienteNormalizado !== '' && $clienteNomeNormalizado !== $clienteNormalizado) {
        continue;
    }
    if ($trechoNormalizado !== '' && strpos($nomeNormalizado, $trechoNormalizado) === false) {
        continue;
    }
    if ($nome !== '' && $servidor['endereco'] !== '' && $servidor['database'] !== '') {
        $servidor['i'] = $indice;
        $servidoresFront[] = $servidor;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notas de Todos os Servidores</title>
<link rel="stylesheet" href="consultar_notas_detalhes.css?v=<?= (int) filemtime(__DIR__ . '/consultar_notas_detalhes.css') ?>">
<link rel="stylesheet" href="consultar_notas_todos_servidores.css">
<style>
/* Ajuste manual das larguras da tabela de notas: altere somente estes valores. */
:root {
    --largura-selecao: 85px;
    --largura-grupo: 130px;
    --largura-servidor: 120px;
    --largura-database: 140px;
    --largura-idt: 110px;
    --largura-cancelado: 90px;
    --largura-emissao: 110px;
    --largura-hora: 90px;
    --largura-nota: 90px;
    --largura-serie: 70px;
    --largura-modelo: 70px;
    --largura-origem: 80px;
    --largura-chave: 280px;
    --largura-operacao: 80px;
    --largura-status: 70px;
    --largura-descricao: 530px;
}
.all-server-filter { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
.all-server-filter .filter-item { flex:1 1 150px; min-width:140px; }
.all-server-filter .filter-item.botao-item { flex:0 0 auto; }
.all-server-filter input { width:100%; height:46px; padding:0 14px; border-radius:14px; border:1px solid rgba(132,150,181,.24); }
.all-server-table { width:calc(var(--largura-selecao) + var(--largura-grupo) + var(--largura-servidor) + var(--largura-database) + var(--largura-idt) + var(--largura-cancelado) + var(--largura-emissao) + var(--largura-hora) + var(--largura-nota) + var(--largura-serie) + var(--largura-modelo) + var(--largura-origem) + var(--largura-chave) + var(--largura-operacao) + var(--largura-status) + var(--largura-descricao)); min-width:calc(var(--largura-selecao) + var(--largura-grupo) + var(--largura-servidor) + var(--largura-database) + var(--largura-idt) + var(--largura-cancelado) + var(--largura-emissao) + var(--largura-hora) + var(--largura-nota) + var(--largura-serie) + var(--largura-modelo) + var(--largura-origem) + var(--largura-chave) + var(--largura-operacao) + var(--largura-status) + var(--largura-descricao)); table-layout:fixed; }
.all-server-table .col-servidor { min-width:var(--largura-servidor); }
.all-server-table .col-database { min-width:var(--largura-database); }
.all-server-table .col-grupo { min-width:var(--largura-grupo); }
.all-server-table .col-descricao { width:var(--largura-descricao); min-width:var(--largura-descricao); max-width:var(--largura-descricao); }
.all-server-table th,
.all-server-table td { overflow:hidden; text-overflow:ellipsis; }
.all-server-table td { white-space:nowrap; }
.all-server-table td.col-descricao { white-space:normal; overflow-wrap:anywhere; word-break:break-word; line-height:1.35; }
.all-server-table th:nth-child(1), .all-server-table td:nth-child(1) { width:var(--largura-selecao); }
.all-server-table th:nth-child(2), .all-server-table td:nth-child(2) { width:var(--largura-grupo); }
.all-server-table th:nth-child(3), .all-server-table td:nth-child(3) { width:var(--largura-servidor); }
.all-server-table th:nth-child(3), .all-server-table td:nth-child(3),
.all-server-table th:nth-child(4), .all-server-table td:nth-child(4) { font-size:11px; }
.all-server-table th:nth-child(4), .all-server-table td:nth-child(4) { width:var(--largura-database); }
.all-server-table th:nth-child(5), .all-server-table td:nth-child(5) { width:var(--largura-idt); }
.all-server-table th:nth-child(6), .all-server-table td:nth-child(6) { width:var(--largura-cancelado); }
.all-server-table th:nth-child(7), .all-server-table td:nth-child(7) { width:var(--largura-emissao); }
.all-server-table th:nth-child(8), .all-server-table td:nth-child(8) { width:var(--largura-hora); }
.all-server-table th:nth-child(9), .all-server-table td:nth-child(9) { width:var(--largura-nota); }
.all-server-table th:nth-child(10), .all-server-table td:nth-child(10) { width:var(--largura-serie); }
.all-server-table th:nth-child(11), .all-server-table td:nth-child(11) { width:var(--largura-modelo); }
.all-server-table th:nth-child(12), .all-server-table td:nth-child(12) { width:var(--largura-origem); }
.all-server-table th:nth-child(13), .all-server-table td:nth-child(13) { width:var(--largura-chave); font-size:11px; }
.all-server-table td.col-chave { overflow:visible; text-overflow:clip; white-space:nowrap; }
.all-server-table th:nth-child(14), .all-server-table td:nth-child(14) { width:var(--largura-operacao); }
.all-server-table th:nth-child(15), .all-server-table td:nth-child(15) { width:var(--largura-status); }
.all-server-table th:nth-child(16), .all-server-table td:nth-child(16) { width:var(--largura-descricao); font-size:11px; }
.all-server-table th:nth-child(2), .all-server-table td:nth-child(2),
.all-server-table th:nth-child(3), .all-server-table td:nth-child(3),
.all-server-table th:nth-child(4), .all-server-table td:nth-child(4),
.all-server-table th:nth-child(5), .all-server-table td:nth-child(5),
.all-server-table th:nth-child(6), .all-server-table td:nth-child(6),
.all-server-table th:nth-child(7), .all-server-table td:nth-child(7),
.all-server-table th:nth-child(8), .all-server-table td:nth-child(8),
.all-server-table th:nth-child(9), .all-server-table td:nth-child(9),
.all-server-table th:nth-child(11), .all-server-table td:nth-child(11),
.all-server-table th:nth-child(14), .all-server-table td:nth-child(14),
.all-server-table th:nth-child(15), .all-server-table td:nth-child(15) {
    white-space: nowrap;
    overflow: visible;
    text-overflow: clip;
}
.status-message { white-space:pre-wrap; }
</style>
<script>
var SERVIDORES = <?= json_encode($servidoresFront, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var DATA_INICIO = <?= json_encode($dataInicio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var DATA_FIM = <?= json_encode($dataFim, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var EXECUTAR_CONSULTA = <?= $executarConsulta ? 'true' : 'false' ?>;
var LINHAS = [];
var SELECIONADAS = new Set();
var CONCORRENCIA = 15;
 var FILTROS = { descricao:'', grupo:'', status:'', serie:'', operacao:'', modelo:'', origem:'', canceladas:false };
var CONSULTA_INTERROMPIDA = false;
var CONSULTA_EM_ANDAMENTO = false;
var ACOES_EM_ANDAMENTO = false;
var CONSULTA_PROCESSADOS = 0;
var CONSULTA_ERROS = 0;
var CONTROLADORES_CONSULTA = [];
var ACOES_INTERROMPIDAS = false;
var CONTROLADOR_ACAO = null;
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function chave(x){return [x.grupo,x.idt,x.origem,x.serie,x.modelo].map(function(v){return String(v==null?'':v).trim().toLowerCase();}).join('|');}
 function texto(v){return String(v==null?'':v).toLowerCase();}
 function formatarDataBrasileira(v){var valor=String(v==null?'':v).trim(),partes=valor.match(/^(\d{4})-(\d{2})-(\d{2})/);if(partes)return partes[3]+'/'+partes[2]+'/'+partes[1];partes=valor.match(/^(\d{2})[\/-](\d{2})[\/-](\d{4})/);return partes?partes[1]+'/'+partes[2]+'/'+partes[3]:valor;}
function compararTexto(a,b){return String(a==null?'':a).localeCompare(String(b==null?'':b),'pt-BR',{numeric:true,sensitivity:'base'});}
 function temCancelamento(valor){var data=String(valor==null?'':valor).trim();return data!==''&&data.indexOf('0000-00-00')!==0&&data.indexOf('00/00/0000')!==0;}
 function filtradas(){var linhas=LINHAS.filter(function(x){return (!FILTROS.descricao||texto(x.descricao).indexOf(texto(FILTROS.descricao))!==-1)&&(!FILTROS.grupo||texto(x.grupo).indexOf(texto(FILTROS.grupo))!==-1)&&(!FILTROS.status||String(x.status||'')===FILTROS.status)&&(!FILTROS.serie||String(x.serie||'')===FILTROS.serie)&&(!FILTROS.operacao||texto(x.operacao)===texto(FILTROS.operacao))&&(!FILTROS.modelo||String(x.modelo||'')===FILTROS.modelo)&&(!FILTROS.origem||String(x.origem||'')===FILTROS.origem)&&(!FILTROS.canceladas||temCancelamento(x.cancelado));});linhas.sort(function(a,b){var resultado=compararTexto(a.status,b.status);if(resultado!==0)return resultado;resultado=compararTexto(a.descricao,b.descricao);if(resultado!==0)return resultado;resultado=compararTexto(a.operacao,b.operacao);if(resultado!==0)return resultado;return compararTexto(a.grupo,b.grupo);});return linhas;}
function montarDataHoraNota(item){var emissao=String((item&&item.emissao)||'').trim(),hora=String((item&&item.hora)||'').trim();if(!emissao)return null;var partes=emissao.indexOf('-')!==-1?emissao.split('-'):emissao.split('/');if(partes.length<3)return null;var ano,mes,dia;if(String(partes[0]).length===4){ano=parseInt(partes[0],10);mes=parseInt(partes[1],10);dia=parseInt(partes[2],10);}else{dia=parseInt(partes[0],10);mes=parseInt(partes[1],10);ano=parseInt(partes[2],10);}if(!ano||!mes||!dia)return null;var hp=hora.split(':'),h=parseInt(hp[0]||'0',10),m=parseInt(hp[1]||'0',10),s=parseInt(hp[2]||'0',10),data=new Date(ano,mes-1,dia,isNaN(h)?0:h,isNaN(m)?0:m,isNaN(s)?0:s);return isNaN(data.getTime())?null:data;}
function montarClasseLinha(item){var classes=[];if(String(item.modelo||'')==='65')classes.push('linha-modelo-65');var dataHora=montarDataHoraNota(item);if(dataHora&&Math.abs(Date.now()-dataHora.getTime())<=(10*60*1000))classes.push('linha-alerta-horario');if(item.destacar_vermelho)classes.push('linha-problema');return classes.join(' ');}
function deduplicarLinhas(){var vistos={};LINHAS=LINHAS.filter(function(x){var k=chave(x);if(vistos[k])return false;vistos[k]=true;return true;});}
function atualizarContadores(){var vis=filtradas(); document.getElementById('totalNotas').textContent=LINHAS.length; document.getElementById('totalVisiveis').textContent=vis.length; document.getElementById('totalSelecionadas').textContent=SELECIONADAS.size;}
 function montarLinkOrigem(item){var origem=String(item&&item.origem||'').trim(),grupo=String(item&&item.grupo||'').trim();if(origem===''||grupo==='')return esc(origem);var url='consultar_emissores.php?grupo='+encodeURIComponent(grupo)+'&origem='+encodeURIComponent(origem);return '<a class="text-link" href="'+esc(url)+'" target="_blank" rel="noopener noreferrer">'+esc(origem)+'</a>';}
 function render(){var corpo=document.getElementById('corpoNotas'), linhas=filtradas(), html=''; linhas.forEach(function(x){var k=chave(x), checked=SELECIONADAS.has(k)?' checked':''; html+='<tr class="'+esc(montarClasseLinha(x))+'"><td class="col-selecao"><input class="check-nota" type="checkbox" data-chave="'+esc(k)+'"'+checked+'></td><td class="col-grupo">'+esc(x.grupo)+'</td><td class="col-servidor">'+esc(x.endereco)+'</td><td class="col-database">'+esc(x.database)+'</td><td class="col-idt">'+esc(x.idt)+'</td><td>'+esc(formatarDataBrasileira(x.cancelado))+'</td><td class="col-emissao">'+esc(formatarDataBrasileira(x.emissao))+'</td><td>'+esc(x.hora)+'</td><td>'+esc(x.nota)+'</td><td>'+esc(x.serie)+'</td><td>'+esc(x.modelo)+'</td><td>'+montarLinkOrigem(x)+'</td><td class="col-chave">'+esc(x.chave)+'</td><td>'+esc(x.operacao)+'</td><td>'+esc(x.status)+'</td><td class="col-descricao">'+esc(x.descricao)+'</td></tr>';}); corpo.innerHTML=html; atualizarContadores(); document.getElementById('mensagemVazia').style.display=linhas.length?'none':'flex';bloquearSelecaoDuranteAcoes(ACOES_EM_ANDAMENTO);}
 function status(t,c){var painel=document.querySelector('.status-consulta');if(painel){painel.classList.remove('status-ok','status-erro','status-andamento');painel.classList.add(c==='erro'?'status-erro':(c==='andamento'?'status-andamento':'status-ok'));}var s=document.getElementById('statusExecucao');if(s){s.textContent=t;s.classList.remove('status-mensagem-atualizada');void s.offsetWidth;s.classList.add('status-mensagem-atualizada');}var cardInterromper=document.getElementById('cardInterromper'),botaoInterromper=document.getElementById('botaoInterromper'),interromperAtivo=c==='andamento'&&(CONSULTA_EM_ANDAMENTO||ACOES_EM_ANDAMENTO);if(cardInterromper)cardInterromper.style.display='flex';if(botaoInterromper){botaoInterromper.disabled=!interromperAtivo;botaoInterromper.classList.toggle('interromper-ativo',interromperAtivo);}var e=document.getElementById('mensagemExecucaoBox');e.textContent=t;e.className='status-message '+(c||'ok');e.style.display=t?'block':'none';}
 function alternarCardAguarde(visivel){var card=document.getElementById('cardAguardeConsulta');if(card)card.style.display=visivel?'flex':'none';var painel=card?card.closest('.info-panel'):null;if(painel)painel.classList.toggle('aguarde-ativo',visivel);}
function bloquearAcoesDuranteConsulta(bloquear){document.querySelectorAll('.actions-panel button').forEach(function(botao){botao.disabled=bloquear;botao.classList.toggle('botao-desabilitado',bloquear);});}
function bloquearSelecaoDuranteAcoes(bloquear){document.querySelectorAll('.check-nota, #checkTopo').forEach(function(checkbox){checkbox.disabled=bloquear;});document.querySelectorAll('.all-server-table .check-actions .check-link').forEach(function(link){link.style.pointerEvents=bloquear?'none':'';link.style.opacity=bloquear?'0.45':'';});}
function carregarServidor(item){if(CONSULTA_INTERROMPIDA)return Promise.resolve(null);status('Consultando '+item.nome+' | '+item.database+'...','andamento');var controlador=typeof AbortController==='function'?new AbortController():null;if(controlador)CONTROLADORES_CONSULTA.push(controlador);var opcoes={cache:'no-store',headers:{Accept:'application/json'}};if(controlador)opcoes.signal=controlador.signal;return fetch('consultar_notas_todos_servidores.php?ajax=1&i='+encodeURIComponent(item.i)+'&data_inicio='+encodeURIComponent(DATA_INICIO)+'&data_fim='+encodeURIComponent(DATA_FIM),opcoes).then(function(r){return r.json();}).then(function(p){if(!p.ok)throw new Error(item.nome+': '+(p.erro||'erro'));LINHAS=LINHAS.filter(function(x){return String(x.i)!==String(item.i);}).concat(p.linhas||[]);deduplicarLinhas();render();CONSULTA_PROCESSADOS++;status('Servidores consultados: '+CONSULTA_PROCESSADOS+'/'+SERVIDORES.length+' | '+item.nome+' | '+p.linhas.length+' nota(s) encontrada(s).','andamento');return p;}).catch(function(e){if(e&&e.name==='AbortError')return null;CONSULTA_PROCESSADOS++;CONSULTA_ERROS++;status('Servidores consultados: '+CONSULTA_PROCESSADOS+'/'+SERVIDORES.length+' | '+(e.message||'Erro na consulta.'),'erro');return null;});}
 function interromperConsulta(){var interrompeuConsulta=CONSULTA_EM_ANDAMENTO,interrompeuAcao=ACOES_EM_ANDAMENTO;CONSULTA_INTERROMPIDA=interrompeuConsulta||CONSULTA_INTERROMPIDA;ACOES_INTERROMPIDAS=interrompeuAcao||ACOES_INTERROMPIDAS;CONTROLADORES_CONSULTA.forEach(function(controlador){try{controlador.abort();}catch(e){}});CONTROLADORES_CONSULTA=[];if(CONTROLADOR_ACAO){try{CONTROLADOR_ACAO.abort();}catch(e){}}if(interrompeuAcao){status('Ações do AutoNFe interrompidas pelo usuário. As operações já iniciadas podem continuar no servidor.','erro');}else if(interrompeuConsulta){status('Consulta interrompida pelo usuário. Servidores processados: '+CONSULTA_PROCESSADOS+'/'+SERVIDORES.length+'.','erro');}}
 async function consultar(){CONSULTA_EM_ANDAMENTO=true;bloquearAcoesDuranteConsulta(true);alternarCardAguarde(true);CONSULTA_INTERROMPIDA=false;ACOES_INTERROMPIDAS=false;CONSULTA_PROCESSADOS=0;CONSULTA_ERROS=0;CONTROLADORES_CONSULTA=[];LINHAS=[];limparSelecao();status('Preparando consulta de '+SERVIDORES.length+' servidor(es), com até '+CONCORRENCIA+' consultas simultâneas...','andamento');var fila=SERVIDORES.slice(),ativos=[];for(var i=0;i<Math.min(CONCORRENCIA,Math.max(1,fila.length));i++)ativos.push((async function(){while(fila.length&&!CONSULTA_INTERROMPIDA){var item=fila.shift();await carregarServidor(item);}})());await Promise.all(ativos);CONTROLADORES_CONSULTA=[];CONSULTA_EM_ANDAMENTO=false;bloquearAcoesDuranteConsulta(false);alternarCardAguarde(false);if(CONSULTA_INTERROMPIDA){status('Consulta interrompida pelo usuário. Servidores processados: '+CONSULTA_PROCESSADOS+'/'+SERVIDORES.length+'.','erro');return;}if(CONSULTA_ERROS>0){status('Consulta finalizada com '+CONSULTA_ERROS+' erro(s): '+CONSULTA_PROCESSADOS+' servidor(es) processado(s) e '+LINHAS.length+' nota(s) carregada(s).','erro');return;}status('Consulta finalizada: '+CONSULTA_PROCESSADOS+' servidor(es) consultado(s) e '+LINHAS.length+' nota(s) carregada(s).','ok');}
 function aplicar(id,valor){FILTROS[id]=valor;render();}
 function alternarSomenteCanceladas(){FILTROS.canceladas=!FILTROS.canceladas;var link=document.getElementById('filtroSomenteCanceladas');if(link)link.classList.toggle('filtro-ativo',FILTROS.canceladas);render();}
function marcarTodos(valor){if(ACOES_EM_ANDAMENTO)return;filtradas().forEach(function(x){var k=chave(x);if(valor)SELECIONADAS.add(k);else SELECIONADAS.delete(k);});var topo=document.getElementById('checkTopo');if(topo)topo.checked=!!valor;render();}
function limparSelecao(){SELECIONADAS.clear();var topo=document.getElementById('checkTopo');if(topo)topo.checked=false;render();}
 function renderizarAcoesAtivas(linhas,acao){var painel=document.getElementById('resultadoAcoes');var corpo=document.getElementById('resultadoAcoesBody');if(!painel||!corpo)return;linhas=Array.isArray(linhas)?linhas:[];var html='';for(var i=0;i<linhas.length;i+=2){var primeira=linhas[i],segunda=linhas[i+1];html+='<tr><td>'+esc(primeira.idt||'')+'</td><td>'+esc(acao||'')+'</td><td>'+(segunda?esc(segunda.idt||''):'')+'</td><td>'+(segunda?esc(acao||''):'')+'</td></tr>';}corpo.innerHTML=html;painel.style.display=linhas.length?'block':'none';}
function atualizarNotasComRetornos(resultados){var atualizacoes={},removidas={};var terminais=['100','101','102','150','155'];(resultados||[]).forEach(function(resultado){var retorno=resultado&&resultado.retorno;if(!retorno||retorno.idt==null)return;var atual=LINHAS.find(function(x){return String(x.i)===String(resultado.i)&&String(x.idt)===String(retorno.idt);});if(!atual)return;var atualizada=Object.assign({},atual);if(retorno.chave!=null)atualizada.chave=String(retorno.chave);if(retorno.origem!=null)atualizada.origem=String(retorno.origem);if(retorno.status!=null)atualizada.status=String(retorno.status);if(retorno.status_descricao!=null)atualizada.descricao=String(retorno.status_descricao);if(retorno.cancelamento!==undefined)atualizada.cancelado=retorno.cancelamento==null?'':String(retorno.cancelamento);if(terminais.indexOf(String(atualizada.status||''))!==-1)removidas[chave(atual)]=true;else atualizacoes[chave(atual)]=atualizada;});LINHAS=LINHAS.filter(function(x){return !removidas[chave(x)];}).map(function(x){return atualizacoes[chave(x)]||x;});deduplicarLinhas();render();}
 function lerRespostaJson(resposta){return resposta.text().then(function(corpo){var textoResposta=String(corpo||'').trim();if(textoResposta==='')throw new Error('O servidor retornou uma resposta vazia (HTTP '+resposta.status+').');try{return JSON.parse(textoResposta);}catch(e){throw new Error('O servidor retornou uma resposta inválida (HTTP '+resposta.status+').');}});}
  function executar(acao){if(CONSULTA_EM_ANDAMENTO||ACOES_EM_ANDAMENTO)return false;if(!SELECIONADAS.size){alert('Selecione pelo menos uma nota.');return false;}var selecionadas=LINHAS.filter(function(x){return SELECIONADAS.has(chave(x));});var tudoOk=true;ACOES_EM_ANDAMENTO=true;ACOES_INTERROMPIDAS=false;bloquearSelecaoDuranteAcoes(true);status('Preparando '+selecionadas.length+' nota(s), em lotes de até 10 simultâneas...','andamento');document.querySelectorAll('.actions-panel button').forEach(function(b){b.disabled=true;});(async function(){try{for(var inicio=0;inicio<selecionadas.length&&!ACOES_INTERROMPIDAS;inicio+=10){var lote=selecionadas.slice(inicio,inicio+10);var pendentes=selecionadas.slice(inicio);renderizarAcoesAtivas(lote,acao);status('Executando '+acao+': lote '+(Math.floor(inicio/10)+1)+' | '+lote.length+' nota(s) simultâneas.','andamento');var controladorAcao=typeof AbortController==='function'?new AbortController():null;CONTROLADOR_ACAO=controladorAcao;var opcoesAcao={method:'POST',body:new URLSearchParams({ajax_action:'1',acao:acao,linhas:JSON.stringify(lote)}),headers:{Accept:'application/json'}};if(controladorAcao)opcoesAcao.signal=controladorAcao.signal;var resposta=await fetch('consultar_notas_todos_servidores.php',opcoesAcao);CONTROLADOR_ACAO=null;var dados=await lerRespostaJson(resposta);if(!dados.ok){tudoOk=false;status('Falha ao executar '+acao+': '+(dados.erro||'erro não informado.'),'erro');}(dados.resultados||[]).forEach(function(resultado){if(Number(resultado.codigo_retorno||-1)!==0)tudoOk=false;});atualizarNotasComRetornos(dados.resultados||[]);renderizarAcoesAtivas(pendentes.slice(lote.length),acao);if(ACOES_INTERROMPIDAS)break;}limparSelecao();renderizarAcoesAtivas([],acao);if(ACOES_INTERROMPIDAS){status('Ações do AutoNFe interrompidas pelo usuário. As operações já iniciadas podem continuar no servidor.','erro');}else{status('Ação '+acao+' concluída para '+selecionadas.length+' nota(s).',tudoOk?'ok':'erro');}}catch(e){limparSelecao();renderizarAcoesAtivas([],acao);if(e&&e.name==='AbortError'){status('Ações do AutoNFe interrompidas pelo usuário. As operações já iniciadas podem continuar no servidor.','erro');}else{status('Erro ao executar a ação: '+(e.message||'erro não informado.'),'erro');}}finally{CONTROLADOR_ACAO=null;ACOES_EM_ANDAMENTO=false;bloquearSelecaoDuranteAcoes(false);document.querySelectorAll('.actions-panel button').forEach(function(b){b.disabled=false;});}})();return false;}
window.addEventListener('change',function(e){if(!e.target.classList.contains('check-nota'))return;if(ACOES_EM_ANDAMENTO){e.target.checked=!e.target.checked;return;}var k=e.target.getAttribute('data-chave');if(e.target.checked)SELECIONADAS.add(k);else SELECIONADAS.delete(k);atualizarContadores();});
window.addEventListener('DOMContentLoaded',function(){document.getElementById('checkTopo').addEventListener('change',function(){marcarTodos(this.checked);});if(EXECUTAR_CONSULTA){var url=new URL(window.location.href);url.searchParams.delete('consultar');window.history.replaceState({},document.title,url.toString());consultar();}});
</script>
</head>
<body>
<div class="app-shell">
<header class="topbar">
<div class="title-row"><div class="title-main"><h1>Notas com problema</h1><p>Consulta agrupada de todos os servidores em uma única tela.</p></div><div class="title-status-slot"><div class="info-card status-consulta"><div class="status-consulta-conteudo"><span class="status-dot"></span><div class="status-text" id="statusExecucao"><?= $executarConsulta ? 'Preparando consulta dos servidores selecionados.' : 'Clique em Consultar para iniciar a consulta.' ?></div></div><div id="mensagemExecucaoBox" class="status-message" style="display:none"></div></div></div><div class="header-tags"><span class="chip"><span>Servidores</span> <strong><?= count($servidoresFront) ?></strong></span><span class="chip"><span>Período</span> <strong><?= htmlspecialchars($dataInicioExibicao . ' a ' . $dataFimExibicao, ENT_QUOTES, 'UTF-8') ?></strong></span></div></div>
<div class="header-grid">
<section class="panel actions-panel">
<form class="all-server-filter" method="get" action="">
<input type="hidden" name="consultar" value="1">
<div class="filter-item filtro-cliente"><label>CLIENTE (EXATO)</label><input name="filtro_cliente" value="<?= htmlspecialchars($filtroCliente, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex.: pelanda22"></div>
<div class="filter-item filtro-trecho"><label>CLIENTE (LIKE)</label><input name="filtro_trecho" value="<?= htmlspecialchars($filtroTrecho, ENT_QUOTES, 'UTF-8') ?>" placeholder="Like no nome original"></div>
<div class="filter-item filtro-data"><label>Data inicial</label><input type="date" name="data_inicio" value="<?= htmlspecialchars($dataInicio, ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="filter-item filtro-data"><label>Data final</label><input type="date" name="data_fim" value="<?= htmlspecialchars($dataFim, ENT_QUOTES, 'UTF-8') ?>"></div>
<div class="filter-item botao-item"><label>&nbsp;</label><button class="botao" type="submit">Consultar</button></div>
</form>
 <div class="filter-box" style="margin-top:14px"><div class="filter-grid-detalhes"><div class="filter-item filtro-descricao"><label>Descrição</label><input maxlength="100" oninput="aplicar('descricao',this.value)"></div><div class="filter-item grupo"><label>Grupo</label><input oninput="aplicar('grupo',this.value)"></div><div class="filter-item operacao"><label>Operação</label><input maxlength="1" oninput="this.value=this.value.toUpperCase();aplicar('operacao',this.value)"></div><div class="filter-item status"><label>Status</label><input maxlength="3" oninput="this.value=this.value.replace(/\D/g,'');aplicar('status',this.value)"></div><div class="filter-item serie"><label>Série</label><input maxlength="3" oninput="this.value=this.value.replace(/\D/g,'');aplicar('serie',this.value)"></div><div class="filter-item modelo"><label>Modelo</label><input maxlength="5" oninput="this.value=this.value.replace(/\D/g,'');aplicar('modelo',this.value)"></div><div class="filter-item origem"><label>Origem</label><input maxlength="3" oninput="this.value=this.value.replace(/\D/g,'');aplicar('origem',this.value)"></div><div class="filter-item limpar"><label>&nbsp;</label><button class="botao" type="button" onclick="document.querySelectorAll('.filter-box input').forEach(function(x){x.value='';});FILTROS={descricao:'',grupo:'',status:'',serie:'',operacao:'',modelo:'',origem:'',canceladas:false};document.getElementById('filtroSomenteCanceladas').classList.remove('filtro-ativo');render();">Limpar</button></div></div></div>
 <div class="action-grid"><button class="botao" type="button" onclick="executar('acerto_w')">Offline (65) &amp; Normal (55)<small>(acerto_w)</small></button><button class="botao" type="button" onclick="executar('consultarX')">Consultar<small>(consultarX)</small></button><button class="botao" type="button" onclick="executar('enviarX')">Enviar<small>(enviarX)</small></button><button class="botao" type="button" onclick="executar('gerarEnviar')">Gerar &amp; Enviar<small>(gerarEnviar Wrapper)</small></button><button class="botao" type="button" onclick="executar('acerto_v')">Normal<small>(acerto_v)</small></button><button class="botao" type="button" onclick="executar('cancelarX')">Cancelar<small>(cancelarX)</small></button><button class="botao" type="button" onclick="executar('inutilizarX')">Inutilizar<small>(inutilizarX)</small></button><button class="botao" type="button" onclick="executar('cancelarInutilizar')">Cancelar ou Inutilizar<small>(cancelarInutilizar Wrapper)</small></button></div>
<div class="mini-actions"><span class="text-link desabilitado" aria-disabled="true">Remover Cancelamento</span><span id="filtroSomenteCanceladas" class="text-link" onclick="alternarSomenteCanceladas()">Somente Canceladas</span></div>
</section>
<section class="panel info-panel"><div class="info-card ok"><div class="label">Total de notas</div><div class="value" id="totalNotas">0</div></div><div class="info-card warn"><div class="label">Visíveis</div><div class="value" id="totalVisiveis">0</div></div><div class="info-card danger"><div class="label">Selecionadas</div><div class="value" id="totalSelecionadas">0</div></div><div class="info-card card-interromper" id="cardInterromper"><button id="botaoInterromper" class="botao botao-interromper" type="button" onclick="interromperConsulta()" disabled>Interromper</button></div><div class="info-card card-aguarde-consulta" id="cardAguardeConsulta" style="display:none"><div class="aguarde-consulta-animacao"><span></span><span></span><span></span></div><div class="aguarde-consulta-texto">Aguarde, consultando...</div><div class="aguarde-consulta-detalhe">Os servidores estão sendo consultados. Esta mensagem desaparecerá ao finalizar.</div></div><div class="info-card resultado-acoes" id="resultadoAcoes" style="display:none"><div class="resultado-acoes-tabela"><table><thead><tr><th>IDT</th><th>Ação</th><th>IDT</th><th>Ação</th></tr></thead><tbody id="resultadoAcoesBody"></tbody></table></div></div></section>
</div>
</header>
<main class="content-shell"><section class="surface"><div class="table-wrap"><table class="all-server-table"><thead><tr><th class="col-selecao"><div class="check-header"><input id="checkTopo" type="checkbox"><div class="check-actions"><span class="check-link" onclick="marcarTodos(true)">Todas</span><span class="check-link" onclick="marcarTodos(false)">Nenhuma</span></div></div></th><th>Grupo</th><th>Servidor</th><th>Database</th><th>IDT</th><th>Cancelado</th><th>Emissão</th><th>Hora</th><th>Nota</th><th>Série</th><th>Modelo</th><th>Origem</th><th>Chave</th><th>Operação</th><th>Status</th><th>Descrição</th></tr></thead><tbody id="corpoNotas"></tbody></table></div><div class="empty-state" id="mensagemVazia">Nenhuma nota encontrada.</div></section></main>
</div>
</body>
</html>
