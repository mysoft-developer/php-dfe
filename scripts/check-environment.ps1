#requires -Version 5.1

$ErrorActionPreference = "Stop"

$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$ExpectedProjectRoot = "C:\httpd-2.4.46-win64-VS16\htdocs\php-dfe"
$EntryPoint = Join-Path $ProjectRoot "consultar_nota_servidores.php"
$PhpExe = "C:\php-8.1.3-Win32-vs16-x64\php.exe"
$ApacheExe = "C:\httpd-2.4.46-win64-VS16\bin\httpd.exe"

$Failed = $false

function Write-Check {
    param(
        [string]$Name,
        [bool]$Ok,
        [string]$Detail
    )

    if ($Ok) {
        Write-Host ("[OK]   {0}: {1}" -f $Name, $Detail)
    }
    else {
        Write-Host ("[ERRO] {0}: {1}" -f $Name, $Detail)
        $script:Failed = $true
    }
}

Write-Host ""
Write-Host "============================================================"
Write-Host " AMBIENTE OPENCODE - php-dfe"
Write-Host "============================================================"
Write-Host ""

Write-Check "Raiz do projeto" (Test-Path $ProjectRoot) $ProjectRoot

if ($ProjectRoot -ieq $ExpectedProjectRoot) {
    Write-Check "Caminho esperado" $true $ExpectedProjectRoot
}
else {
    Write-Host ("[INFO] Caminho atual difere do caminho esperado: {0}" -f $ExpectedProjectRoot)
}

Write-Check "Entrada principal" (Test-Path $EntryPoint) $EntryPoint
Write-Check "PHP configurado" (Test-Path $PhpExe) $PhpExe
Write-Check "Apache configurado" (Test-Path $ApacheExe) $ApacheExe

if (Test-Path $PhpExe) {
    try {
        $PhpVersion = & $PhpExe -r "echo PHP_VERSION;"
        Write-Host ("[INFO] PHP: {0}" -f $PhpVersion)
    }
    catch {
        Write-Host ("[ERRO] Não foi possível executar o PHP: {0}" -f $_.Exception.Message)
        $Failed = $true
    }
}

$Git = Get-Command git -ErrorAction SilentlyContinue
if ($null -ne $Git) {
    Write-Host ("[OK]   Git: {0}" -f $Git.Source)

    Push-Location $ProjectRoot
    try {
        & git rev-parse --is-inside-work-tree 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            Write-Host "[OK]   Repositório Git detectado."
            & git status --short
        }
        else {
            Write-Host "[INFO] A pasta não parece estar dentro de um repositório Git."
        }
    }
    finally {
        Pop-Location
    }
}
else {
    Write-Host "[INFO] Git não encontrado no PATH."
}

Write-Host ""

if ($Failed) {
    Write-Host "Resultado: ambiente com problema(s)."
    exit 1
}

Write-Host "Resultado: verificações principais concluídas."
exit 0
