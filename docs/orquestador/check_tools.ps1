<#
.SYNOPSIS
  Comprobación acotada de herramientas en ESTE equipo Windows (CPV-SESSION-CONTINUITY, `6210552318`/`6211767961`).

.DESCRIPTION
  Solo informa nombre, estado, código de salida y versión. No instala nada, no cambia PATH, no lee secretos y no
  usa la red ni la base. Es idempotente y no interactivo. Se invoca con las herramientas que la tarea necesita.

  Estados:
    AVAILABLE    con -Version: el comando nativo salió con código 0 y respondió la versión esperada
    STORE_ALIAS  con -Version: respondió con el mensaje del alias de la Microsoft Store ("Python was not found...").
                 La ruta WindowsApps por sí sola NO alcanza: un Python instalado desde la Store también vive ahí
    BLOCKED      con -Version: salió con código distinto de 0, superó el tiempo límite, o la ejecución fue
                 rechazada por política
    UNKNOWN      con -Version: salió con 0 pero la salida no tiene la forma esperada (no se infiere nada)
    FOUND        sin -Version: está en PATH; no se ejecutó
    NOT_ON_PATH  no está en PATH. NO significa "no instalado en ningún lado"

.EXAMPLE
  .\docs\orquestador\check_tools.ps1 -Name python,gh -Version
#>
param(
    [string[]] $Name = @('git', 'ssh', 'node', 'php', 'docker', 'gh', 'python', 'python3', 'py'),
    [switch] $Version,
    [int] $TimeoutSeconds = 10
)

# Argumento de versión y forma esperada de la respuesta, por herramienta.
$spec = @{
    git     = @('--version', '^git version \d')
    ssh     = @('-V', '^OpenSSH[_\w]*\d')
    node    = @('--version', '^v\d+\.\d+')
    php     = @('--version', '^PHP \d+\.\d+')
    docker  = @('--version', '^Docker version \d')
    gh      = @('--version', '^gh version \d')
    python  = @('--version', '^Python \d+\.\d+')
    python3 = @('--version', '^Python \d+\.\d+')
    py      = @('--version', '^Python \d+\.\d+')
}
$storeStub = 'Python was not found|Microsoft Store|App execution aliases'

foreach ($tool in $Name) {
    $cmd = Get-Command $tool -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $cmd) { "{0}`tNOT_ON_PATH" -f $tool; continue }
    if (-not $Version) { "{0}`tFOUND" -f $tool; continue }

    $arg, $expected = if ($spec.ContainsKey($tool)) { $spec[$tool] } else { @('--version', '\d+\.\d+') }
    # cmd /c junta stdout y stderr sin que PowerShell 5.1 convierta stderr en error, y conserva el código nativo.
    $job = Start-Job -ScriptBlock {
        param($exe, $a)
        $out = cmd /c "`"$exe`" $a 2>&1"
        [pscustomobject]@{ Exit = $LASTEXITCODE; Out = (($out | Select-Object -First 1) -as [string]) }
    } -ArgumentList $cmd.Source, $arg

    if (-not (Wait-Job $job -Timeout $TimeoutSeconds)) {
        Stop-Job $job; Remove-Job $job -Force
        "{0}`tBLOCKED`texit=timeout({1}s)" -f $tool, $TimeoutSeconds
        continue
    }
    $r = Receive-Job $job -ErrorAction SilentlyContinue
    Remove-Job $job -Force
    $line = if ($r -and $r.Out) { $r.Out.Trim() } else { '' }
    $exit = if ($r) { $r.Exit } else { 'n/a' }

    $state = if ($exit -eq 0 -and $line -match $expected) { 'AVAILABLE' }
        elseif ($tool -like 'py*' -and $line -match $storeStub) { 'STORE_ALIAS' }
        elseif ($exit -ne 0) { 'BLOCKED' }
        else { 'UNKNOWN' }
    # Versión solamente: la primera línea, sin rutas locales.
    $shown = if ($state -eq 'AVAILABLE') { ($line -replace '^.*?:\s+(?=OpenSSH)', '') } elseif ($state -eq 'STORE_ALIAS') { 'Store alias message' } else { '' }
    "{0}`t{1}`texit={2}`t{3}" -f $tool, $state, $exit, $shown
}
