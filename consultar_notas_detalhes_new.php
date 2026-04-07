<?php

declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');
ignore_user_abort(true);

$arquivoConfiguracao = __DIR__ . '/config.json';
$arquivoLogPhp = __DIR__ . '/consultar_notas_detalhes.log';
ini_set('log_errors', '1');
ini_set('error_log', $arquivoLogPhp);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

function sairComErro(string $mensagem): void
{
    echo '<!doctype html>';
    echo '<html lang="pt-BR">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Detalhes das Notas</title>';
    echo '<style>';
    echo 'html, body { height:100%; margin:0; }';
    echo 'body { background:#f4f7fb; color:#1b2a3a; font-family:Arial,Helvetica,sans-serif; }';
    echo '.erro-wrap { padding:20px; }';
    echo '.erro-box { padding:12px; background:#fff1f1; border:1px solid #e2b4b4; color:#a84545; font-weight:bold; border-radius:12px; }';
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

function listarValor(array $linha, array $campos, string $padrao = ''): string
{
    foreach ($campos as $campo) {
        if (array_key_exists($campo, $linha) && $linha[$campo] !== null && $linha[$campo] !== '') {
            return (string) $linha[$campo];
        }
    }

    return $padrao;
}


function normalizarUtf8Recursivo($valor)
{
    if (is_array($valor)) {
        $normalizado = [];
        foreach ($valor as $chave => $item) {
            $chaveNormalizada = is_string($chave) ? (string) normalizarUtf8Recursivo($chave) : $chave;
            $normalizado[$chaveNormalizada] = normalizarUtf8Recursivo($item);
        }

        return $normalizado;
    }

    if (!is_string($valor)) {
        return $valor;
    }

    if ($valor === '' || preg_match('//u', $valor) === 1) {
        return $valor;
    }

    if (function_exists('mb_convert_encoding')) {
        $convertido = @mb_convert_encoding($valor, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        if ($convertido !== false && preg_match('//u', $convertido) === 1) {
            return $convertido;
        }
    }

    if (function_exists('iconv')) {
        $convertido = @iconv('Windows-1252', 'UTF-8//IGNORE', $valor);
        if ($convertido !== false && preg_match('//u', $convertido) === 1) {
            return $convertido;
        }
    }

    return utf8_encode($valor);
}

function codificarJsonSeguro($dados, string $rotuloLog): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $json = json_encode($dados, $flags);
    if ($json !== false) {
        return $json;
    }

    error_log($rotuloLog . ' json_encode falhou na primeira tentativa: ' . json_last_error_msg());

    $dadosNormalizados = normalizarUtf8Recursivo($dados);
    $json = json_encode($dadosNormalizados, $flags);
    if ($json !== false) {
        return $json;
    }

    error_log($rotuloLog . ' json_encode falhou apos normalizar UTF-8: ' . json_last_error_msg());

    return '[]';
}


function lerLinhasSelecionadasPost(): array
{
    if (!isset($_POST['linhas_selecionadas_json'])) {
        return [];
    }

    $json = trim((string) $_POST['linhas_selecionadas_json']);
    if ($json === '') {
        return [];
    }

    $dados = json_decode($json, true);
    if (!is_array($dados)) {
        return [];
    }

    $linhas = [];
    foreach ($dados as $item) {
        if (!is_array($item)) {
            continue;
        }

        $idt = isset($item['idt']) ? trim((string) $item['idt']) : '';
        if ($idt === '') {
            continue;
        }

        $linhas[] = [
            'idt' => $idt,
            'cancelado' => isset($item['cancelado']) ? (string) $item['cancelado'] : '',
            'emissao' => isset($item['emissao']) ? (string) $item['emissao'] : '',
            'hora' => isset($item['hora']) ? (string) $item['hora'] : '',
            'nota' => isset($item['nota']) ? (string) $item['nota'] : '',
            'serie' => isset($item['serie']) ? (string) $item['serie'] : '',
            'modelo' => isset($item['modelo']) ? (string) $item['modelo'] : '',
            'origem' => isset($item['origem']) ? (string) $item['origem'] : '',
            'chave' => isset($item['chave']) ? (string) $item['chave'] : '',
            'operacao' => isset($item['operacao']) ? (string) $item['operacao'] : '',
            'status' => isset($item['status']) ? (string) $item['status'] : '',
            'descricao' => isset($item['descricao']) ? (string) $item['descricao'] : ''
        ];
    }

    return $linhas;
}

function indexarLinhasPorIdt(array $linhas): array
{
    $mapa = [];
    foreach ($linhas as $linha) {
        if (!is_array($linha)) {
            continue;
        }

        $idt = isset($linha['idt']) ? trim((string) $linha['idt']) : '';
        if ($idt === '') {
            continue;
        }

        $mapa[$idt] = $linha;
    }

    return $mapa;
}

function detectarPlataformaAutonfe(): string
{
    $familia = PHP_OS_FAMILY;
    $maquina = strtolower((string) php_uname('m'));

    if ($familia === 'Windows') {
        return 'windows';
    }

    if ($familia === 'Linux') {
        if (strpos($maquina, 'arm') !== false || strpos($maquina, 'aarch64') !== false) {
            return 'linux_arm';
        }

        return 'linux';
    }

    return 'desconhecida';
}

function obterCaminhoAutonfe(array $configuracao): string
{
    $plataforma = detectarPlataformaAutonfe();
    $caminhos = isset($configuracao['autonfe']) && is_array($configuracao['autonfe'])
        ? $configuracao['autonfe']
        : [];

    if ($plataforma === 'windows' && !empty($caminhos['windows'])) {
        return (string) $caminhos['windows'];
    }

    if ($plataforma === 'linux' && !empty($caminhos['linux'])) {
        return (string) $caminhos['linux'];
    }

    if ($plataforma === 'linux_arm' && !empty($caminhos['linux_arm'])) {
        return (string) $caminhos['linux_arm'];
    }

    return '';
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

function carregarLinhasProblema(mysqli $conexao, string $nomeBanco, int $diasConsulta): array
{
    $nomeBancoEscapado = str_replace('`', '``', $nomeBanco);
    $diasConsultaSql = (int) $diasConsulta;
    $campoOrigemNotas = obterCampoOrigemNotasFiscais($conexao, $nomeBanco);
    $campoOrigemNotasEscapado = str_replace('`', '``', $campoOrigemNotas);

    $sqlDetalhes = "
        SELECT
            a.idt,
            b.cancelado,
            b.emissao,
            b.hora AS hora,
            a.nota,
            a.serie,
            a.modelo,
            a.origem,
            a.chave,
            a.operacao,
            a.status,
            a.status_descricao
        FROM `{$nomeBancoEscapado}`.`notas_fiscais_eletronicas` a
        INNER JOIN `{$nomeBancoEscapado}`.`notas_fiscais` b
            ON a.idt = b.idt
           AND a.origem = b.`{$campoOrigemNotasEscapado}`
        WHERE a.status NOT IN (100, 150, 101, 102)
          AND b.emissao >= DATE_SUB(NOW(), INTERVAL {$diasConsultaSql} DAY)
        ORDER BY b.emissao DESC, a.idt DESC
    ";

    $resultado = $conexao->query($sqlDetalhes);
    if ($resultado === false) {
        throw new RuntimeException('Erro ao consultar detalhes: ' . $conexao->error);
    }

    $linhas = [];
    while ($linha = $resultado->fetch_assoc()) {
        $linhas[] = [
            'idt' => listarValor($linha, ['idt']),
            'cancelado' => listarValor($linha, ['cancelado']),
            'emissao' => listarValor($linha, ['emissao']),
            'hora' => listarValor($linha, ['hora']),
            'nota' => listarValor($linha, ['nota']),
            'serie' => listarValor($linha, ['serie']),
            'modelo' => listarValor($linha, ['modelo']),
            'origem' => listarValor($linha, ['origem']),
            'chave' => listarValor($linha, ['chave']),
            'operacao' => listarValor($linha, ['operacao']),
            'status' => listarValor($linha, ['status']),
            'descricao' => listarValor($linha, ['status_descricao'])
        ];
    }

    $resultado->free();

    return $linhas;
}


function carregarModelosPorIdts(mysqli $conexao, string $nomeBanco, array $idtsSelecionados): array
{
    if (count($idtsSelecionados) === 0) {
        return [];
    }

    $nomeBancoEscapado = str_replace('`', '``', $nomeBanco);
    $idtsEscapados = [];

    foreach ($idtsSelecionados as $idtSelecionado) {
        $idtSelecionado = trim((string) $idtSelecionado);
        if ($idtSelecionado === '') {
            continue;
        }
        $idtsEscapados[] = "'" . $conexao->real_escape_string($idtSelecionado) . "'";
    }

    if (count($idtsEscapados) === 0) {
        return [];
    }

    $sqlModelos = "
        SELECT
            a.idt,
            a.modelo
        FROM `{$nomeBancoEscapado}`.`notas_fiscais_eletronicas` a
        WHERE a.idt IN (" . implode(', ', $idtsEscapados) . ")
    ";

    $resultado = $conexao->query($sqlModelos);
    if ($resultado === false) {
        throw new RuntimeException('Erro ao consultar modelos das notas selecionadas: ' . $conexao->error);
    }

    $linhasPorIdt = [];
    while ($linha = $resultado->fetch_assoc()) {
        $idtAtual = listarValor($linha, ['idt']);
        if ($idtAtual === '') {
            continue;
        }

        $linhasPorIdt[$idtAtual] = [
            'idt' => $idtAtual,
            'modelo' => listarValor($linha, ['modelo'])
        ];
    }

    $resultado->free();

    return $linhasPorIdt;
}

function buscarNotaPorIdt(mysqli $conexao, string $database, $idt): array
{
    $idt = (string) $idt;
    $databaseEscapado = str_replace('`', '``', $database);
    $idtEscapado = $conexao->real_escape_string($idt);
    $campoOrigemNotas = obterCampoOrigemNotasFiscais($conexao, $database);
    $campoOrigemNotasEscapado = str_replace('`', '``', $campoOrigemNotas);

    $sql = "
        SELECT
            a.idt,
            b.cancelado,
            b.emissao,
            b.hora AS hora,
            a.nota,
            a.serie,
            a.modelo,
            a.origem,
            a.chave,
            a.operacao,
            a.status,
            a.status_descricao
        FROM `{$databaseEscapado}`.`notas_fiscais_eletronicas` a
        INNER JOIN `{$databaseEscapado}`.`notas_fiscais` b
            ON a.idt = b.idt
           AND a.origem = b.`{$campoOrigemNotasEscapado}`
        WHERE a.idt = '{$idtEscapado}'
        LIMIT 1
    ";

    $resultado = $conexao->query($sql);
    if ($resultado === false) {
        error_log('AUTONFE buscarNotaPorIdt erro SQL: ' . $conexao->error . ' | idt=' . $idt);
        return [];
    }

    $linha = $resultado->fetch_assoc();
    $resultado->free();

    if (!is_array($linha)) {
        return [];
    }

    return [
        'idt' => listarValor($linha, ['idt']),
        'cancelado' => listarValor($linha, ['cancelado']),
        'emissao' => listarValor($linha, ['emissao']),
        'hora' => listarValor($linha, ['hora']),
        'nota' => listarValor($linha, ['nota']),
        'serie' => listarValor($linha, ['serie']),
        'modelo' => listarValor($linha, ['modelo']),
        'origem' => listarValor($linha, ['origem']),
        'chave' => listarValor($linha, ['chave']),
        'operacao' => listarValor($linha, ['operacao']),
        'status' => listarValor($linha, ['status']),
        'descricao' => listarValor($linha, ['status_descricao'])
    ];
}

function buscarNotasPorIdts(mysqli $conexao, string $database, array $idts): array
{
    if (count($idts) === 0) {
        return [];
    }

    $databaseEscapado = str_replace('`', '``', $database);
    $campoOrigemNotas = obterCampoOrigemNotasFiscais($conexao, $database);
    $campoOrigemNotasEscapado = str_replace('`', '``', $campoOrigemNotas);
    $idtsEscapados = [];

    foreach ($idts as $idt) {
        $idt = trim((string) $idt);
        if ($idt === '') {
            continue;
        }
        $idtsEscapados[] = "'" . $conexao->real_escape_string($idt) . "'";
    }

    if (count($idtsEscapados) === 0) {
        return [];
    }

    $sql = "
        SELECT
            a.idt,
            b.cancelado,
            b.emissao,
            b.hora AS hora,
            a.nota,
            a.serie,
            a.modelo,
            a.origem,
            a.chave,
            a.operacao,
            a.status,
            a.status_descricao
        FROM `{$databaseEscapado}`.`notas_fiscais_eletronicas` a
        INNER JOIN `{$databaseEscapado}`.`notas_fiscais` b
            ON a.idt = b.idt
           AND a.origem = b.`{$campoOrigemNotasEscapado}`
        WHERE a.idt IN (" . implode(', ', $idtsEscapados) . ")
    ";

    $resultado = $conexao->query($sql);
    if ($resultado === false) {
        error_log('AUTONFE buscarNotasPorIdts erro SQL: ' . $conexao->error . ' | banco=' . $database . ' | idts=' . implode(',', $idts));
        return [];
    }

    $linhas = [];
    while ($linha = $resultado->fetch_assoc()) {
        $idtAtual = listarValor($linha, ['idt']);
        if ($idtAtual === '') {
            continue;
        }
        $linhas[$idtAtual] = [
            'idt' => $idtAtual,
            'cancelado' => listarValor($linha, ['cancelado']),
            'emissao' => listarValor($linha, ['emissao']),
            'hora' => listarValor($linha, ['hora']),
            'nota' => listarValor($linha, ['nota']),
            'serie' => listarValor($linha, ['serie']),
            'modelo' => listarValor($linha, ['modelo']),
            'origem' => listarValor($linha, ['origem']),
            'chave' => listarValor($linha, ['chave']),
            'operacao' => listarValor($linha, ['operacao']),
            'status' => listarValor($linha, ['status']),
            'descricao' => listarValor($linha, ['status_descricao'])
        ];
    }

    $resultado->free();

    return $linhas;
}

function criarConexaoMysql(string $endereco, string $usuario, string $senha, int $porta)
{
    $conexao = mysqli_init();
    if ($conexao === false) {
        return false;
    }

    if (defined('MYSQLI_OPT_CONNECT_TIMEOUT')) {
        @mysqli_options($conexao, MYSQLI_OPT_CONNECT_TIMEOUT, 240);
    }

    if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
        @mysqli_options($conexao, MYSQLI_OPT_READ_TIMEOUT, 1200);
    }

    $ok = @mysqli_real_connect($conexao, $endereco, $usuario, $senha, '', $porta);
    if ($ok !== true) {
        @mysqli_close($conexao);
        return false;
    }

    @$conexao->set_charset('utf8mb4');
    @$conexao->query('SET SESSION wait_timeout = 28800');
    @$conexao->query('SET SESSION net_read_timeout = 1200');
    @$conexao->query('SET SESSION net_write_timeout = 1200');
    @$conexao->query('SET SESSION innodb_lock_wait_timeout = 1200');

    return $conexao;
}

function montarComandoAutonfe(
    string $caminhoExecutavel,
    string $modelo,
    string $idt,
    string $acao,
    string $endereco,
    string $database,
    int $porta,
    int $versaoMysql
): string {
    $caminhoExecutavel = trim($caminhoExecutavel);
    $caminhoExecutavel = trim($caminhoExecutavel, '"');

    return
        '"' . $caminhoExecutavel . '" ' .
        '0 ' .
        $modelo . ' ' .
        $idt . ' ' .
        $acao . ' ' .
        'xml ' .
        $endereco . ' ' .
        $database . ' ' .
        (string) $porta . ' ' .
        (string) $versaoMysql;
}

function iniciarProcessoAutonfe(
    string $caminhoExecutavel,
    string $modelo,
    string $idt,
    string $acao,
    string $endereco,
    string $database,
    int $porta,
    int $versaoMysql
): array {
    $caminhoExecutavel = trim($caminhoExecutavel);
    $caminhoExecutavel = trim($caminhoExecutavel, '"');
    $diretorioTrabalho = dirname($caminhoExecutavel);
    $cwdAnterior = getcwd();
    $chdirAplicado = false;

    if ($diretorioTrabalho !== '' && is_dir($diretorioTrabalho)) {
        $chdirAplicado = @chdir($diretorioTrabalho);
    }

    $comando = montarComandoAutonfe(
        $caminhoExecutavel,
        $modelo,
        $idt,
        $acao,
        $endereco,
        $database,
        $porta,
        $versaoMysql
    );

    error_log('AUTONFE cwd anterior: ' . ($cwdAnterior !== false ? $cwdAnterior : '(indisponivel)'));
    error_log('AUTONFE cwd configurado: ' . $diretorioTrabalho);
    error_log('AUTONFE chdir aplicado: ' . ($chdirAplicado ? 'sim' : 'nao'));
    error_log('AUTONFE caminho: ' . $caminhoExecutavel);
    error_log('AUTONFE parametros: ' . json_encode([
        'acao' => $acao,
        'tipo_retorno' => 'xml',
        'amb' => '0',
        'modelo' => $modelo,
        'idt' => $idt,
        'servidor' => $endereco,
        'database' => $database,
        'porta' => (string) $porta,
        'mysql_versao' => (string) $versaoMysql
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    error_log('AUTONFE comando final: ' . $comando);

    $descritores = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    //$processo = @proc_open($comando, $descritores, $pipes, $diretorioTrabalho !== '' ? $diretorioTrabalho : null);

    $ambiente = null;
    $plataforma = detectarPlataformaAutonfe();

    if ($plataforma === 'linux' || $plataforma === 'linux_arm') {
        $ambiente = $_ENV;
        $ambiente['DISPLAY'] = ':99';
    }

    $processo = @proc_open(
        $comando,
        $descritores,
        $pipes,
        $diretorioTrabalho !== '' ? $diretorioTrabalho : null,
        $ambiente
    );

    if ($chdirAplicado && $cwdAnterior !== false) {
        @chdir($cwdAnterior);
    }

    if (!is_resource($processo)) {
        error_log('AUTONFE erro ao iniciar processo para idt=' . $idt);
        return [
            'ok' => false,
            'erro' => 'Não foi possível iniciar o AutoNFe.',
            'comando' => $comando
        ];
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    if (isset($pipes[1]) && is_resource($pipes[1])) {
        stream_set_blocking($pipes[1], false);
    }

    if (isset($pipes[2]) && is_resource($pipes[2])) {
        stream_set_blocking($pipes[2], false);
    }

    return [
        'ok' => true,
        'processo' => $processo,
        'pipes' => $pipes,
        'comando' => $comando,
        'saida' => '',
        'erro_saida' => ''
    ];
}

function fecharPipesProcesso(array $pipes): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
}


function obterArquivoExecucoesAtivas($indiceServidor, string $nomeBanco): string
{
    $identificadorServidor = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $indiceServidor);
    if (!is_string($identificadorServidor) || $identificadorServidor === '') {
        $identificadorServidor = 'servidor';
    }

    $base = 'consultar_notas_execucoes_' . $identificadorServidor . '_' . md5($nomeBanco) . '.json';
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $base;
}

function lerExecucoesAtivas(string $arquivo): array
{
    if (!file_exists($arquivo)) {
        return [];
    }

    $conteudo = @file_get_contents($arquivo);
    if ($conteudo === false || $conteudo === '') {
        return [];
    }

    $dados = json_decode($conteudo, true);
    if (!is_array($dados)) {
        return [];
    }

    $execucoes = [];
    foreach ($dados as $item) {
        if (!is_array($item)) {
            continue;
        }

        $idt = isset($item['idt']) ? trim((string) $item['idt']) : '';
        $acao = isset($item['acao']) ? trim((string) $item['acao']) : '';

        if ($idt === '' || $acao === '') {
            continue;
        }

        $execucoes[] = [
            'idt' => $idt,
            'acao' => $acao
        ];
    }

    return array_slice($execucoes, 0, 10);
}

function salvarExecucoesAtivas(string $arquivo, array $execucoes): void
{
    @file_put_contents($arquivo, json_encode(array_values($execucoes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function registrarExecucaoAtiva(string $arquivo, string $idt, string $acao): void
{
    $execucoes = lerExecucoesAtivas($arquivo);
    $novasExecucoes = [];

    foreach ($execucoes as $item) {
        if ((string) $item['idt'] === $idt) {
            continue;
        }
        $novasExecucoes[] = $item;
    }

    $novasExecucoes[] = [
        'idt' => $idt,
        'acao' => $acao
    ];

    salvarExecucoesAtivas($arquivo, array_slice($novasExecucoes, 0, 10));
}

function removerExecucaoAtiva(string $arquivo, string $idt): void
{
    $execucoes = lerExecucoesAtivas($arquivo);
    $novasExecucoes = [];

    foreach ($execucoes as $item) {
        if ((string) $item['idt'] === $idt) {
            continue;
        }
        $novasExecucoes[] = $item;
    }

    salvarExecucoesAtivas($arquivo, $novasExecucoes);
}


if (!file_exists($arquivoConfiguracao)) {
    sairComErro('Arquivo config.json não encontrado.');
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
    !array_key_exists('mysql_senha', $configuracao)
) {
    sairComErro('Configuração incompleta.');
}

$parametrosEntrada = $_GET;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $parametrosEntrada = array_merge($_GET, $_POST);
}

$indiceServidor = isset($parametrosEntrada['s']) ? (int) $parametrosEntrada['s'] : -1;
$nomeBanco = isset($parametrosEntrada['db']) ? trim((string) $parametrosEntrada['db']) : '';
$enderecoDireto = isset($parametrosEntrada['host']) ? trim((string) $parametrosEntrada['host']) : '';
$portaDireta = isset($parametrosEntrada['porta']) ? (int) $parametrosEntrada['porta'] : 3306;
$nomeDireto = isset($parametrosEntrada['nome']) ? trim((string) $parametrosEntrada['nome']) : '';
$grupoContexto = isset($parametrosEntrada['grupo']) ? trim((string) $parametrosEntrada['grupo']) : '';
$limparFiltroAoAbrir = isset($parametrosEntrada['limpar_filtro']) && (string) $parametrosEntrada['limpar_filtro'] === '1';
$diasConsulta = isset($parametrosEntrada['dias']) ? (int) $parametrosEntrada['dias'] : 7;
$grupoDetalhes = isset($parametrosEntrada['grupo']) ? trim((string) $parametrosEntrada['grupo']) : '';
$modoDireto = $enderecoDireto !== '';

if ($diasConsulta <= 0) {
    $diasConsulta = 7;
}

if ($nomeBanco === '') {
    sairComErro('Parâmetros inválidos.');
}

$identificacao = '';
$endereco = '';
$porta = 3306;
$versaoMysql = 5;
$identificadorServidorTela = '';
$parametrosBaseDetalhes = '';

if ($modoDireto) {
    $endereco = $enderecoDireto;
    $porta = $portaDireta > 0 ? $portaDireta : 3306;
    $identificacao = $nomeDireto !== '' ? $nomeDireto : $endereco;
    $identificadorServidorTela = 'direto:' . $endereco . ':' . $porta;
    $parametrosBaseDetalhes = 'host=' . rawurlencode($endereco)
        . '&porta=' . rawurlencode((string) $porta)
        . '&nome=' . rawurlencode($identificacao)
        . '&db=' . rawurlencode($nomeBanco)
        . '&dias=' . (int) $diasConsulta
        . '&grupo=' . rawurlencode($grupoContexto);
} else {
    if (
        empty($configuracao['servidores']) ||
        !is_array($configuracao['servidores']) ||
        $indiceServidor < 0 ||
        !isset($configuracao['servidores'][$indiceServidor]) ||
        !is_array($configuracao['servidores'][$indiceServidor])
    ) {
        sairComErro('Servidor não encontrado na configuração.');
    }

    $servidor = $configuracao['servidores'][$indiceServidor];
    $identificacao = isset($servidor['identificacao']) ? trim((string) $servidor['identificacao']) : '';
    $endereco = isset($servidor['endereco']) ? trim((string) $servidor['endereco']) : '';
    $porta = isset($servidor['porta']) ? (int) $servidor['porta'] : 3306;
    $versaoMysql = isset($servidor['mysql_versao']) ? (int) $servidor['mysql_versao'] : 5;

    if ($versaoMysql !== 4 && $versaoMysql !== 5) {
        $versaoMysql = 5;
    }

    if ($identificacao === '' || $endereco === '') {
        sairComErro('Configuração do servidor inválida.');
    }

    $identificadorServidorTela = (string) $indiceServidor;
    $parametrosBaseDetalhes = 's=' . (int) $indiceServidor
        . '&db=' . rawurlencode($nomeBanco)
        . '&dias=' . (int) $diasConsulta
        . '&grupo=' . rawurlencode($grupoContexto);
}

$arquivoExecucoesAtivas = obterArquivoExecucoesAtivas($identificadorServidorTela, $nomeBanco);
$respostaAjax = isset($_POST['ajax_action']) && (string) $_POST['ajax_action'] === '1';

if (isset($_GET['ajax_execucoes']) && (string) $_GET['ajax_execucoes'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['execucoes' => lerExecucoesAtivas($arquivoExecucoesAtivas)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$ajaxCarregar = isset($_GET['ajax_carregar']) && (string) $_GET['ajax_carregar'] === '1';
$mysqlUsuario = (string) $configuracao['mysql_usuario'];
$mysqlSenha = (string) $configuracao['mysql_senha'];

mysqli_report(MYSQLI_REPORT_OFF);

$conexao = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $ajaxCarregar) {
    $conexao = criarConexaoMysql($endereco, $mysqlUsuario, $mysqlSenha, $porta);
    if (!$conexao instanceof mysqli) {
        if ($ajaxCarregar) {
            header('Content-Type: application/json; charset=utf-8');
            echo codificarJsonSeguro([
                'ok' => false,
                'erro' => 'Erro ao conectar no servidor para carregar os detalhes das notas.'
            ], 'CONSULTAR_NOTAS_DETALHES ajax carregar erro conexao');
            exit;
        }

        sairComErro('Erro ao conectar no servidor.');
    }
}

if ($ajaxCarregar) {
    try {
        error_log('CONSULTAR_NOTAS_DETALHES carregando lista inicial via ajax banco=' . $nomeBanco . ' dias=' . $diasConsulta);
        $linhasAjax = carregarLinhasProblema($conexao, $nomeBanco, $diasConsulta);
        $conexao->close();
        header('Content-Type: application/json; charset=utf-8');
        echo codificarJsonSeguro([
            'ok' => true,
            'linhas' => array_values($linhasAjax)
        ], 'CONSULTAR_NOTAS_DETALHES ajax carregar');
        exit;
    } catch (Throwable $e) {
        if ($conexao instanceof mysqli) {
            $conexao->close();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo codificarJsonSeguro([
            'ok' => false,
            'erro' => $e->getMessage()
        ], 'CONSULTAR_NOTAS_DETALHES ajax carregar erro consulta');
        exit;
    }
}

$linhas = [];
$linhasCarregadas = false;

$classeMensagem = 'ok';
$mensagemExecucao = '';
$resultadosExecucao = [];
$linhasResultado = [];
$idtsProcessados = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = isset($_POST['acao']) ? trim((string) $_POST['acao']) : '';
    $acoesPermitidas = ['consultarX', 'validarX', 'acerto_w', 'acerto_v', 'enviarX', 'cancelarX', 'inutilizarX', 'removercancelamento'];
    $statusEncerrados = ['100', '101', '102', '150'];

    $idtsSelecionados = isset($_POST['idts']) && is_array($_POST['idts']) ? $_POST['idts'] : [];
    $idtsSelecionados = array_values(array_unique(array_filter(array_map(static function ($valor): string {
        return trim((string) $valor);
    }, $idtsSelecionados), static function (string $valor): bool {
        return $valor !== '';
    })));

    $linhasSelecionadasPost = lerLinhasSelecionadasPost();
    $linhasSelecionadasPorIdt = indexarLinhasPorIdt($linhasSelecionadasPost);
    $idtsRemover = [];
    $linhasAtualizar = [];

    if (!in_array($acao, $acoesPermitidas, true)) {
        $mensagemExecucao = 'Ação inválida.';
        $classeMensagem = 'erro';
    } elseif (count($idtsSelecionados) === 0) {
        $mensagemExecucao = 'Selecione pelo menos uma nota.';
        $classeMensagem = 'erro';
    } else {
        $caminhoAutonfe = obterCaminhoAutonfe($configuracao);

        if ($caminhoAutonfe === '') {
            $mensagemExecucao = 'Caminho do AutoNFe não encontrado no config.json para a plataforma atual.';
            $classeMensagem = 'erro';
        } elseif (!file_exists(trim($caminhoAutonfe, '"'))) {
            $mensagemExecucao = 'Executável do AutoNFe não encontrado em: ' . $caminhoAutonfe;
            $classeMensagem = 'erro';
        } else {
            salvarExecucoesAtivas($arquivoExecucoesAtivas, []);

            error_log('CONSULTAR_NOTAS_DETALHES preparando execucao local banco=' . $nomeBanco . ' acao=' . $acao . ' selecionadas=' . count($idtsSelecionados));
            $linhasPorIdt = [];

            foreach ($idtsSelecionados as $idtSelecionado) {
                $idtSelecionado = (string) $idtSelecionado;
                if (isset($linhasSelecionadasPorIdt[$idtSelecionado])) {
                    $linhaSelecionada = $linhasSelecionadasPorIdt[$idtSelecionado];
                    $modeloAtual = isset($linhaSelecionada['modelo']) ? trim((string) $linhaSelecionada['modelo']) : '';
                    if ($modeloAtual === '') {
                        $modeloAtual = '65';
                    }
                    $linhaSelecionada['modelo'] = $modeloAtual;
                    $linhasPorIdt[$idtSelecionado] = $linhaSelecionada;
                    continue;
                }

                $linhasPorIdt[$idtSelecionado] = [
                    'idt' => $idtSelecionado,
                    'modelo' => '65',
                    'descricao' => ''
                ];
            }

            $fila = [];
            $idtsBloqueadosOffline = [];
            $idtsExecutadosConsultar = [];

            foreach ($idtsSelecionados as $idtSelecionado) {
                $idtSelecionado = (string) $idtSelecionado;
                $modeloAtual = isset($linhasPorIdt[$idtSelecionado]['modelo']) ? (string) $linhasPorIdt[$idtSelecionado]['modelo'] : '65';
                if ($modeloAtual === '') {
                    $modeloAtual = '65';
                }

                if ($acao === 'acerto_w' && $modeloAtual === '55') {
                    $idtsBloqueadosOffline[] = $idtSelecionado;
                    $linhasAtualizar[] = [
                        'idt' => $idtSelecionado,
                        'descricao' => 'Ação Offline não permitida para notas do modelo 55.',
                        'status' => isset($linhasPorIdt[$idtSelecionado]['status']) ? (string) $linhasPorIdt[$idtSelecionado]['status'] : '',
                        'destacar_vermelho' => '1'
                    ];
                    $resultadosExecucao[] = [
                        'idt' => $idtSelecionado,
                        'acao' => $acao,
                        'codigo_retorno' => -1,
                        'comando' => '',
                        'saida' => 'Ação Offline não permitida para notas do modelo 55.'
                    ];
                    continue;
                }

                $fila[] = [
                    'idt' => $idtSelecionado,
                    'modelo' => $modeloAtual
                ];
            }

            if ($classeMensagem !== 'erro') {
                $maximoParalelo = 10;
                $ativos = [];
                $indiceFila = 0;
                $primeiroInicioRegistrado = false;

                while ($indiceFila < count($fila) || count($ativos) > 0) {
                    while ($indiceFila < count($fila) && count($ativos) < $maximoParalelo) {
                        $itemFila = $fila[$indiceFila];
                        $iniciado = iniciarProcessoAutonfe(
                            $caminhoAutonfe,
                            (string) $itemFila['modelo'],
                            (string) $itemFila['idt'],
                            $acao,
                            $endereco,
                            $nomeBanco,
                            $porta,
                            $versaoMysql
                        );

                        if (!$iniciado['ok']) {
                            $resultadosExecucao[] = [
                                'idt' => (string) $itemFila['idt'],
                                'acao' => $acao,
                                'codigo_retorno' => -1,
                                'comando' => (string) $iniciado['comando'],
                                'saida' => (string) $iniciado['erro']
                            ];
                        } else {
                            registrarExecucaoAtiva($arquivoExecucoesAtivas, (string) $itemFila['idt'], $acao);

                            if (!$primeiroInicioRegistrado) {
                                $primeiroInicioRegistrado = true;
                                error_log('CONSULTAR_NOTAS_DETALHES primeira execucao iniciada local banco=' . $nomeBanco . ' acao=' . $acao . ' idt=' . (string) $itemFila['idt']);
                            }

                            $ativos[] = [
                                'idt' => (string) $itemFila['idt'],
                                'modelo' => (string) $itemFila['modelo'],
                                'acao' => $acao,
                                'processo' => $iniciado['processo'],
                                'pipes' => $iniciado['pipes'],
                                'comando' => $iniciado['comando'],
                                'saida' => '',
                                'erro_saida' => ''
                            ];
                        }

                        $indiceFila++;
                    }

                    if (count($ativos) === 0) {
                        continue;
                    }

                    usleep(200000);

                    foreach ($ativos as $indiceAtivo => $ativo) {
                        if (isset($ativo['pipes'][1]) && is_resource($ativo['pipes'][1])) {
                            $trechoSaida = stream_get_contents($ativo['pipes'][1]);
                            if ($trechoSaida !== false && $trechoSaida !== '') {
                                $ativo['saida'] .= $trechoSaida;
                            }
                        }

                        if (isset($ativo['pipes'][2]) && is_resource($ativo['pipes'][2])) {
                            $trechoErro = stream_get_contents($ativo['pipes'][2]);
                            if ($trechoErro !== false && $trechoErro !== '') {
                                $ativo['erro_saida'] .= $trechoErro;
                            }
                        }

                        $statusProcesso = proc_get_status($ativo['processo']);
                        $ativos[$indiceAtivo] = $ativo;

                        if (!is_array($statusProcesso) || !isset($statusProcesso['running']) || $statusProcesso['running'] !== false) {
                            continue;
                        }

                        if (isset($ativo['pipes'][1]) && is_resource($ativo['pipes'][1])) {
                            $trechoSaida = stream_get_contents($ativo['pipes'][1]);
                            if ($trechoSaida !== false && $trechoSaida !== '') {
                                $ativo['saida'] .= $trechoSaida;
                            }
                        }

                        if (isset($ativo['pipes'][2]) && is_resource($ativo['pipes'][2])) {
                            $trechoErro = stream_get_contents($ativo['pipes'][2]);
                            if ($trechoErro !== false && $trechoErro !== '') {
                                $ativo['erro_saida'] .= $trechoErro;
                            }
                        }

                        fecharPipesProcesso($ativo['pipes']);
                        $codigoRetorno = proc_close($ativo['processo']);
                        if (!is_int($codigoRetorno) || $codigoRetorno < 0) {
                            $codigoRetorno = isset($statusProcesso['exitcode']) && is_int($statusProcesso['exitcode'])
                                ? $statusProcesso['exitcode']
                                : -1;
                        }

                        $saidaFinal = trim($ativo['saida']);
                        $erroFinal = trim($ativo['erro_saida']);
                        $saidaCombinada = $saidaFinal;
                        if ($erroFinal !== '') {
                            $saidaCombinada = $saidaCombinada !== ''
                                ? $saidaCombinada . "
" . $erroFinal
                                : $erroFinal;
                        }

                        error_log('AUTONFE retorno exec local: codigo=' . $codigoRetorno . ' saida=' . json_encode($saidaCombinada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        error_log('AUTONFE idt=' . $ativo['idt'] . ' modelo=' . $ativo['modelo'] . ' comando=' . $ativo['comando']);

                        $idtsExecutadosConsultar[] = (string) $ativo['idt'];
                        removerExecucaoAtiva($arquivoExecucoesAtivas, (string) $ativo['idt']);

                        $resultadosExecucao[] = [
                            'idt' => (string) $ativo['idt'],
                            'acao' => $ativo['acao'],
                            'codigo_retorno' => $codigoRetorno,
                            'comando' => (string) $ativo['comando'],
                            'saida' => $saidaCombinada
                        ];

                        unset($ativos[$indiceAtivo]);
                    }

                    $ativos = array_values($ativos);
                }

                salvarExecucoesAtivas($arquivoExecucoesAtivas, []);

                $idtsExecutadosConsultar = array_values(array_unique($idtsExecutadosConsultar));
                if (count($idtsExecutadosConsultar) > 0) {
                    error_log('CONSULTAR_NOTAS_DETALHES reconsultando por idt banco=' . $nomeBanco . ' quantidade=' . count($idtsExecutadosConsultar));
                    $notasAtualizadas = buscarNotasPorIdts($conexao, $nomeBanco, $idtsExecutadosConsultar);
                    $indicesResultadosPorIdt = [];
                    foreach ($resultadosExecucao as $indiceResultado => $resultadoExecucao) {
                        $idtResultado = isset($resultadoExecucao['idt']) ? (string) $resultadoExecucao['idt'] : '';
                        if ($idtResultado !== '') {
                            $indicesResultadosPorIdt[$idtResultado] = $indiceResultado;
                        }
                    }

                    foreach ($idtsExecutadosConsultar as $idtExecutado) {
                        if (!isset($notasAtualizadas[$idtExecutado])) {
                            if (isset($indicesResultadosPorIdt[$idtExecutado])) {
                                $indiceResultado = $indicesResultadosPorIdt[$idtExecutado];
                                $textoAtual = trim((string) ($resultadosExecucao[$indiceResultado]['saida'] ?? ''));
                                $textoConsulta = 'Reconsulta por IDT sem retorno no banco.';
                                $resultadosExecucao[$indiceResultado]['saida'] = $textoAtual !== '' ? ($textoAtual . "
" . $textoConsulta) : $textoConsulta;
                            }
                            continue;
                        }

                        $linhaAtualizada = $notasAtualizadas[$idtExecutado];
                        $statusAtual = trim((string) ($linhaAtualizada['status'] ?? ''));
                        $descricaoAtual = trim((string) ($linhaAtualizada['descricao'] ?? ''));
                        $textoConsulta = 'Status atual: ' . $statusAtual;
                        if ($descricaoAtual !== '') {
                            $textoConsulta .= ' - ' . $descricaoAtual;
                        }

                        if (isset($indicesResultadosPorIdt[$idtExecutado])) {
                            $indiceResultado = $indicesResultadosPorIdt[$idtExecutado];
                            $textoAtual = trim((string) ($resultadosExecucao[$indiceResultado]['saida'] ?? ''));
                            $resultadosExecucao[$indiceResultado]['saida'] = $textoAtual !== '' ? ($textoAtual . "
" . $textoConsulta) : $textoConsulta;
                        }

                        if (in_array($statusAtual, $statusEncerrados, true)) {
                            $idtsRemover[] = $idtExecutado;
                            continue;
                        }

                        $linhaAtualizada['destacar_vermelho'] = '1';
                        $linhasAtualizar[] = $linhaAtualizada;
                    }
                }

                $quantidadeExecutada = count($idtsSelecionados) - count($idtsBloqueadosOffline);
                if ($acao === 'acerto_w' && count($idtsBloqueadosOffline) > 0) {
                    $mensagemExecucao = 'Ação ' . $acao . ' executada para ' . $quantidadeExecutada . ' nota(s). ' . count($idtsBloqueadosOffline) . ' nota(s) modelo 55 foram bloqueadas no Offline.';
                } else {
                    $mensagemExecucao = 'Ação ' . $acao . ' executada para ' . count($idtsSelecionados) . ' nota(s).';
                }
                $classeMensagem = 'ok';
            }
        }
    }

    if ($respostaAjax) {
        $conexao->close();
        header('Content-Type: application/json; charset=utf-8');
        echo codificarJsonSeguro([
            'ok' => $classeMensagem !== 'erro',
            'classeMensagem' => $classeMensagem,
            'mensagemExecucao' => $mensagemExecucao,
            'resultadosExecucao' => array_values($resultadosExecucao),
            'idtsRemover' => array_values(array_unique($idtsRemover)),
            'linhasAtualizar' => array_values($linhasAtualizar)
        ], 'CONSULTAR_NOTAS_DETALHES ajax acao');
        exit;
    }
}


if ($conexao instanceof mysqli) {
    $conexao->close();
}

$linhasJson = codificarJsonSeguro(array_values($linhas), 'CONSULTAR_NOTAS_DETALHES linhas');
$linhasResultadoJson = codificarJsonSeguro(array_values($linhasResultado), 'CONSULTAR_NOTAS_DETALHES linhasResultado');
$versaoCssDetalhes = @filemtime(__DIR__ . '/consultar_notas_detalhes.css');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Detalhes das Notas</title>
<link rel="stylesheet" href="consultar_notas_detalhes.css<?= $versaoCssDetalhes ? '?v=' . $versaoCssDetalhes : '' ?>">
<style>
.filter-grid-detalhes { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
.filter-grid-detalhes .filter-item { display:flex; flex-direction:column; gap:4px; }
.filter-grid-detalhes .filter-item input { min-height:36px; box-sizing:border-box; }
.filter-grid-detalhes .filter-item.status input { width:78px; }
.filter-grid-detalhes .filter-item.operacao input { width:68px; text-transform:uppercase; }
.filter-grid-detalhes .filter-item.modelo input { width:68px; }
.filter-grid-detalhes .filter-item.limpar { justify-content:flex-end; width:132px; min-width:132px; }
.filter-grid-detalhes .filter-item.limpar .botao { min-height:52px; width:100%; }
</style>
<script>
var LINHAS_INICIAIS = <?= $linhasJson ?: '[]' ?>;
var RESULTADOS_EXECUCAO_INICIAIS = [];
var DB_UI_NFE = 'consulta_erros_nfe_ui';
var DB_VERSAO_UI_NFE = 2;
var CANAL_ATUALIZACAO_RESUMO = 'consulta_erros_nfe_resumo';
var LIMPAR_FILTRO_AO_ABRIR = <?= $limparFiltroAoAbrir ? 'true' : 'false' ?>;
var STORAGE_ATUALIZACAO_RESUMO = 'consulta_erros_nfe_resumo_update';
var dbUiPromise = null;
var canalResumoAtualizacao = null;
var ESTADO_DETALHES = {
    linhas: Array.isArray(LINHAS_INICIAIS) ? LINHAS_INICIAIS : [],
    filtro: '',
    filtroStatus: '',
    filtroOperacao: '',
    filtroModelo: '',
    selecionados: new Set(),
    envioEmAndamento: false,
    persistenciaUi: null,
    cacheKey: <?= json_encode('detalhes::' . $_SERVER['PHP_SELF'] . '::' . $identificadorServidorTela . '::' . $nomeBanco . '::' . $diasConsulta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    uiKey: <?= json_encode('ui::' . $_SERVER['PHP_SELF'] . '::' . $identificadorServidorTela . '::' . $nomeBanco . '::' . $diasConsulta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
var URL_CARREGAR_DETALHES = 'consultar_notas_detalhes.php?ajax_carregar=1&<?= $parametrosBaseDetalhes ?>';
var GRUPO_DETALHES = <?= json_encode($grupoDetalhes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var GRUPO_CONTEXTO = <?= json_encode($grupoContexto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var DATA_ATUAL_CONSULTA_IDT = (function() {
    var agora = new Date();
    var ano = String(agora.getFullYear());
    var mes = String(agora.getMonth() + 1).padStart(2, '0');
    var dia = String(agora.getDate()).padStart(2, '0');
    return ano + mes + dia;
})();
var GRUPO_PARA_CONSULTA_IDT = String(GRUPO_DETALHES || GRUPO_CONTEXTO || '').trim();
function mostrarOverlayConsultaDados(texto, subtexto) {
    var overlay = document.getElementById('overlayConsultaDados');
    if (!overlay) { return; }
    var textoEl = document.getElementById('overlayConsultaDadosTexto');
    var subtextoEl = document.getElementById('overlayConsultaDadosSubtexto');
    if (textoEl) {
        textoEl.textContent = texto || 'Consultando dados das notas...';
    }
    if (subtextoEl) {
        subtextoEl.textContent = subtexto || 'Aguarde enquanto as informações são carregadas.';
    }
    overlay.hidden = false;
}
function ocultarOverlayConsultaDados() {
    var overlay = document.getElementById('overlayConsultaDados');
    if (!overlay) { return; }
    overlay.hidden = true;
}

function abrirOrigemEmNovaAba(url) {
    window.open(url, '_blank', 'noopener,noreferrer');
    return false;
}
function abrirConsultaIdtComOverlay(url) {
    url = String(url || '').trim();
    if (url === '') {
        return false;
    }

    var novaAba = window.open('', '_blank');
    if (!novaAba) {
        window.open(url, '_blank');
        return false;
    }

    try {
        novaAba.document.open();
        novaAba.document.write('<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Carregando consulta</title><style>html,body{height:100%;margin:0;}body{display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;background:#eef3f9;color:#1b2a3a;font-family:Inter,Segoe UI,Arial,Helvetica,sans-serif;} .loader-wrap{width:min(560px,100%);padding:28px 26px;border-radius:22px;border:1px solid rgba(47,125,225,0.24);background:linear-gradient(180deg,rgba(255,255,255,0.99),rgba(245,249,255,0.98));box-shadow:0 18px 42px rgba(71,95,128,0.14);text-align:center;} .loader-spinner{width:54px;height:54px;margin:0 auto 16px auto;border-radius:50%;border:4px solid rgba(47,125,225,0.18);border-top-color:rgba(47,125,225,0.92);animation:giro 0.9s linear infinite;} .loader-titulo{margin:0;font-size:15px;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;color:#2b4058;} .loader-texto{margin-top:10px;font-size:22px;font-weight:800;color:#2f9d57;} .loader-subtexto{margin-top:10px;color:#5f738c;font-size:13px;} @keyframes giro{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}</style></head><body><div class="loader-wrap"><div class="loader-spinner"></div><div class="loader-titulo">Carregando</div><div class="loader-texto">Abrindo consulta do IDT...</div><div class="loader-subtexto">Aguarde enquanto a nova aba prepara a consulta automática.</div></div></body></html>');
        novaAba.document.close();
    } catch (erro) {
    }

    window.setTimeout(function() {
        try {
            novaAba.location.replace(url);
        } catch (erro) {
            novaAba.location.href = url;
        }
    }, 20);

    return false;
}
function carregarLinhasIniciaisOnline() {
    mostrarOverlayConsultaDados('Consultando dados das notas...', 'Aguarde enquanto as informações são carregadas.');
    return fetch(URL_CARREGAR_DETALHES, {
        method: 'GET',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
        .then(function(resposta) {
            if (!resposta.ok) {
                throw new Error('Falha HTTP ao carregar detalhes');
            }
            return resposta.json();
        })
        .then(function(payload) {
            if (!payload || payload.ok !== true || !Array.isArray(payload.linhas)) {
                throw new Error(payload && payload.erro ? String(payload.erro) : 'Falha ao carregar detalhes das notas.');
            }
            ESTADO_DETALHES.linhas = payload.linhas;
            return payload;
        })
        .finally(function() {
            ocultarOverlayConsultaDados();
        });
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
function montarLinkOrigem(item) {
    var origemTexto = String((item && item.origem) || '');
    if (origemTexto === '') {
        return '';
    }
    if (GRUPO_CONTEXTO === '') {
        return escaparHtml(origemTexto);
    }
    var url = 'consultar_emissores.php?grupo=' + encodeURIComponent(GRUPO_CONTEXTO) +
        '&consultar=1';
    return '<a class="text-link" href="' + escaparHtml(url) + '" target="_blank" rel="noopener noreferrer" onclick="window.open(this.href, \'_blank\', \'noopener,noreferrer\'); return false;">' + escaparHtml(origemTexto) + '</a>';
}
function obterPayloadResumoAtualizado() {
    return {
        tipo: 'quantidade_detalhes_atualizada',
        indiceServidor: <?= json_encode($identificadorServidorTela, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        servidor: <?= json_encode($identificacao, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        endereco: <?= json_encode($endereco, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        porta: <?= json_encode((string) $porta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        database: <?= json_encode($nomeBanco, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        diasConsulta: <?= (int) $diasConsulta ?>,
        quantidade: ESTADO_DETALHES.linhas.length,
        atualizadoEm: new Date().toISOString()
    };
}
function emitirAtualizacaoResumo(payload) {
    if (typeof window.BroadcastChannel === 'function') {
        try {
            if (!canalResumoAtualizacao) {
                canalResumoAtualizacao = new BroadcastChannel(CANAL_ATUALIZACAO_RESUMO);
            }
            canalResumoAtualizacao.postMessage(payload);
        } catch (erro) {}
    }
    try {
        window.localStorage.setItem(STORAGE_ATUALIZACAO_RESUMO, JSON.stringify(payload));
    } catch (erro) {}
}
function registroResumoCombinaComDetalhe(registro, database) {
    if (Number(registro.diasConsulta || 0) !== <?= (int) $diasConsulta ?>) {
        return false;
    }
    var filtro = String(registro.filtroDatabase || '').toLowerCase();
    var nome = String(database || '').toLowerCase();
    return !filtro || nome.indexOf(filtro) !== -1;
}
function atualizarRegistrosResumoNoCache(payload) {
    return abrirBancoUi().then(function(db) {
        if (!db) { return false; }
        return new Promise(function(resolve) {
            var houveAtualizacao = false;
            try {
                var tx = db.transaction('resumo', 'readwrite');
                var store = tx.objectStore('resumo');
                var request = store.openCursor();
                request.onsuccess = function(evento) {
                    var cursor = evento.target.result;
                    if (!cursor) {
                        return;
                    }
                    var registro = cursor.value || {};
                    if (!registroResumoCombinaComDetalhe(registro, payload.database)) {
                        cursor.continue();
                        return;
                    }
                    var linhas = Array.isArray(registro.linhas) ? registro.linhas : [];
                    var chaveAtual = [String(payload.indiceServidor || ''), String(payload.database || '').toLowerCase(), String(payload.porta || '')].join('::');
                    var indiceExistente = -1;
                    for (var i = 0; i < linhas.length; i++) {
                        var linhaAtual = linhas[i] || {};
                        var chaveLinha = [String(linhaAtual.indiceServidor || ''), String(linhaAtual.database || '').toLowerCase(), String(linhaAtual.porta || '')].join('::');
                        if (chaveLinha === chaveAtual) {
                            indiceExistente = i;
                            break;
                        }
                    }
                    var quantidade = Number(payload.quantidade || 0);
                    var novaLinha = {
                        servidor: String(payload.servidor || ''),
                        endereco: String(payload.endereco || ''),
                        porta: String(payload.porta || ''),
                        database: String(payload.database || ''),
                        quantidade: String(payload.quantidade || '0'),
                        classeLinha: quantidade > 100 ? 'linha-vermelha' : 'linha-verde',
                        indiceServidor: String(payload.indiceServidor || ''),
                        diasConsulta: String(payload.diasConsulta || '')
                    };
                    if (quantidade > 0) {
                        if (indiceExistente >= 0) {
                            linhas[indiceExistente] = novaLinha;
                        } else {
                            linhas.push(novaLinha);
                        }
                    } else if (indiceExistente >= 0) {
                        linhas.splice(indiceExistente, 1);
                    }
                    var total = 0;
                    for (var j = 0; j < linhas.length; j++) {
                        total += Number((linhas[j] || {}).quantidade || 0);
                    }
                    registro.linhas = linhas;
                    if (!registro.resumo || typeof registro.resumo !== 'object') {
                        registro.resumo = {};
                    }
                    registro.resumo.bases = linhas.length;
                    registro.resumo.total = total;
                    registro.atualizadoEm = payload.atualizadoEm || new Date().toISOString();
                    cursor.update(registro);
                    houveAtualizacao = true;
                    cursor.continue();
                };
                request.onerror = function() { resolve(false); };
                tx.oncomplete = function() { resolve(houveAtualizacao); };
                tx.onerror = function() { resolve(false); };
            } catch (erro) {
                resolve(false);
            }
        });
    });
}
function sincronizarResumoComDetalhes() {
    var payload = obterPayloadResumoAtualizado();
    atualizarRegistrosResumoNoCache(payload).then(function() {
        emitirAtualizacaoResumo(payload);
    });
}
function normalizarTextoFiltro(texto) {
    texto = (texto || '').toString().toLowerCase();
    if (texto.normalize) {
        texto = texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    return texto;
}
function atualizarBadgeCache(texto, classe) {
    var el = document.getElementById('badgeCacheDetalhes');
    if (!el) { return; }
    el.className = 'cache-pill ' + (classe || '');
    el.textContent = texto;
}
function obterLinhasFiltradas() {
    var filtroDescricao = normalizarTextoFiltro(ESTADO_DETALHES.filtro);
    var filtroStatus = String(ESTADO_DETALHES.filtroStatus || '').trim();
    var filtroOperacao = normalizarTextoFiltro(ESTADO_DETALHES.filtroOperacao);
    var filtroModelo = String(ESTADO_DETALHES.filtroModelo || '').trim();

    return ESTADO_DETALHES.linhas.filter(function(item) {
        var descricaoOk = !filtroDescricao || normalizarTextoFiltro(item.descricao || '').indexOf(filtroDescricao) !== -1;
        var statusOk = !filtroStatus || String(item.status || '').trim() === filtroStatus;
        var operacaoOk = !filtroOperacao || normalizarTextoFiltro(item.operacao || '') === filtroOperacao;
        var modeloOk = !filtroModelo || String(item.modelo || '').trim() === filtroModelo;
        return descricaoOk && statusOk && operacaoOk && modeloOk;
    });
}
function montarClasseLinha(item) {
    var classes = [];
    if (String(item.modelo || '') === '65') {
        classes.push('linha-modelo-65');
    }
    if (item.destacar_vermelho) {
        classes.push('linha-problema');
    }
    return classes.join(' ');
}
function atualizarContadores() {
    var total = ESTADO_DETALHES.linhas.length;
    var visiveis = obterLinhasFiltradas().length;
    var selecionadas = ESTADO_DETALHES.selecionados.size;
    var mapa = {
        contadorTotalNotas: total,
        contadorVisiveis: visiveis,
        contadorSelecionadas: selecionadas,
        infoTotalNotas: total,
        infoVisiveis: visiveis,
        infoSelecionadas: selecionadas
    };
    Object.keys(mapa).forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = mapa[id];
        }
    });
}
function atualizarCheckTopo() {
    var topo = document.getElementById('checkTopo');
    if (!topo) { return; }
    var linhas = obterLinhasFiltradas();
    if (!linhas.length) {
        topo.checked = false;
        return;
    }
    for (var i = 0; i < linhas.length; i++) {
        if (!ESTADO_DETALHES.selecionados.has(String(linhas[i].idt || ''))) {
            topo.checked = false;
            return;
        }
    }
    topo.checked = true;
}
function atualizarMensagemVazia() {
    var mensagem = document.getElementById('mensagemVazia');
    if (!mensagem) { return; }
    if (!ESTADO_DETALHES.linhas.length) {
        mensagem.textContent = 'Nenhuma nota com problema foi encontrada para este database.';
        mensagem.style.display = 'flex';
        return;
    }
    if (!obterLinhasFiltradas().length) {
        mensagem.textContent = 'Nenhuma nota corresponde aos filtros informados.';
        mensagem.style.display = 'flex';
        return;
    }
    mensagem.style.display = 'none';
}

function montarLinkOrigem(item) {
    var origem = String(item && item.origem ? item.origem : '').trim();
    if (origem === '') {
        return '';
    }
    var url = 'consultar_emissores.php?grupo=' + encodeURIComponent(String(GRUPO_DETALHES || '')) +
        '&origem=' + encodeURIComponent(origem);
    return '<a class="text-link" href="' + escaparHtml(url) + '" target="_blank" rel="noopener noreferrer" onclick="return abrirOrigemEmNovaAba(this.href);">' + escaparHtml(origem) + '</a>';
}

function montarUrlConsultaIdt(idt) {
    idt = String(idt || '').trim();
    if (idt === '') {
        return '';
    }
    var url = 'consultar_idt.php?idt=' + encodeURIComponent(idt) +
        '&data_consulta=' + encodeURIComponent(DATA_ATUAL_CONSULTA_IDT);
    if (GRUPO_PARA_CONSULTA_IDT !== '') {
        url += '&grupo=' + encodeURIComponent(GRUPO_PARA_CONSULTA_IDT) + '&auto=1';
    }
    return url;
}

function montarLinkIdt(item) {
    var idt = String(item && item.idt ? item.idt : '').trim();
    if (idt === '') {
        return '';
    }
    var url = montarUrlConsultaIdt(idt);
    if (url === '') {
        return escaparHtml(idt);
    }
    return '<a class="text-link" href="' + escaparHtml(url) + '" target="_blank" rel="noopener noreferrer" onclick="return abrirConsultaIdtComOverlay(this.href);">' + escaparHtml(idt) + '</a>';
}

function renderizarTabelaDetalhes() {
    var corpo = document.getElementById('corpoNotas');
    if (!corpo) { return; }
    var linhas = obterLinhasFiltradas();
    if (!linhas.length) {
        corpo.innerHTML = '';
        atualizarContadores();
        atualizarMensagemVazia();
        atualizarCheckTopo();
        return;
    }
    var html = '';
    for (var i = 0; i < linhas.length; i++) {
        var item = linhas[i];
        var idt = String(item.idt || '');
        var checked = ESTADO_DETALHES.selecionados.has(idt) ? ' checked' : '';
        html += '<tr class="' + escaparHtml(montarClasseLinha(item)) + '">' +
            '<td class="col-selecao"><input class="check-nota" type="checkbox" data-idt="' + escaparHtml(idt) + '"' + checked + '></td>' +
            '<td class="col-idt">' + montarLinkIdt(item) + '</td>' +
            '<td class="col-curta">' + escaparHtml(item.cancelado || '') + '</td>' +
            '<td class="col-emissao">' + escaparHtml(item.emissao || '') + '</td>' +
            '<td class="col-curta">' + escaparHtml(item.hora || '') + '</td>' +
            '<td class="col-curta">' + escaparHtml(item.nota || '') + '</td>' +
            '<td class="col-curta">' + escaparHtml(item.serie || '') + '</td>' +
            '<td class="col-curta">' + escaparHtml(item.modelo || '') + '</td>' +
            '<td class="col-curta">' + montarLinkOrigem(item) + '</td>' +
            '<td class="col-curta">' + escaparHtml(item.chave || '') + '</td>' +
            '<td class="col-curta">' + escaparHtml(item.operacao || '') + '</td>' +
            '<td class="col-status">' + escaparHtml(item.status || '') + '</td>' +
            '<td class="col-descricao">' + escaparHtml(item.descricao || '') + '</td>' +
            '</tr>';
    }
    corpo.innerHTML = html;
    atualizarContadores();
    atualizarMensagemVazia();
    atualizarCheckTopo();
}
function salvarEstadoUiDebounce() {
    if (ESTADO_DETALHES.persistenciaUi) {
        window.clearTimeout(ESTADO_DETALHES.persistenciaUi);
    }
    ESTADO_DETALHES.persistenciaUi = window.setTimeout(function() {
        salvarRegistro('ui_estado', {
            cacheKey: ESTADO_DETALHES.uiKey,
            filtro: ESTADO_DETALHES.filtro,
            filtroStatus: ESTADO_DETALHES.filtroStatus,
            filtroOperacao: ESTADO_DETALHES.filtroOperacao,
            filtroModelo: ESTADO_DETALHES.filtroModelo,
            selecionados: Array.from(ESTADO_DETALHES.selecionados),
            atualizadoEm: new Date().toISOString()
        });
    }, 160);
}
function salvarCacheDetalhes() {
    salvarRegistro('detalhes', {
        cacheKey: ESTADO_DETALHES.cacheKey,
        linhas: ESTADO_DETALHES.linhas,
        atualizadoEm: new Date().toISOString(),
        servidor: <?= json_encode($identificacao, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        database: <?= json_encode($nomeBanco, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        diasConsulta: <?= (int) $diasConsulta ?>
    }).then(function(ok) {
        if (ok) {
            atualizarBadgeCache('Tabela local atualizada', 'cache-local');
        }
    });
}
function restaurarEstadoUi() {
    return lerRegistro('ui_estado', ESTADO_DETALHES.uiKey).then(function(registro) {
        var filtroDescricao = document.getElementById('filtroDescricao');
        var filtroStatus = document.getElementById('filtroStatus');
        var filtroOperacao = document.getElementById('filtroOperacao');
        var filtroModelo = document.getElementById('filtroModelo');

        if (!registro || LIMPAR_FILTRO_AO_ABRIR) {
            ESTADO_DETALHES.filtro = '';
            ESTADO_DETALHES.filtroStatus = '';
            ESTADO_DETALHES.filtroOperacao = '';
            ESTADO_DETALHES.filtroModelo = '';
        } else {
            ESTADO_DETALHES.filtro = String(registro.filtro || '');
            ESTADO_DETALHES.filtroStatus = String(registro.filtroStatus || '');
            ESTADO_DETALHES.filtroOperacao = String(registro.filtroOperacao || '');
            ESTADO_DETALHES.filtroModelo = String(registro.filtroModelo || '');
        }

        if (filtroDescricao) {
            filtroDescricao.value = ESTADO_DETALHES.filtro;
        }
        if (filtroStatus) {
            filtroStatus.value = ESTADO_DETALHES.filtroStatus;
        }
        if (filtroOperacao) {
            filtroOperacao.value = ESTADO_DETALHES.filtroOperacao;
        }
        if (filtroModelo) {
            filtroModelo.value = ESTADO_DETALHES.filtroModelo;
        }

        var validos = new Set(ESTADO_DETALHES.linhas.map(function(item) { return String(item.idt || ''); }));
        ESTADO_DETALHES.selecionados = new Set((Array.isArray(registro && registro.selecionados) ? registro.selecionados : []).filter(function(idt) {
            return validos.has(String(idt || ''));
        }).map(function(idt) { return String(idt); }));
    });
}
function aplicarFiltroDescricao() {
    var campo = document.getElementById('filtroDescricao');
    ESTADO_DETALHES.filtro = campo ? campo.value : '';
    renderizarTabelaDetalhes();
    salvarEstadoUiDebounce();
}
function aplicarFiltroStatus() {
    var campo = document.getElementById('filtroStatus');
    var valor = campo ? String(campo.value || '').replace(/\D+/g, '').slice(0, 3) : '';
    if (campo) {
        campo.value = valor;
    }
    ESTADO_DETALHES.filtroStatus = valor;
    renderizarTabelaDetalhes();
    salvarEstadoUiDebounce();
}
function aplicarFiltroOperacao() {
    var campo = document.getElementById('filtroOperacao');
    var valor = campo ? String(campo.value || '').replace(/\s+/g, '').slice(0, 1).toUpperCase() : '';
    if (campo) {
        campo.value = valor;
    }
    ESTADO_DETALHES.filtroOperacao = valor;
    renderizarTabelaDetalhes();
    salvarEstadoUiDebounce();
}
function aplicarFiltroModelo() {
    var campo = document.getElementById('filtroModelo');
    var valor = campo ? String(campo.value || '').replace(/\D+/g, '').slice(0, 2) : '';
    if (campo) {
        campo.value = valor;
    }
    ESTADO_DETALHES.filtroModelo = valor;
    renderizarTabelaDetalhes();
    salvarEstadoUiDebounce();
}
function limparTodosOsFiltros() {
    ESTADO_DETALHES.filtro = '';
    ESTADO_DETALHES.filtroStatus = '';
    ESTADO_DETALHES.filtroOperacao = '';
    ESTADO_DETALHES.filtroModelo = '';

    var filtroDescricao = document.getElementById('filtroDescricao');
    var filtroStatus = document.getElementById('filtroStatus');
    var filtroOperacao = document.getElementById('filtroOperacao');
    var filtroModelo = document.getElementById('filtroModelo');

    if (filtroDescricao) { filtroDescricao.value = ''; }
    if (filtroStatus) { filtroStatus.value = ''; }
    if (filtroOperacao) { filtroOperacao.value = ''; }
    if (filtroModelo) { filtroModelo.value = ''; }

    renderizarTabelaDetalhes();
    salvarEstadoUiDebounce();
}
function marcarTodos() {
    var linhas = obterLinhasFiltradas();
    for (var i = 0; i < linhas.length; i++) {
        ESTADO_DETALHES.selecionados.add(String(linhas[i].idt || ''));
    }
    renderizarTabelaDetalhes();
    salvarEstadoUiDebounce();
}
function desmarcarTodos() {
    var linhas = obterLinhasFiltradas();
    for (var i = 0; i < linhas.length; i++) {
        ESTADO_DETALHES.selecionados.delete(String(linhas[i].idt || ''));
    }
    renderizarTabelaDetalhes();
    salvarEstadoUiDebounce();
}
function marcarTodosCheckbox(origem) {
    var linhas = obterLinhasFiltradas();
    for (var i = 0; i < linhas.length; i++) {
        var idt = String(linhas[i].idt || '');
        if (origem.checked) {
            ESTADO_DETALHES.selecionados.add(idt);
        } else {
            ESTADO_DETALHES.selecionados.delete(idt);
        }
    }
    renderizarTabelaDetalhes();
    salvarEstadoUiDebounce();
}
function preencherIdtsSelecionados() {
    var container = document.getElementById('idtsSelecionadosContainer');
    if (!container) { return; }
    var selecionados = Array.from(ESTADO_DETALHES.selecionados);
    selecionados.sort(function(a, b) { return String(a).localeCompare(String(b), 'pt-BR', { numeric: true }); });
    var html = '';
    for (var i = 0; i < selecionados.length; i++) {
        html += '<input type="hidden" name="idts[]" value="' + escaparHtml(selecionados[i]) + '">';
    }
    container.innerHTML = html;
}

function obterLinhasSelecionadasParaEnvio() {
    var mapaSelecionados = {};
    ESTADO_DETALHES.linhas.forEach(function(item) {
        var idt = String((item || {}).idt || '');
        if (!idt || !ESTADO_DETALHES.selecionados.has(idt)) {
            return;
        }
        mapaSelecionados[idt] = {
            idt: idt,
            cancelado: String(item.cancelado || ''),
            emissao: String(item.emissao || ''),
            hora: String(item.hora || ''),
            nota: String(item.nota || ''),
            serie: String(item.serie || ''),
            modelo: String(item.modelo || ''),
            origem: String(item.origem || ''),
            chave: String(item.chave || ''),
            operacao: String(item.operacao || ''),
            status: String(item.status || ''),
            descricao: String(item.descricao || '')
        };
    });
    return Object.keys(mapaSelecionados).sort(function(a, b) {
        return String(a).localeCompare(String(b), 'pt-BR', { numeric: true });
    }).map(function(idt) {
        return mapaSelecionados[idt];
    });
}
function preencherLinhasSelecionadasJson() {
    var campo = document.getElementById('linhasSelecionadasJson');
    if (!campo) { return; }
    try {
        campo.value = JSON.stringify(obterLinhasSelecionadasParaEnvio());
    } catch (erro) {
        campo.value = '[]';
    }
}
function mostrarMensagemExecucao(texto, classe) {
    var box = document.getElementById('mensagemExecucaoBox');
    if (!box) { return; }
    box.className = 'status-message ' + (classe || 'ok');
    box.textContent = texto || '';
    box.style.display = texto ? 'block' : 'none';
}
function renderizarResultadoAcoes(resultados) {
    var secao = document.getElementById('secaoResultadoAcoes');
    var corpo = document.getElementById('resultadoAcoesBody');
    if (!secao || !corpo) { return; }
    resultados = Array.isArray(resultados) ? resultados : [];
    if (!resultados.length) {
        corpo.innerHTML = '';
        secao.style.display = 'none';
        return;
    }
    var html = '';
    for (var i = 0; i < resultados.length; i++) {
        var item = resultados[i] || {};
        html += '<tr>' +
            '<td class="col-idt">' + escaparHtml(item.idt || '') + '</td>' +
            '<td class="col-curta">' + escaparHtml(item.acao || '') + '</td>' +
            '<td class="col-curta">' + escaparHtml(item.codigo_retorno || '') + '</td>' +
            '<td class="col-descricao" style="white-space: pre-wrap;">' + escaparHtml(item.saida || '') + '</td>' +
            '</tr>';
    }
    corpo.innerHTML = html;
    secao.style.display = '';
}
function aplicarRetornoAcao(dados) {
    var idtsRemover = new Set((Array.isArray(dados.idtsRemover) ? dados.idtsRemover : []).map(function(idt) {
        return String(idt || '');
    }));
    var atualizacoes = {};
    (Array.isArray(dados.linhasAtualizar) ? dados.linhasAtualizar : []).forEach(function(item) {
        var idt = String((item || {}).idt || '');
        if (!idt) { return; }
        atualizacoes[idt] = item || {};
    });

    var novasLinhas = [];
    for (var i = 0; i < ESTADO_DETALHES.linhas.length; i++) {
        var linha = ESTADO_DETALHES.linhas[i] || {};
        var idtLinha = String(linha.idt || '');
        if (!idtLinha) {
            continue;
        }
        if (idtsRemover.has(idtLinha)) {
            ESTADO_DETALHES.selecionados.delete(idtLinha);
            continue;
        }
        if (atualizacoes[idtLinha]) {
            linha = Object.assign({}, linha, atualizacoes[idtLinha]);
        }
        novasLinhas.push(linha);
        ESTADO_DETALHES.selecionados.delete(idtLinha);
    }
    ESTADO_DETALHES.linhas = novasLinhas;
    renderizarTabelaDetalhes();
    salvarCacheDetalhes();
    salvarEstadoUiDebounce();
    sincronizarResumoComDetalhes();
}
function definirEstadoAcoesBloqueadas(bloquear) {
    var botoes = document.querySelectorAll('.actions-panel .botao[type=submit], .actions-panel button.botao');
    for (var i = 0; i < botoes.length; i++) {
        botoes[i].disabled = bloquear ? true : false;
        if (bloquear) {
            botoes[i].classList.add('botao-desabilitado');
        } else {
            botoes[i].classList.remove('botao-desabilitado');
        }
    }
}
function validarEnvio(acao) {
    if (ESTADO_DETALHES.envioEmAndamento) {
        return false;
    }
    if (ESTADO_DETALHES.selecionados.size === 0) {
        alert('Selecione pelo menos uma nota.');
        return false;
    }
    preencherIdtsSelecionados();
    preencherLinhasSelecionadasJson();
    var campoAcao = document.getElementById('acaoFormulario');
    if (campoAcao) {
        campoAcao.value = acao;
    }
    ESTADO_DETALHES.envioEmAndamento = true;
    salvarEstadoUiDebounce();
    return true;
}
function validarEnvioLink(evento, acao) {
    if (evento && evento.preventDefault) {
        evento.preventDefault();
    }
    if (!validarEnvio(acao)) {
        return false;
    }
    return enviarFormularioAjax(evento || null);
}
function enviarFormularioAjax(evento) {
    if (evento && evento.preventDefault) {
        evento.preventDefault();
    }
    if (!ESTADO_DETALHES.envioEmAndamento) {
        return false;
    }
    var formulario = document.getElementById('formNotas');
    if (!formulario) {
        ESTADO_DETALHES.envioEmAndamento = false;
        definirEstadoAcoesBloqueadas(false);
        return false;
    }
    definirEstadoAcoesBloqueadas(true);
    atualizarBadgeCache('Executando ação com base na tabela local', 'cache-live');
    var dados = new FormData(formulario);
    fetch(window.location.href, {
        method: 'POST',
        body: dados,
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
        .then(function(resposta) {
            if (!resposta.ok) {
                throw new Error('Falha HTTP');
            }
            return resposta.json();
        })
        .then(function(payload) {
            mostrarMensagemExecucao(payload.mensagemExecucao || '', payload.classeMensagem || 'ok');
            renderizarResultadoAcoes(payload.resultadosExecucao || []);
            aplicarRetornoAcao(payload || {});
            ESTADO_DETALHES.envioEmAndamento = false;
            definirEstadoAcoesBloqueadas(false);
            atualizarBadgeCache('Tabela local sincronizada após ação', 'cache-local');
        })
        .catch(function() {
            ESTADO_DETALHES.envioEmAndamento = false;
            definirEstadoAcoesBloqueadas(false);
            atualizarBadgeCache('Falha ao sincronizar ação', 'cache-live');
            alert('Erro ao executar a ação.');
        });
    return false;
}
var urlExecucoesAtivas = 'consultar_notas_detalhes.php?ajax_execucoes=1&<?= $parametrosBaseDetalhes ?>';
var intervaloExecucoesAtivas = null;
function renderizarExecucoesAtivas(execucoes) {
    var corpo = document.getElementById('execucoesAtivasBody');
    if (!corpo) { return; }
    execucoes = Array.isArray(execucoes) ? execucoes.slice(0, 10) : [];
    var html = '';
    for (var i = 0; i < 5; i++) {
        var esquerda = execucoes[i] || null;
        var direita = execucoes[i + 5] || null;
        html += '<tr>';
        html += esquerda ? ('<td>' + escaparHtml(esquerda.idt || '') + '</td><td>' + escaparHtml(esquerda.acao || '') + '</td>') : '<td></td><td></td>';
        html += direita ? ('<td>' + escaparHtml(direita.idt || '') + '</td><td>' + escaparHtml(direita.acao || '') + '</td>') : '<td></td><td></td>';
        html += '</tr>';
    }
    corpo.innerHTML = html;
}
function atualizarExecucoesAtivas() {
    fetch(urlExecucoesAtivas, { cache: 'no-store' })
        .then(function(resposta) { return resposta.json(); })
        .then(function(dados) {
            renderizarExecucoesAtivas(dados.execucoes || []);
            atualizarBadgeCache('Monitor online ativo', 'cache-live');
        })
        .catch(function() {});
}
function iniciarMonitorExecucoesAtivas() {
    if (intervaloExecucoesAtivas) {
        clearInterval(intervaloExecucoesAtivas);
    }
    atualizarExecucoesAtivas();
    intervaloExecucoesAtivas = setInterval(atualizarExecucoesAtivas, 700);
}
window.addEventListener('change', function(evento) {
    var alvo = evento.target;
    if (!alvo || !alvo.classList || !alvo.classList.contains('check-nota')) {
        return;
    }
    var idt = String(alvo.getAttribute('data-idt') || '');
    if (!idt) { return; }
    if (alvo.checked) {
        ESTADO_DETALHES.selecionados.add(idt);
    } else {
        ESTADO_DETALHES.selecionados.delete(idt);
    }
    atualizarContadores();
    atualizarCheckTopo();
    salvarEstadoUiDebounce();
});
window.addEventListener('DOMContentLoaded', function() {
    carregarLinhasIniciaisOnline()
        .then(function() {
            return restaurarEstadoUi();
        })
        .then(function() {
            renderizarTabelaDetalhes();
            renderizarResultadoAcoes(RESULTADOS_EXECUCAO_INICIAIS);
            salvarCacheDetalhes();
            if (LIMPAR_FILTRO_AO_ABRIR) {
                salvarEstadoUiDebounce();
            }
            sincronizarResumoComDetalhes();
            iniciarMonitorExecucoesAtivas();
            atualizarBadgeCache('Tabela local atualizada', 'cache-local');
        })
        .catch(function(erro) {
            renderizarTabelaDetalhes();
            renderizarResultadoAcoes(RESULTADOS_EXECUCAO_INICIAIS);
            mostrarMensagemExecucao((erro && erro.message) ? erro.message : 'Erro ao carregar os dados das notas.', 'erro');
            atualizarBadgeCache('Falha ao carregar tabela', 'cache-live');
            iniciarMonitorExecucoesAtivas();
        });
});
</script>
</head>
<body>
<div id="overlayConsultaDados" class="overlay-consulta-dados">
    <div class="overlay-consulta-box">
        <div class="overlay-consulta-spinner"></div>
        <div class="overlay-consulta-titulo">Carregando</div>
        <div id="overlayConsultaDadosTexto" class="overlay-consulta-texto">Consultando dados das notas...</div>
        <div id="overlayConsultaDadosSubtexto" class="overlay-consulta-subtexto">Aguarde enquanto as informações são carregadas.</div>
    </div>
</div>
<div class="app-shell">
    <header class="topbar">
        <div class="title-row">
            <div class="title-main">
                <h1>Notas com problema</h1>
                <p>Painel operacional com filtro local, seleção persistida e tabela local no navegador.</p>
            </div>
            <div class="title-status-slot">
                <div id="mensagemExecucaoBox" class="status-message top-status-card <?= htmlspecialchars($classeMensagem, ENT_QUOTES, 'UTF-8') ?>" style="<?= $mensagemExecucao === '' ? 'display:none;' : '' ?>">
                    <?= htmlspecialchars($mensagemExecucao, ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <div class="header-tags">
                <span class="chip"><span>Servidor</span> <strong><?= htmlspecialchars($identificacao, ENT_QUOTES, 'UTF-8') ?></strong></span>
                <span class="chip"><span>Database</span> <strong><?= htmlspecialchars($nomeBanco, ENT_QUOTES, 'UTF-8') ?></strong></span>
                <span class="chip"><span>Dias</span> <strong><?= (int) $diasConsulta ?></strong></span>
            </div>
        </div>

        <div class="header-grid">
            <section class="panel actions-panel">
                <form id="formNotas" method="post" action="" onsubmit="return enviarFormularioAjax(event)">
                    <input type="hidden" id="acaoFormulario" name="acao" value="">
                    <?php if ($modoDireto): ?>
                    <input type="hidden" name="host" value="<?= htmlspecialchars($endereco, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="porta" value="<?= (int) $porta ?>">
                    <input type="hidden" name="nome" value="<?= htmlspecialchars($identificacao, ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                    <input type="hidden" name="s" value="<?= (int) $indiceServidor ?>">
                    <?php endif; ?>
                    <input type="hidden" name="db" value="<?= htmlspecialchars($nomeBanco, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="dias" value="<?= (int) $diasConsulta ?>">
                    <input type="hidden" name="grupo" value="<?= htmlspecialchars($grupoContexto, ENT_QUOTES, 'UTF-8') ?>">
                    <div id="idtsSelecionadosContainer"></div>
                    <input type="hidden" name="ajax_action" value="1">
                    <input type="hidden" id="linhasSelecionadasJson" name="linhas_selecionadas_json" value="">
                    <div class="panel-title">Ações disponíveis</div>
                    <div class="filter-box">
                        <div class="filter-grid-detalhes">
                            <div class="filter-item" style="flex:1 1 220px; min-width:220px;">
                                <label for="filtroDescricao">Filtrar descrição</label>
                                <input id="filtroDescricao" type="text" autocomplete="off" oninput="aplicarFiltroDescricao()" placeholder="Digite para filtrar pela descrição">
                            </div>
                            <div class="filter-item status">
                                <label for="filtroStatus">Status</label>
                                <input id="filtroStatus" type="text" inputmode="numeric" maxlength="3" autocomplete="off" oninput="aplicarFiltroStatus()" placeholder="000">
                            </div>
                            <div class="filter-item operacao">
                                <label for="filtroOperacao">Operação</label>
                                <input id="filtroOperacao" type="text" maxlength="1" autocomplete="off" oninput="aplicarFiltroOperacao()" placeholder="X">
                            </div>
                            <div class="filter-item modelo">
                                <label for="filtroModelo">Modelo</label>
                                <input id="filtroModelo" type="text" inputmode="numeric" maxlength="2" autocomplete="off" oninput="aplicarFiltroModelo()" placeholder="55">
                            </div>
                            <div class="filter-item limpar">
                                <label>&nbsp;</label>
                                <button class="botao" type="button" onclick="limparTodosOsFiltros()"><span>Limpar</span><small>filtros</small></button>
                            </div>
                        </div>
                    </div>
                    <div class="action-grid">
                        <button class="botao" type="submit" onclick="return validarEnvio('acerto_w');"><span>Offline</span><small>(acerto_w)</small></button>
                        <button class="botao" type="submit" onclick="return validarEnvio('consultarX');"><span>Consultar</span><small>(consultarX)</small></button>
                        <button class="botao" type="submit" onclick="return validarEnvio('enviarX');"><span>Enviar</span><small>(enviarX)</small></button>
                        <button class="botao" type="submit" onclick="return validarEnvio('acerto_v');"><span>Normal</span><small>(acerto_v)</small></button>
                        <button class="botao" type="submit" onclick="return validarEnvio('cancelarX');"><span>Cancelar</span><small>(cancelarX)</small></button>
                        <button class="botao" type="submit" onclick="return validarEnvio('inutilizarX');"><span>Inutilizar</span><small>(inutilizarX)</small></button>
                        <button class="botao oculto" type="submit" onclick="return validarEnvio('validarX');"><span>Validar</span><small>(validarX)</small></button>
                    </div>
                    <div class="mini-actions">
                        <span class="text-link" onclick="marcarTodos()">Marcar visíveis</span>
                        <span class="text-link" onclick="desmarcarTodos()">Desmarcar visíveis</span>
                        <span style="margin-left:auto; min-width:calc((100% - 20px) / 3); text-align:center;"><span class="text-link" onclick="return validarEnvioLink(event, 'removercancelamento');">Remover Cancelamento</span></span>
                    </div>
                </form>
            </section>

            <section class="panel info-panel">
                <div class="info-card accent">
                    <div class="label">Endereço</div>
                    <div class="value small"><?= htmlspecialchars($endereco, ENT_QUOTES, 'UTF-8') ?>:<?= (int) $porta ?></div>
                </div>
                <div class="info-card accent">
                    <div class="label">MySQL versão</div>
                    <div class="value" style="font-size:26px;"><?= (int) $versaoMysql ?></div>
                </div>
                <div class="info-card accent">
                    <div class="label">Plataforma AutoNFe</div>
                    <div class="value small"><?= htmlspecialchars(detectarPlataformaAutonfe(), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="info-card ok">
                    <div class="label">Total de notas</div>
                    <div class="value" id="infoTotalNotas"><?= count($linhas) ?></div>
                </div>
                <div class="info-card warn">
                    <div class="label">Linhas visíveis</div>
                    <div class="value" id="infoVisiveis">0</div>
                </div>
                <div class="info-card danger">
                    <div class="label">Selecionadas</div>
                    <div class="value" id="infoSelecionadas">0</div>
                </div>
            </section>

            <section class="panel exec-panel">
                <div class="exec-header">
                    <div>
                        <div class="panel-title" style="margin-bottom: 4px;">Ações em execução</div>
                        <div style="color: var(--muted); font-size: 13px;">Monitor em tempo real das execuções ativas.</div>
                    </div>
                    <span class="cache-pill" id="badgeCacheDetalhes">Preparando tabela local</span>
                </div>
                <div class="exec-table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>IDT</th>
                                <th>Ação</th>
                                <th>IDT</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody id="execucoesAtivasBody">
                        <?php for ($i = 0; $i < 5; $i++): ?>
                            <tr><td></td><td></td><td></td><td></td></tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </header>

    <main class="content-shell">
        <section class="surface" style="flex: 1 1 auto;">
            <div class="surface-header">
                <div>
                    <div class="surface-title">Tabela principal</div>
                    <div class="surface-subtitle">Filtros e marcações são tratados localmente para deixar a tela mais fluida.</div>
                </div>
                <div class="table-toolbar">
                    <span class="counter-pill">Total <strong id="contadorTotalNotas"><?= count($linhas) ?></strong></span>
                    <span class="counter-pill">Visíveis <strong id="contadorVisiveis">0</strong></span>
                    <span class="counter-pill">Selecionadas <strong id="contadorSelecionadas">0</strong></span>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="col-selecao">
                                <div class="check-header">
                                    <input id="checkTopo" type="checkbox" onclick="marcarTodosCheckbox(this)">
                                    <div class="check-actions">
                                        <span class="check-link" onclick="marcarTodos()">Todas</span>
                                        <span class="check-link" onclick="desmarcarTodos()">Nenhuma</span>
                                    </div>
                                </div>
                            </th>
                            <th class="col-idt">IDT</th>
                            <th class="col-curta">Cancelado</th>
                            <th class="col-emissao">Emissão</th>
                            <th class="col-curta">Hora</th>
                            <th class="col-curta">Nota</th>
                            <th class="col-curta">Série</th>
                            <th class="col-curta">Modelo</th>
                            <th class="col-curta">Origem</th>
                            <th class="col-curta">Chave</th>
                            <th class="col-curta">Operação</th>
                            <th class="col-status">Status</th>
                            <th class="col-descricao">Descrição</th>
                        </tr>
                    </thead>
                    <tbody id="corpoNotas"></tbody>
                </table>
            </div>
            <div class="empty-state" id="mensagemVazia" style="display:none;">Nenhuma nota com problema foi encontrada para este database.</div>
        </section>

        <section id="secaoResultadoAcoes" class="surface result-section" style="<?= count($resultadosExecucao) === 0 ? 'display:none;' : '' ?>">
            <div class="surface-header">
                <div>
                    <div class="surface-title">Resultado das ações</div>
                    <div class="surface-subtitle">Saída das execuções locais e reconsulta final por IDT somente nas notas executadas.</div>
                </div>
            </div>
            <div class="table-wrap table-wrap-results">
                <table>
                    <thead>
                        <tr>
                            <th class="col-idt">IDT</th>
                            <th class="col-curta">Ação</th>
                            <th class="col-curta">Retorno</th>
                            <th class="col-descricao">Saída</th>
                        </tr>
                    </thead>
                    <tbody id="resultadoAcoesBody">
                    <?php foreach ($resultadosExecucao as $resultadoExecucao): ?>
                        <tr>
                            <td class="col-idt"><?= htmlspecialchars((string) ($resultadoExecucao['idt'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="col-curta"><?= htmlspecialchars((string) ($resultadoExecucao['acao'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="col-curta"><?= htmlspecialchars((string) ($resultadoExecucao['codigo_retorno'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="col-descricao" style="white-space: pre-wrap;"><?= htmlspecialchars((string) ($resultadoExecucao['saida'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
