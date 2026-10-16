<#
.SYNOPSIS
  Comprobación acotada de herramientas en ESTE equipo Windows (CPV-SESSION-CONTINUITY, `6210552318`).

.DESCRIPTION
  Solo informa nombre y estado; no instala nada, no cambia PATH, no lee secretos, no usa la red ni la base.
  Es idempotente y no interactivo. Se invoca con las herramientas que la tarea necesita, no en cada arranque.

  Estados:
    AVAILABLE    está en PATH y (con -Version) respondió una versión
    STORE_ALIAS  es el alias de la Microsoft Store en WindowsApps: no es un intérprete utilizable
    NOT_ON_PATH  no está en PATH. NO significa "no instalado en ningún lado"
    BLOCKED      está en PATH pero la invocación falló o superó el tiempo límite
    FOUND        está en PATH; no se pidió -Version

.EXAMPLE
  .\docs\orquestador\check_tools.ps1 -Name python,gh -Version
#>
param(
    [string[]] $Name = @('git', 'ssh', 'node', 'php', 'docker', 'gh', 'python', 'python3', 'py'),
    [switch] $Version,
    [int] $TimeoutSeconds = 10
)

$versionArgs = @{ ssh = '-V' }

foreach ($tool in $Name) {
    $cmd = Get-Command $tool -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $cmd) {
        "{0}`tNOT_ON_PATH" -f $tool
        continue
    }
    if ($cmd.Source -like '*\Microsoft\WindowsApps\*' -and $tool -like 'python*') {
        "{0}`tSTORE_ALIAS" -f $tool
        continue
    }
    if (-not $Version) {
        "{0}`tFOUND" -f $tool
        continue
    }
    $arg = if ($versionArgs.ContainsKey($tool)) { $versionArgs[$tool] } else { '--version' }
    $job = Start-Job -ScriptBlock { param($exe, $a) & $exe $a 2>&1 | Select-Object -First 1 | Out-String } -ArgumentList $cmd.Source, $arg
    if (Wait-Job $job -Timeout $TimeoutSeconds) {
        $out = (Receive-Job $job -ErrorAction SilentlyContinue | Out-String).Trim()
        $state = if ($job.State -eq 'Completed' -and $out -match '\d+\.\d+') { 'AVAILABLE' } else { 'BLOCKED' }
        "{0}`t{1}`t{2}" -f $tool, $state, ($out -split "`n")[0].Trim()
    } else {
        Stop-Job $job
        "{0}`tBLOCKED`ttimeout {1}s" -f $tool, $TimeoutSeconds
    }
    Remove-Job $job -Force
}
