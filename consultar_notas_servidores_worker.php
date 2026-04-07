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
$arquivoLogPhp = __DIR__ . '/consultar_notas_servidores_worker.log';
ini_set('log_errors', '1');
ini_set('error_log', $arquivoLogPhp);

function responderJson(array $dados, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lerJsonArquivo(string $arquivo, string $rotulo): array
{
    if (!file_exists($arquivo)) {
        responderJson(['ok' => false, 'erro' => 'Arquivo ' . $rotulo . ' não encontrado.'], 500);
    }

    $conteudo = file_get_contents($arquivo);
    if ($conteudo === false) {
        responderJson(['ok' => false, 'erro' => 'Não foi possível ler ' . $rotulo . '.'], 500);
    }

    $dados = json_decode($conteudo, true);
    if (!is_array($dados)) {
        responderJson(['ok' => false, 'erro' => 'JSON inválido em ' . $rotulo . '.'], 500);
    }

    return $dados;
}

const SERVIDORES_TABELA_HOST = '10.8.0.6';
const SERVIDORES_TABELA_PORTA = 3306;
const SERVIDORES_TABELA_USUARIO = 'mysoftweb';
const SERVIDORES_TABELA_SENHA = 'g3108f88';
const SERVIDORES_TABELA_DATABASE = 'mysoft';

function criarConexaoTabelaServidores()
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

    $ok = @mysqli_real_connect(
        $conexao,
        SERVIDORES_TABELA_HOST,
        SERVIDORES_TABELA_USUARIO,
        SERVIDORES_TABELA_SENHA,
        SERVIDORES_TABELA_DATABASE,
        SERVIDORES_TABELA_PORTA
    );

    if ($ok !== true) {
        @mysqli_close($conexao);
        return false;
    }

    @$conexao->set_charset('utf8mb4');
    @$conexao->query('SET SESSION wait_timeout = 28800');
    @$conexao->query('SET SESSION net_read_timeout = 600');
    @$conexao->query('SET SESSION net_write_timeout = 600');

    return $conexao;
}

function carregarServidoresTabela(): array
{
    $conexao = criarConexaoTabelaServidores();
    if (!$conexao instanceof mysqli) {
        $erro = mysqli_connect_error();
        if (!is_string($erro) || $erro === '') {
            $erro = 'Não foi possível conectar na tabela servidores.';
        }
        responderJson(['ok' => false, 'erro' => $erro], 500);
    }

    $sql = "
        SELECT
            nome,
            grupo,
            endereco,
            porta,
            `database`
        FROM `servidores`
        ORDER BY grupo, nome, `database`, endereco, porta
    ";

    $resultado = $conexao->query($sql);
    if ($resultado === false) {
        $erro = $conexao->error;
        $conexao->close();
        responderJson(['ok' => false, 'erro' => 'Erro ao consultar tabela servidores: ' . $erro], 500);
    }

    $servidores = [];
    while ($linha = $resultado->fetch_assoc()) {
        if (!is_array($linha)) {
            continue;
        }

        $servidores[] = [
            'nome' => isset($linha['nome']) ? trim((string) $linha['nome']) : '',
            'grupo' => isset($linha['grupo']) ? trim((string) $linha['grupo']) : '',
            'endereco' => isset($linha['endereco']) ? trim((string) $linha['endereco']) : '',
            'porta' => isset($linha['porta']) ? (int) $linha['porta'] : 3306,
            'database' => isset($linha['database']) ? trim((string) $linha['database']) : ''
        ];
    }

    $resultado->free();
    $conexao->close();

    return $servidores;
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
            $nomeTabela = isset($linha['table_name']) ? (string) $linha['table_name'] : '';
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


function obterCampoOrigemNotasFiscais(mysqli $conexao, string $nomeBanco): string
{
    static $cache = [];

    if (isset($cache[$nomeBanco])) {
        return $cache[$nomeBanco];
    }

    $sql = "
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = ?
          AND table_name = 'notas_fiscais'
          AND column_name IN ('empresa', 'origem')
        ORDER BY CASE column_name WHEN 'empresa' THEN 0 ELSE 1 END
        LIMIT 1
    ";

    $stmt = $conexao->prepare($sql);
    if ($stmt === false) {
        $cache[$nomeBanco] = 'origem';
        return $cache[$nomeBanco];
    }

    $stmt->bind_param('s', $nomeBanco);
    $ok = $stmt->execute();
    if ($ok === false) {
        $stmt->close();
        $cache[$nomeBanco] = 'origem';
        return $cache[$nomeBanco];
    }

    $resultado = $stmt->get_result();
    $campo = 'origem';

    if ($resultado !== false) {
        $linha = $resultado->fetch_assoc();
        if (is_array($linha) && !empty($linha['column_name'])) {
            $campo = (string) $linha['column_name'];
        }
        $resultado->free();
    }

    $stmt->close();
    $cache[$nomeBanco] = $campo;

    return $campo;
}


mysqli_report(MYSQLI_REPORT_OFF);

$configuracao = lerJsonArquivo($arquivoConfiguracao, 'config.json');
$servidoresTabela = carregarServidoresTabela();

if (empty($configuracao['mysql_usuario']) || !array_key_exists('mysql_senha', $configuracao)) {
    responderJson(['ok' => false, 'erro' => 'Configuração incompleta no config.json.'], 500);
}

if (count($servidoresTabela) === 0) {
    responderJson(['ok' => false, 'erro' => 'Nenhum servidor encontrado na tabela servidores do mysoft.'], 500);
}

$indiceItem = isset($_GET['i']) ? (int) $_GET['i'] : -1;
$diasConsulta = isset($_GET['dias']) ? (int) $_GET['dias'] : 7;
if ($diasConsulta <= 0) {
    $diasConsulta = 7;
}

if (!isset($servidoresTabela[$indiceItem]) || !is_array($servidoresTabela[$indiceItem])) {
    responderJson([
        'ok' => false,
        'erro' => 'Item inválido na tabela servidores.',
        'contabilizar_erro' => 1,
        'contabilizar_ok' => 0,
        'erro_conexao' => false,
    ], 400);
}

$item = $servidoresTabela[$indiceItem];
$nome = isset($item['nome']) ? trim((string) $item['nome']) : '';
$grupo = isset($item['grupo']) ? trim((string) $item['grupo']) : '';
$endereco = isset($item['endereco']) ? trim((string) $item['endereco']) : '';
$porta = isset($item['porta']) ? (int) $item['porta'] : 3306;
$database = isset($item['database']) ? trim((string) $item['database']) : '';
$indiceServidorConfig = localizarIndiceServidorConfig(
    isset($configuracao['servidores']) && is_array($configuracao['servidores']) ? $configuracao['servidores'] : [],
    $endereco,
    $porta
);

if ($nome === '' || $endereco === '' || $database === '') {
    responderJson([
        'ok' => false,
        'erro' => 'Registro incompleto na tabela servidores.',
        'nome' => $nome,
        'grupo' => $grupo,
        'endereco' => $endereco,
        'porta' => (string) $porta,
        'database' => $database,
        'indiceServidor' => (string) $indiceServidorConfig,
        'contabilizar_erro' => 1,
        'contabilizar_ok' => 0,
        'erro_conexao' => false,
    ], 400);
}

$inicio = microtime(true);
error_log('WORKER consultar_notas_servidores nome=' . $nome . ' grupo=' . $grupo . ' host=' . $endereco . ' porta=' . $porta . ' database=' . $database . ' dias=' . $diasConsulta . ' origem=tabela_servidores');

$conexao = criarConexaoMysql($endereco, (string) $configuracao['mysql_usuario'], (string) $configuracao['mysql_senha'], $porta);
if ($conexao === false) {
    $erroConexao = mysqli_connect_error();
    if (!is_string($erroConexao) || $erroConexao === '') {
        $erroConexao = 'falha ao conectar no servidor MySQL';
    }

    responderJson([
        'ok' => false,
        'erro' => $erroConexao,
        'nome' => $nome,
        'grupo' => $grupo,
        'endereco' => $endereco,
        'porta' => (string) $porta,
        'database' => $database,
        'indiceServidor' => (string) $indiceServidorConfig,
        'contabilizar_erro' => 1,
        'contabilizar_ok' => 0,
        'erro_conexao' => true,
        'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
    ], 500);
}

$presenca = obterPresencaTabelas($conexao, $database);
if (!empty($presenca['erro'])) {
    $conexao->close();
    responderJson([
        'ok' => false,
        'erro' => (string) $presenca['erro'],
        'nome' => $nome,
        'grupo' => $grupo,
        'endereco' => $endereco,
        'porta' => (string) $porta,
        'database' => $database,
        'indiceServidor' => (string) $indiceServidorConfig,
        'contabilizar_erro' => 1,
        'contabilizar_ok' => 0,
        'erro_conexao' => false,
        'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
    ], 500);
}

if (!$presenca['notas_fiscais_eletronicas']) {
    $conexao->close();
    responderJson([
        'ok' => false,
        'erro' => 'Tabela notas_fiscais_eletronicas não encontrada.',
        'nome' => $nome,
        'grupo' => $grupo,
        'endereco' => $endereco,
        'porta' => (string) $porta,
        'database' => $database,
        'indiceServidor' => (string) $indiceServidorConfig,
        'contabilizar_erro' => 1,
        'contabilizar_ok' => 0,
        'erro_conexao' => false,
        'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
    ], 500);
}

if (!$presenca['notas_fiscais']) {
    $conexao->close();
    responderJson([
        'ok' => false,
        'erro' => 'Tabela notas_fiscais não encontrada.',
        'nome' => $nome,
        'grupo' => $grupo,
        'endereco' => $endereco,
        'porta' => (string) $porta,
        'database' => $database,
        'indiceServidor' => (string) $indiceServidorConfig,
        'contabilizar_erro' => 1,
        'contabilizar_ok' => 0,
        'erro_conexao' => false,
        'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
    ], 500);
}

$nomeBancoEscapado = str_replace('`', '``', $database);
$campoOrigemNotas = obterCampoOrigemNotasFiscais($conexao, $database);
$campoOrigemNotasEscapado = str_replace('`', '``', $campoOrigemNotas);
$sqlConsulta = "
    SELECT COUNT(*) AS quantidade
    FROM `{$nomeBancoEscapado}`.`notas_fiscais_eletronicas` a
    INNER JOIN `{$nomeBancoEscapado}`.`notas_fiscais` b
        ON a.idt = b.idt AND a.origem = b.`{$campoOrigemNotasEscapado}`
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
        'nome' => $nome,
        'grupo' => $grupo,
        'endereco' => $endereco,
        'porta' => (string) $porta,
        'database' => $database,
        'indiceServidor' => (string) $indiceServidorConfig,
        'contabilizar_erro' => 1,
        'contabilizar_ok' => 0,
        'erro_conexao' => false,
        'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
    ], 500);
}

$linhaConsulta = $resultadoConsulta->fetch_assoc();
$quantidade = isset($linhaConsulta['quantidade']) ? (int) $linhaConsulta['quantidade'] : 0;
$resultadoConsulta->free();
$conexao->close();

responderJson([
    'ok' => true,
    'nome' => $nome,
    'grupo' => $grupo,
    'endereco' => $endereco,
    'porta' => (string) $porta,
    'database' => $database,
    'indiceServidor' => (string) $indiceServidorConfig,
    'diasConsulta' => (string) $diasConsulta,
    'quantidade' => $quantidade,
    'classeLinha' => ($quantidade > 100) ? 'linha-vermelha' : 'linha-verde',
    'contabilizar_erro' => 0,
    'contabilizar_ok' => 1,
    'erro_conexao' => false,
    'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
]);
