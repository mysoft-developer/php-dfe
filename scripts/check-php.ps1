#requires -Version 5.1

param(
    [Parameter(Position = 0)]
    [string[]]$Path
)

$ErrorActionPreference = "Stop"

$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$ConfiguredPhp = "C:\php-8.1.3-Win32-vs16-x64\php.exe"

if (Test-Path $ConfiguredPhp) {
    $PhpExe = $ConfiguredPhp
}
else {
    $PhpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $PhpCommand) {
        Write-Host "[ERRO] PHP não encontrado."
        exit 2
    }
    $PhpExe = $PhpCommand.Source
}

$Files = @()

if ($Path -and $Path.Count -gt 0) {
    foreach ($Item in $Path) {
        $ResolvedItems = Get-ChildItem -Path $Item -File -Filter "*.php" -ErrorAction SilentlyContinue

        if (-not $ResolvedItems -and (Test-Path $Item -PathType Leaf)) {
            $ResolvedItems = @(Get-Item $Item)
        }

        $Files += $ResolvedItems
    }
}
else {
    $Files = Get-ChildItem -Path $ProjectRoot -Recurse -File -Filter "*.php" |
        Where-Object {
            $_.FullName -notmatch "\\\.git\\" -and
            $_.FullName -notmatch "\\vendor\\"
        }
}

$Files = @($Files | Sort-Object FullName -Unique)

if ($Files.Count -eq 0) {
    Write-Host "[INFO] Nenhum arquivo PHP encontrado para validar."
    exit 0
}

Write-Host ""
Write-Host "============================================================"
Write-Host " VALIDACAO DE SINTAXE PHP - php-dfe"
Write-Host "============================================================"
Write-Host ("PHP: {0}" -f $PhpExe)
Write-Host ("Arquivos: {0}" -f $Files.Count)
Write-Host ""

$Errors = 0

foreach ($File in $Files) {
    $Display = $File.FullName
    if ($File.FullName.StartsWith($ProjectRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
        $Display = $File.FullName.Substring($ProjectRoot.Length).TrimStart("\")
    }

    $Output = & $PhpExe -l $File.FullName 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host ("[OK]   {0}" -f $Display)
    }
    else {
        $Errors++
        Write-Host ("[ERRO] {0}" -f $Display)
        $Output | ForEach-Object { Write-Host ("       {0}" -f $_) }
    }
}

Write-Host ""
Write-Host ("Total: {0} | Erros: {1}" -f $Files.Count, $Errors)

if ($Errors -gt 0) {
    exit 1
}

exit 0
