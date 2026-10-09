<#
    CPV - Publica un comentario en Issue #2 desde un archivo Markdown.

    Existe para que el loop orquestador<->agente no necesite al propietario como mensajero en cuanto
    haya un token disponible. Mientras no lo haya, el fallback valido sigue siendo el de
    AUTONOMOUS_DEV_LOOP.md seccion 11 (push + handoff, lectura anonima de vuelta).

    USO:
        .\docs\orquestador\post_issue_comment.ps1 -BodyFile .\mi_comentario.md
        .\docs\orquestador\post_issue_comment.ps1 -BodyFile .\x.md -Issue 2 -WhatIf

    EL TOKEN:
      Se toma de $env:CPV_GITHUB_TOKEN (cargado por bootstrap_access.ps1 desde el almacen local,
      %USERPROFILE%\.cpv\credentials.env). Scope minimo: public_repo.
      NUNCA se pasa por linea de comandos, NUNCA se imprime, NUNCA se escribe a disco.

    SALIDA: solo el id y la URL del comentario creado.

    Ver docs/orquestador/ACCESS_BOOTSTRAP.md seccion 5.2
#>

[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [Parameter(Mandatory = $true)]
    [string]$BodyFile,

    [string]$Repo,
    [int]$Issue
)

$ErrorActionPreference = 'Stop'

# Carga PATH + almacen local sin imprimir valores.
. (Join-Path $PSScriptRoot 'bootstrap_access.ps1') -Quiet

if (-not $Repo)  { $Repo  = $env:CPV_REPO }
if (-not $Issue) { if ($env:CPV_ISSUE) { $Issue = [int]$env:CPV_ISSUE } else { $Issue = 2 } }

if (-not (Test-Path -LiteralPath $BodyFile)) {
    throw "No existe el archivo de cuerpo: $BodyFile"
}

$token = $env:CPV_GITHUB_TOKEN
if ((-not $token) -and (-not $WhatIfPreference)) {
    Write-Host 'CPV_GITHUB_TOKEN no esta disponible.' -ForegroundColor Red
    Write-Host 'Anadirlo a %USERPROFILE%\.cpv\credentials.env (ver ACCESS_BOOTSTRAP.md seccion 3).' -ForegroundColor Yellow
    Write-Host 'Mientras no exista, usar el fallback de AUTONOMOUS_DEV_LOOP.md seccion 11.' -ForegroundColor Yellow
    throw 'BLOCKED_NO_GITHUB_WRITE_CREDENTIAL'
}

# Lectura deterministica: .NET devuelve un String limpio y decodifica UTF-8 explicitamente.
# NO usar Get-Content -Raw aqui: decora su salida con propiedades PS* (PSPath, PSProvider...), y
# ConvertTo-Json las expande, enviando {"body":{"value":...,"Length":...}} en lugar de una cadena.
# GitHub lo rechaza con 422 "Invalid request. For 'properties/body'". Encontrado en vivo 2026-10-09.
$body = [System.IO.File]::ReadAllText($BodyFile, [System.Text.Encoding]::UTF8)

# Barrido defensivo: este repositorio es PUBLICO, un comentario tambien lo es.
$secretPatterns = @(
    'ghp_[A-Za-z0-9]{20}',
    'github_pat_[A-Za-z0-9_]{20}',
    'cfut_[A-Za-z0-9]{20}',
    'BEGIN [A-Z ]*PRIVATE KEY',
    'DB_PGSQL_PASSWORD\s*=\s*\S',
    'DEBUG_TOKEN\s*=\s*\S',
    'APP_KEY\s*=\s*base64',
    'postgres(ql)?://[^\s]*:[^\s]*@'
)
foreach ($pat in $secretPatterns) {
    if ($body -match $pat) {
        throw "ABORTADO: el cuerpo coincide con un patron de secreto ($pat). El Issue es PUBLICO."
    }
}

$uri = "https://api.github.com/repos/$Repo/issues/$Issue/comments"

if (-not $PSCmdlet.ShouldProcess($uri, "Publicar comentario de $($body.Length) caracteres")) {
    Write-Host "DRY RUN - no se publico nada."
    Write-Host ("  destino : {0}" -f $uri)
    Write-Host ("  tamano  : {0} caracteres" -f $body.Length)
    return
}

$payload = @{ body = [string]$body } | ConvertTo-Json -Compress -Depth 3

# Guarda contra la regresion descrita arriba: si el cuerpo no se serializo como cadena plana,
# abortar antes de enviar en lugar de dejar que GitHub devuelva un 422 opaco.
if ($payload -notmatch '^\{"body":"') {
    throw "ABORTADO: el cuerpo no se serializo como cadena JSON plana. Payload: $($payload.Substring(0, [Math]::Min(120, $payload.Length)))"
}

$bytes = [System.Text.Encoding]::UTF8.GetBytes($payload)

$result = Invoke-RestMethod -Method Post -Uri $uri `
    -Headers @{
        Authorization  = "Bearer $token"
        'User-Agent'   = 'cpv-dev-agent'
        Accept         = 'application/vnd.github+json'
    } `
    -ContentType 'application/json; charset=utf-8' `
    -Body $bytes

Write-Host ''
Write-Host 'COMENTARIO PUBLICADO' -ForegroundColor Green
Write-Host ("  id  : {0}" -f $result.id)
Write-Host ("  url : {0}" -f $result.html_url)
Write-Host ''
Write-Host 'Registrar este id en audit/orchestrator_handoff.json si corresponde al loop de revision.' -ForegroundColor DarkGray
