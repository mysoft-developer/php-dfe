<?php

declare(strict_types=1);

set_time_limit(0);
ini_set('max_execution_time', '0');

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

function listarValor(array $linha, array $campos, string $padrao = ''): string
{
    foreach ($campos as $campo) {
        if (array_key_exists($campo, $linha) && $linha[$campo] !== null && $linha[$campo] !== '') {
            return (string) $linha[$campo];
        }
    }

    return $padrao;
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

function carregarLinhasProblema(mysqli $conexao, string $nomeBanco, int $diasConsulta): array
{
    $nomeBancoEscapado = str_replace('`', '``', $nomeBanco);
    $diasConsultaSql = (int) $diasConsulta;

    $sqlDetalhes = "
        SELECT
            a.idt,
            a.nota,
            a.serie,
            a.modelo,
            a.origem,
            a.status,
            a.status_descricao
        FROM `{$nomeBancoEscapado}`.`notas_fiscais_eletronicas` a
        INNER JOIN `{$nomeBancoEscapado}`.`notas_fiscais` b
            ON a.idt = b.idt
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
            'nota' => listarValor($linha, ['nota']),
            'serie' => listarValor($linha, ['serie']),
            'modelo' => listarValor($linha, ['modelo']),
            'origem' => listarValor($linha, ['origem']),
            'status' => listarValor($linha, ['status']),
            'descricao' => listarValor($linha, ['status_descricao'])
        ];
    }

    $resultado->free();

    return $linhas;
}

function buscarNotaPorIdt(mysqli $conexao, string $database, int|string $idt): array
{
    $idt = (string) $idt;
    $databaseEscapado = str_replace('`', '``', $database);
    $idtEscapado = $conexao->real_escape_string($idt);

    $sql = "
        SELECT
            a.idt,
            a.nota,
            a.serie,
            a.modelo,
            a.origem,
            a.status,
            a.status_descricao
        FROM `{$databaseEscapado}`.`notas_fiscais_eletronicas` a
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
        'nota' => listarValor($linha, ['nota']),
        'serie' => listarValor($linha, ['serie']),
        'modelo' => listarValor($linha, ['modelo']),
        'origem' => listarValor($linha, ['origem']),
        'status' => listarValor($linha, ['status']),
        'descricao' => listarValor($linha, ['status_descricao'])
    ];
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

    $processo = @proc_open($comando, $descritores, $pipes, $diretorioTrabalho !== '' ? $diretorioTrabalho : null);

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
    !array_key_exists('mysql_senha', $configuracao) ||
    empty($configuracao['servidores']) ||
    !is_array($configuracao['servidores'])
) {
    sairComErro('Configuração incompleta.');
}

$indiceServidor = isset($_REQUEST['s']) ? (int) $_REQUEST['s'] : -1;
$nomeBanco = isset($_REQUEST['db']) ? trim((string) $_REQUEST['db']) : '';
$diasConsulta = isset($_REQUEST['dias']) ? (int) $_REQUEST['dias'] : 90;

if ($diasConsulta <= 0) {
    $diasConsulta = 90;
}

if ($indiceServidor < 0 || $nomeBanco === '') {
    sairComErro('Parâmetros inválidos.');
}

if (!isset($configuracao['servidores'][$indiceServidor]) || !is_array($configuracao['servidores'][$indiceServidor])) {
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

$mysqlUsuario = (string) $configuracao['mysql_usuario'];
$mysqlSenha = (string) $configuracao['mysql_senha'];

mysqli_report(MYSQLI_REPORT_OFF);

$conexao = @new mysqli($endereco, $mysqlUsuario, $mysqlSenha, '', $porta);
if ($conexao->connect_errno) {
    sairComErro('Erro ao conectar no servidor: ' . $conexao->connect_error);
}
$conexao->set_charset('utf8mb4');

try {
    $linhas = carregarLinhasProblema($conexao, $nomeBanco, $diasConsulta);
} catch (Throwable $e) {
    $erro = $e->getMessage();
    $conexao->close();
    sairComErro($erro);
}

$classeMensagem = 'ok';
$mensagemExecucao = '';
$resultadosExecucao = [];
$linhasResultado = [];
$idtsProcessados = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = isset($_POST['acao']) ? trim((string) $_POST['acao']) : '';
    $acoesPermitidas = ['consultarX', 'validarX', 'acerto_w', 'acerto_v', 'enviarX', 'cancelarX', 'inutilizarX'];

    $idtsSelecionados = isset($_POST['idts']) && is_array($_POST['idts']) ? $_POST['idts'] : [];
    $idtsSelecionados = array_values(array_unique(array_filter(array_map(static function ($valor): string {
        return trim((string) $valor);
    }, $idtsSelecionados), static function (string $valor): bool {
        return $valor !== '';
    })));

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
            $linhasPorIdt = [];
            foreach ($linhas as $linha) {
                $linhasPorIdt[(string) $linha['idt']] = $linha;
            }

            $fila = [];
            foreach ($idtsSelecionados as $idtSelecionado) {
                $idtSelecionado = (string) $idtSelecionado;
                $modeloAtual = isset($linhasPorIdt[$idtSelecionado]['modelo']) ? (string) $linhasPorIdt[$idtSelecionado]['modelo'] : '65';
                if ($modeloAtual === '') {
                    $modeloAtual = '65';
                }

                $fila[] = [
                    'idt' => $idtSelecionado,
                    'modelo' => $modeloAtual
                ];
            }

            $maximoParalelo = 10;
            $ativos = [];
            $indiceFila = 0;

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
                            ? $saidaCombinada . "\n" . $erroFinal
                            : $erroFinal;
                    }

                    error_log('AUTONFE retorno exec: codigo=' . $codigoRetorno . ' saida=' . json_encode($saidaCombinada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    error_log('AUTONFE idt=' . $ativo['idt'] . ' modelo=' . $ativo['modelo'] . ' comando=' . $ativo['comando']);

                    $notaAtualizada = buscarNotaPorIdt($conexao, $nomeBanco, (string) $ativo['idt']);
                    if (count($notaAtualizada) > 0) {
                        $linhasResultado[] = $notaAtualizada;
                    }
                    $idtsProcessados[(string) $ativo['idt']] = true;

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

            foreach (array_keys($idtsProcessados) as $idtProcessado) {
                unset($linhasPorIdt[(string) $idtProcessado]);
            }
            $linhas = array_values($linhasPorIdt);
            $mensagemExecucao = 'Ação ' . $acao . ' executada para ' . count($idtsSelecionados) . ' nota(s).';
            $classeMensagem = 'ok';
        }
    }
}

$conexao->close();

echo '<!doctype html>';
echo '<html lang="pt-BR">';
echo '<head>';
echo '<meta charset="utf-8">';
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>Detalhes das Notas</title>';
echo '<style>';
echo 'html, body { height:100%; margin:0; }';
echo 'body { background:#111; color:#eee; font-family:Arial,Helvetica,sans-serif; overflow:hidden; }';
echo '.pagina { display:flex; flex-direction:column; height:100vh; }';
echo '.topo-fixo { flex:0 0 auto; padding:16px 20px 12px 20px; background:#111; border-bottom:1px solid #2b2b2b; }';
echo '.topo-grid { display:grid; grid-template-columns: 40% 60%; gap:16px; align-items:stretch; }';
echo '.painel-acoes, .info { padding:10px 12px; background:#1a1a1a; border:1px solid #333; border-radius:6px; box-sizing:border-box; }';
echo '.painel-acoes { min-height:160px; display:flex; flex-direction:column; justify-content:flex-start; }';
echo '.painel-acoes .acoes { margin-top:0; display:grid; grid-template-columns: repeat(3, minmax(110px, 1fr)); gap:12px; align-content:start; }';
echo '.painel-acoes .acoes .botao { width:100%; min-width:0; min-height:48px; }';
echo '.painel-acoes .acoes a.botao { display:flex; width:100%; min-width:0; min-height:48px; box-sizing:border-box; }';
echo '.painel-filtro { margin:0 0 12px 0; }';
echo '.painel-filtro label { display:block; margin:0 0 6px 0; font-size:13px; color:#ddd; }';
echo '.painel-filtro input[type=text] { width:100%; box-sizing:border-box; padding:10px 12px; background:#101010; color:#fff; border:1px solid #3a3a3a; border-radius:6px; outline:none; }';
echo '.painel-filtro input[type=text]:focus { border-color:#666; }';
echo '.topo-fixo h2 { margin:0 0 10px 0; }';
echo '.info { padding:10px 12px; background:#1a1a1a; border:1px solid #333; border-radius:6px; }';
echo '.info div { padding:2px 0; }';
echo '.acoes { margin-top:10px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; }';
echo '.botao { display:inline-flex; flex-direction:column; align-items:center; justify-content:center; padding:8px 12px; background:#222; color:#fff; text-decoration:none; border:1px solid #444; border-radius:6px; cursor:pointer; line-height:1.2; min-width:110px; }';
echo '.botao:hover { background:#2c2c2c; }';
echo '.botao small { font-size:12px; font-weight:normal; }';
echo '.botao.oculto { display:none; }';
echo '.mensagem { margin-top:10px; padding:10px 12px; background:#1a1a1a; border:1px solid #333; border-radius:6px; }';
echo '.mensagem.ok { color:#c8f7c5; border-color:#355535; }';
echo '.mensagem.erro { color:#ffb3b3; border-color:#884444; }';
echo '.area-tabela { flex:1 1 auto; min-height:0; padding:12px 20px 20px 20px; overflow:auto; }';
echo 'table { width:100%; border-collapse:collapse; background:#181818; }';
echo 'th, td { border:1px solid #333; padding:10px; text-align:left; }';
echo 'thead th { background:#222; color:#fff; position:sticky; top:0; z-index:2; }';
echo 'tr:nth-child(even) td { background:#151515; }';
echo 'tr:hover td { background:#1d1d1d; }';
echo '.mensagem-vazia { padding:12px; background:#1a1a1a; border:1px solid #333; border-radius:6px; color:#ccc; }';
echo '.col-selecao { width:95px; text-align:center; }';
echo '.col-idt { width:120px; }';
echo '.col-curta { width:90px; }';
echo '.col-status { width:120px; }';
echo 'input[type=checkbox] { transform:scale(1.15); }';
echo '.check-actions { display:flex; gap:8px; justify-content:center; flex-wrap:wrap; margin-top:6px; }';
echo '.check-link { color:#ddd; text-decoration:underline; cursor:pointer; font-size:12px; }';
echo '.check-link:hover { color:#fff; }';
echo '.bloco-execucao { margin-top:12px; padding:10px 12px; background:#161616; border:1px solid #333; border-radius:6px; }';
echo '.bloco-execucao pre { white-space:pre-wrap; word-break:break-word; background:#0f0f0f; padding:10px; border:1px solid #2b2b2b; border-radius:6px; color:#ddd; }';
echo '.resultado-acoes { margin-top:16px; }';
echo '.resultado-acoes h3 { margin:0 0 10px 0; }';
echo '@media (max-width: 900px) { .topo-grid { grid-template-columns: 1fr; } .painel-acoes .acoes { grid-template-columns: repeat(2, minmax(110px, 1fr)); } }';
echo '</style>';
echo '<script>';
echo 'function normalizarTextoFiltro(texto) {';
echo '  texto = (texto || "").toString().toLowerCase();';
echo '  if (texto.normalize) {';
echo '    texto = texto.normalize("NFD").replace(/[\u0300-\u036f]/g, "");';
echo '  }';
echo '  return texto;';
echo '}';
echo 'function obterLinhasVisiveis() {';
echo '  var linhas = document.querySelectorAll("tbody tr[data-descricao]");';
echo '  var visiveis = [];';
echo '  for (var i = 0; i < linhas.length; i++) {';
echo '    if (linhas[i].style.display !== "none") { visiveis.push(linhas[i]); }';
echo '  }';
echo '  return visiveis;';
echo '}';
echo 'function atualizarCheckTopo() {';
echo '  var topo = document.getElementById("checkTopo");';
echo '  if (!topo) { return; }';
echo '  var linhas = obterLinhasVisiveis();';
echo '  if (linhas.length === 0) { topo.checked = false; return; }';
echo '  for (var i = 0; i < linhas.length; i++) {';
echo '    var chk = linhas[i].querySelector(".check-nota");';
echo '    if (chk && !chk.checked) { topo.checked = false; return; }';
echo '  }';
echo '  topo.checked = true;';
echo '}';
echo 'function marcarTodos() {';
echo '  var linhas = obterLinhasVisiveis();';
echo '  for (var i = 0; i < linhas.length; i++) {';
echo '    var chk = linhas[i].querySelector(".check-nota");';
echo '    if (chk) { chk.checked = true; }';
echo '  }';
echo '  atualizarCheckTopo();';
echo '}';
echo 'function desmarcarTodos() {';
echo '  var linhas = obterLinhasVisiveis();';
echo '  for (var i = 0; i < linhas.length; i++) {';
echo '    var chk = linhas[i].querySelector(".check-nota");';
echo '    if (chk) { chk.checked = false; }';
echo '  }';
echo '  atualizarCheckTopo();';
echo '}';
echo 'function marcarTodosCheckbox(origem) {';
echo '  var linhas = obterLinhasVisiveis();';
echo '  for (var i = 0; i < linhas.length; i++) {';
echo '    var chk = linhas[i].querySelector(".check-nota");';
echo '    if (chk) { chk.checked = origem.checked; }';
echo '  }';
echo '  atualizarCheckTopo();';
echo '}';
echo 'function aplicarFiltroDescricao() {';
echo '  var campo = document.getElementById("filtroDescricao");';
echo '  if (!campo) { return; }';
echo '  var filtro = normalizarTextoFiltro(campo.value);';
echo '  var linhas = document.querySelectorAll("tbody tr[data-descricao]");';
echo '  for (var i = 0; i < linhas.length; i++) {';
echo '    var descricao = normalizarTextoFiltro(linhas[i].getAttribute("data-descricao") || "");';
echo '    if (filtro === "" || descricao.indexOf(filtro) !== -1) {';
echo '      linhas[i].style.display = "";';
echo '    } else {';
echo '      linhas[i].style.display = "none";';
echo '    }';
echo '  }';
echo '  atualizarCheckTopo();';
echo '}';
echo 'function validarEnvio(acao) {';
echo '  var selecionados = document.querySelectorAll(".check-nota:checked");';
echo '  if (selecionados.length === 0) {';
echo '    alert("Selecione pelo menos uma nota.");';
echo '    return false;';
echo '  }';
echo '  var campoAcao = document.getElementById("acaoFormulario");';
echo '  if (campoAcao) { campoAcao.value = acao; }';
echo '  return true;';
echo '}';
echo 'document.addEventListener("change", function(e) {';
echo '  if (e.target && e.target.className && (" " + e.target.className + " ").indexOf(" check-nota ") !== -1) {';
echo '    atualizarCheckTopo();';
echo '  }';
echo '});';
echo 'document.addEventListener("DOMContentLoaded", function() {';
echo '  atualizarCheckTopo();';
echo '});';
echo '</script>';
echo '</head>';
echo '<body>';
echo '<div class="pagina">';

echo '<div class="topo-fixo">';
echo '<h2>Notas com problema</h2>';
echo '<div class="topo-grid">';
echo '<div class="painel-acoes">';
if (count($linhas) > 0) {
    echo '<form method="post" action="">';
    echo '<input type="hidden" id="acaoFormulario" name="acao" value="">';
    echo '<input type="hidden" name="s" value="' . $indiceServidor . '">';
    echo '<input type="hidden" name="db" value="' . htmlspecialchars($nomeBanco, ENT_QUOTES, 'UTF-8') . '">';
    echo '<input type="hidden" name="dias" value="' . $diasConsulta . '">';
    echo '<div class="painel-filtro">';
    echo '<label for="filtroDescricao">Filtrar descrição</label>';
    echo '<input id="filtroDescricao" type="text" autocomplete="off" oninput="aplicarFiltroDescricao()" placeholder="Digite para filtrar pela descrição">';
    echo '</div>';
    echo '<div class="acoes">';
    echo '<a class="botao" href="consultar_notas.php"><span>Nova</span><small>consulta</small></a>';
    echo '<button class="botao" type="submit" onclick="return validarEnvio(\'consultarX\');"><span>Consultar</span><small>(consultarX)</small></button>';
    echo '<button class="botao" type="submit" onclick="return validarEnvio(\'enviarX\');"><span>Enviar</span><small>(enviarX)</small></button>';
    echo '<button class="botao" type="submit" onclick="return validarEnvio(\'acerto_w\');"><span>Offline</span><small>(acerto_w)</small></button>';
    echo '<button class="botao" type="submit" onclick="return validarEnvio(\'acerto_v\');"><span>Normal</span><small>(acerto_v)</small></button>';
    echo '<button class="botao" type="submit" onclick="return validarEnvio(\'cancelarX\');"><span>Cancelar</span><small>(cancelarX)</small></button>';
    echo '<button class="botao" type="submit" onclick="return validarEnvio(\'inutilizarX\');"><span>Inutilizar</span><small>(inutilizarX)</small></button>';
    echo '<button class="botao oculto" type="submit" onclick="return validarEnvio(\'validarX\');"><span>Validar NFe</span><small>(validarX)</small></button>';
    echo '</div>';
} else {
    echo '<div class="acoes">';
    echo '<a class="botao" href="consultar_notas.php"><span>Nova</span><small>consulta</small></a>';
    echo '</div>';
}
echo '</div>';
echo '<div class="info">';
echo '<div><b>Servidor:</b> ' . htmlspecialchars($identificacao, ENT_QUOTES, 'UTF-8') . '</div>';
echo '<div><b>Endereço:</b> ' . htmlspecialchars($endereco, ENT_QUOTES, 'UTF-8') . ':' . $porta . '</div>';
echo '<div><b>Database:</b> ' . htmlspecialchars($nomeBanco, ENT_QUOTES, 'UTF-8') . '</div>';
echo '<div><b>Total de notas:</b> ' . count($linhas) . '</div>';
echo '<div><b>Dias da consulta:</b> ' . $diasConsulta . '</div>';
echo '<div><b>MySQL versão:</b> ' . $versaoMysql . '</div>';
echo '<div><b>Plataforma AutoNFe:</b> ' . htmlspecialchars(detectarPlataformaAutonfe(), ENT_QUOTES, 'UTF-8') . '</div>';
echo '</div>';
echo '</div>';
if ($mensagemExecucao !== '') {
    echo '<div class="mensagem ' . $classeMensagem . '">' . htmlspecialchars($mensagemExecucao, ENT_QUOTES, 'UTF-8') . '</div>';
}
echo '</div>';

echo '<div class="area-tabela">';

if (count($linhas) === 0) {
    echo '<div class="acoes" style="margin-bottom:12px;">';
    echo '<a class="botao" href="javascript:history.back()">Voltar</a>';
    echo '<a class="botao" href="consultar_notas.php"><span>Nova</span><small>consulta</small></a>';
    echo '</div>';
    echo '<div class="mensagem-vazia">Nenhuma nota com problema foi encontrada para este database.</div>';
} else {
    echo '<table>';
    echo '<thead>';
    echo '<tr>';
    echo '<th class="col-selecao">';
    echo '<div><input id="checkTopo" type="checkbox" onclick="marcarTodosCheckbox(this)"></div>';
    echo '<div class="check-actions">';
    echo '<span class="check-link" onclick="marcarTodos()">Marcar todas</span>';
    echo '<span class="check-link" onclick="desmarcarTodos()">Desmarcar todas</span>';
    echo '</div>';
    echo '</th>';
    echo '<th class="col-idt">IDT</th>';
    echo '<th class="col-curta">Nota</th>';
    echo '<th class="col-curta">Série</th>';
    echo '<th class="col-curta">Modelo</th>';
    echo '<th>Origem</th>';
    echo '<th class="col-status">Status</th>';
    echo '<th>Descrição</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($linhas as $linha) {
        echo '<tr data-descricao="' . htmlspecialchars((string) $linha['descricao'], ENT_QUOTES, 'UTF-8') . '">';
        echo '<td class="col-selecao"><input class="check-nota" type="checkbox" name="idts[]" value="' . htmlspecialchars((string) $linha['idt'], ENT_QUOTES, 'UTF-8') . '"></td>';
        echo '<td>' . htmlspecialchars((string) $linha['idt'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $linha['nota'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $linha['serie'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $linha['modelo'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $linha['origem'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $linha['status'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) $linha['descricao'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</form>';

    if (count($linhasResultado) > 0) {
        echo '<div class="resultado-acoes">';
        echo '<h3>Resultado das ações</h3>';
        echo '<table>';
        echo '<thead>';
        echo '<tr>';
        echo '<th class="col-idt">IDT</th>';
        echo '<th class="col-curta">Nota</th>';
        echo '<th class="col-curta">Série</th>';
        echo '<th class="col-curta">Modelo</th>';
        echo '<th>Origem</th>';
        echo '<th class="col-status">Status</th>';
        echo '<th>Descrição</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        foreach ($linhasResultado as $linhaResultado) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) $linhaResultado['idt'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $linhaResultado['nota'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $linhaResultado['serie'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $linhaResultado['modelo'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $linhaResultado['origem'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $linhaResultado['status'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td>' . htmlspecialchars((string) $linhaResultado['descricao'], ENT_QUOTES, 'UTF-8') . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
}

echo '</div>';
echo '</div>';
echo '</body>';
echo '</html>';
