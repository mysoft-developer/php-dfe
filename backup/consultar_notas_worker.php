<?php

declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$arquivoConfiguracao = __DIR__ . '/config.json';
$arquivoLogPhp = __DIR__ . '/consultar_notas_worker.log';
ini_set('log_errors', '1');
ini_set('error_log', $arquivoLogPhp);

function responderJson(array $dados, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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

function obterPresencaTabelas(mysqli $conexao, string $nomeBanco): array
{
    $sql = "
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = ?
          AND table_name IN ('notas_fiscais_eletronicas', 'notas_fiscais')
    ";

    $stmt = $conexao->prepare($sql);
    if ($stmt === false) {
        return ['notas_fiscais_eletronicas' => false, 'notas_fiscais' => false, 'erro' => 'Falha ao preparar verificação de tabelas.'];
    }

    $stmt->bind_param('s', $nomeBanco);
    $ok = $stmt->execute();

    if ($ok === false) {
        $stmt->close();
        return ['notas_fiscais_eletronicas' => false, 'notas_fiscais' => false, 'erro' => 'Falha ao executar verificação de tabelas.'];
    }

    $resultado = $stmt->get_result();
    $temNotasEletronicas = false;
    $temNotas = false;

    if ($resultado !== false) {
        while ($linha = $resultado->fetch_assoc()) {
            $nomeTabela = isset($linha['table_name']) ? (string)$linha['table_name'] : '';
            if ($nomeTabela === 'notas_fiscais_eletronicas') {
                $temNotasEletronicas = true;
            }
            if ($nomeTabela === 'notas_fiscais') {
                $temNotas = true;
            }
        }
        $resultado->free();
    }

    $stmt->close();

    return [
        'notas_fiscais_eletronicas' => $temNotasEletronicas,
        'notas_fiscais' => $temNotas,
        'erro' => ''
    ];
}

if (!file_exists($arquivoConfiguracao)) {
    responderJson(['ok' => false, 'erro' => 'Arquivo config.json não encontrado.'], 500);
}

$conteudoJson = file_get_contents($arquivoConfiguracao);
if ($conteudoJson === false) {
    responderJson(['ok' => false, 'erro' => 'Não foi possível ler o config.json.'], 500);
}

$configuracao = json_decode($conteudoJson, true);
if (!is_array($configuracao)) {
    responderJson(['ok' => false, 'erro' => 'JSON inválido no config.json.'], 500);
}

if (
    empty($configuracao['mysql_usuario']) ||
    !array_key_exists('mysql_senha', $configuracao) ||
    empty($configuracao['servidores']) ||
    !is_array($configuracao['servidores'])
) {
    responderJson(['ok' => false, 'erro' => 'Configuração incompleta.'], 500);
}

mysqli_report(MYSQLI_REPORT_OFF);

$acao = isset($_GET['acao']) ? trim((string)$_GET['acao']) : '';
$indiceServidor = isset($_GET['s']) ? (int)$_GET['s'] : -1;
$filtroDatabase = isset($_GET['database']) ? trim((string)$_GET['database']) : '';
$diasConsulta = isset($_GET['dias']) ? (int)$_GET['dias'] : 7;
if ($diasConsulta <= 0) {
    $diasConsulta = 7;
}

if (!isset($configuracao['servidores'][$indiceServidor]) || !is_array($configuracao['servidores'][$indiceServidor])) {
    responderJson([
        'ok' => false,
        'erro' => 'Servidor inválido.',
        'contabilizar_erro' => 1,
        'contabilizar_ok' => 0,
    ], 400);
}

$servidor = $configuracao['servidores'][$indiceServidor];
$identificacao = isset($servidor['identificacao']) ? trim((string)$servidor['identificacao']) : '';
$endereco = isset($servidor['endereco']) ? trim((string)$servidor['endereco']) : '';
$porta = isset($servidor['porta']) ? (int)$servidor['porta'] : 3306;
$mysqlUsuario = (string)$configuracao['mysql_usuario'];
$mysqlSenha = (string)$configuracao['mysql_senha'];

if ($identificacao === '' || $endereco === '') {
    responderJson([
        'ok' => false,
        'erro' => 'Servidor ignorado por falta de configuração.',
        'servidor' => $identificacao,
        'endereco' => $endereco,
        'porta' => (string)$porta,
        'indiceServidor' => (string)$indiceServidor,
        'contabilizar_erro' => 1,
        'contabilizar_ok' => 0,
    ], 400);
}

$inicio = microtime(true);
error_log('WORKER consultar_notas acao=' . $acao . ' servidor=' . $identificacao . ' host=' . $endereco . ' porta=' . $porta . ' filtro=' . $filtroDatabase . ' dias=' . $diasConsulta);

$conexao = criarConexaoMysql($endereco, $mysqlUsuario, $mysqlSenha, $porta);
if ($conexao === false) {
    $erroConexao = mysqli_connect_error();
    if (!is_string($erroConexao) || $erroConexao === '') {
        $erroConexao = 'falha ao conectar no servidor MySQL';
    }

    responderJson([
        'ok' => false,
        'erro' => $erroConexao,
        'servidor' => $identificacao,
        'endereco' => $endereco,
        'porta' => (string)$porta,
        'indiceServidor' => (string)$indiceServidor,
        'contabilizar_erro' => 1,
        'contabilizar_ok' => 0,
        'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
    ], 500);
}

if ($acao === 'listar_bancos') {
    $bancos = listarBancos($conexao, $filtroDatabase);
    $conexao->close();

    $bancosFiltrados = [];
    foreach ($bancos as $nomeBanco) {
        if (strcasecmp($nomeBanco, 'posto_teste') === 0) {
            continue;
        }
        if ($filtroDatabase !== '' && stripos($nomeBanco, $filtroDatabase) === false) {
            continue;
        }
        $bancosFiltrados[] = (string)$nomeBanco;
    }

    responderJson([
        'ok' => true,
        'acao' => 'listar_bancos',
        'servidor' => $identificacao,
        'endereco' => $endereco,
        'porta' => (string)$porta,
        'indiceServidor' => (string)$indiceServidor,
        'bancos' => array_values($bancosFiltrados),
        'contabilizar_erro' => 0,
        'contabilizar_ok' => 0,
        'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
    ]);
}

if ($acao === 'consultar_banco') {
    $nomeBanco = isset($_GET['db']) ? trim((string)$_GET['db']) : '';
    if ($nomeBanco === '') {
        $conexao->close();
        responderJson([
            'ok' => false,
            'erro' => 'Database não informado.',
            'servidor' => $identificacao,
            'endereco' => $endereco,
            'porta' => (string)$porta,
            'indiceServidor' => (string)$indiceServidor,
            'contabilizar_erro' => 1,
            'contabilizar_ok' => 0,
            'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ], 400);
    }

    $presenca = obterPresencaTabelas($conexao, $nomeBanco);
    if (!empty($presenca['erro'])) {
        $conexao->close();
        responderJson([
            'ok' => false,
            'erro' => (string)$presenca['erro'],
            'servidor' => $identificacao,
            'endereco' => $endereco,
            'porta' => (string)$porta,
            'indiceServidor' => (string)$indiceServidor,
            'database' => $nomeBanco,
            'contabilizar_erro' => 1,
            'contabilizar_ok' => 0,
            'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ], 500);
    }

    if (!$presenca['notas_fiscais_eletronicas']) {
        $conexao->close();
        responderJson([
            'ok' => true,
            'ignorado' => true,
            'motivo' => 'Tabela notas_fiscais_eletronicas não encontrada.',
            'servidor' => $identificacao,
            'endereco' => $endereco,
            'porta' => (string)$porta,
            'indiceServidor' => (string)$indiceServidor,
            'database' => $nomeBanco,
            'contabilizar_erro' => 0,
            'contabilizar_ok' => 0,
            'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ]);
    }

    if (!$presenca['notas_fiscais']) {
        $conexao->close();
        responderJson([
            'ok' => false,
            'erro' => 'Tabela notas_fiscais não encontrada.',
            'servidor' => $identificacao,
            'endereco' => $endereco,
            'porta' => (string)$porta,
            'indiceServidor' => (string)$indiceServidor,
            'database' => $nomeBanco,
            'contabilizar_erro' => 1,
            'contabilizar_ok' => 0,
            'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ], 500);
    }

    $nomeBancoEscapado = str_replace('`', '``', $nomeBanco);
    $sqlConsulta = "
        SELECT COUNT(*) AS quantidade
        FROM `{$nomeBancoEscapado}`.`notas_fiscais_eletronicas` a
        INNER JOIN `{$nomeBancoEscapado}`.`notas_fiscais` b
            ON a.idt = b.idt AND a.origem = b.origem
        WHERE a.status NOT IN (100, 150, 101, 102)
          AND b.emissao >= DATE_SUB(NOW(), INTERVAL {$diasConsulta} DAY)
    ";

    $resultadoConsulta = $conexao->query($sqlConsulta);
    if ($resultadoConsulta === false) {
        $erroBanco = $conexao->error;
        if (!is_string($erroBanco) || $erroBanco === '') {
            $erroBanco = 'Falha ao executar consulta.';
        }
        $conexao->close();
        responderJson([
            'ok' => false,
            'erro' => $erroBanco,
            'servidor' => $identificacao,
            'endereco' => $endereco,
            'porta' => (string)$porta,
            'indiceServidor' => (string)$indiceServidor,
            'database' => $nomeBanco,
            'contabilizar_erro' => 1,
            'contabilizar_ok' => 0,
            'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ], 500);
    }

    $linhaConsulta = $resultadoConsulta->fetch_assoc();
    $quantidade = isset($linhaConsulta['quantidade']) ? (int)$linhaConsulta['quantidade'] : 0;
    $resultadoConsulta->free();
    $conexao->close();

    responderJson([
        'ok' => true,
        'ignorado' => false,
        'servidor' => $identificacao,
        'endereco' => $endereco,
        'porta' => (string)$porta,
        'indiceServidor' => (string)$indiceServidor,
        'database' => $nomeBanco,
        'diasConsulta' => (string)$diasConsulta,
        'quantidade' => $quantidade,
        'classeLinha' => ($quantidade > 100) ? 'linha-vermelha' : 'linha-verde',
        'contabilizar_erro' => 0,
        'contabilizar_ok' => 1,
        'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
    ]);
}

$conexao->close();
responderJson([
    'ok' => false,
    'erro' => 'Ação inválida.',
    'servidor' => $identificacao,
    'endereco' => $endereco,
    'porta' => (string)$porta,
    'indiceServidor' => (string)$indiceServidor,
    'contabilizar_erro' => 1,
    'contabilizar_ok' => 0,
    'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
], 400);
