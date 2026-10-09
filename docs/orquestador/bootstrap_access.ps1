<#
    CPV - Cargador de accesos para una sesion de desarrollo.

    Resuelve tres problemas recurrentes del entorno Windows de este proyecto:
      1. PowerShell 5.1 no conserva estado entre llamadas -> hay que fijar PATH en cada comando.
      2. Las credenciales se "pierden" al abrir una sesion nueva.
      3. Nunca se debe imprimir ni persistir el valor de un secreto.

    USO (dot-source, para que las variables queden en la sesion que lo invoca):

        . docs\orquestador\bootstrap_access.ps1            # carga + tabla de estado
        . docs\orquestador\bootstrap_access.ps1 -Quiet     # carga y no imprime nada
        . docs\orquestador\bootstrap_access.ps1 -Verify    # carga + pruebas de conectividad

    Como prefijo de cualquier comando real:

        . C:\Proyectos\GitHub\PerfilAfiliadosCPV\docs\orquestador\bootstrap_access.ps1 -Quiet; git status --short

    Este script NO imprime valores de secretos, solo SET / MISSING.
    Ver docs/orquestador/ACCESS_BOOTSTRAP.md
#>

[CmdletBinding()]
param(
    [switch]$Quiet,
    [switch]$Verify
)

$ErrorActionPreference = 'Continue'

# --- 1. PATH -------------------------------------------------------------------------------------
# git y php no estan en el PATH por defecto de las sesiones del agente.
$cpvPathCandidates = @(
    'C:\Users\esteb\AppData\Local\Programs\Git\cmd',
    'C:\Users\esteb\php82'
)
foreach ($p in $cpvPathCandidates) {
    if ((Test-Path $p) -and ($env:PATH -notlike "*$p*")) {
        $env:PATH = "$p;$env:PATH"
    }
}

# --- 2. Identificadores no secretos del proyecto -------------------------------------------------
if (-not $env:CPV_REPO)        { $env:CPV_REPO        = 'estebanjvasquez/PerfilAfiliadosCPV' }
if (-not $env:CPV_WORKER_REPO) { $env:CPV_WORKER_REPO = 'estebanjvasquez/perfilafiliados-mcp' }
if (-not $env:CPV_BRANCH)      { $env:CPV_BRANCH      = 'feature/upgrade-filament-v3' }
if (-not $env:CPV_ISSUE)       { $env:CPV_ISSUE       = '2' }

# --- 3. Almacen local de credenciales (fuera del repositorio) ------------------------------------
$storePath = $env:CPV_CREDENTIAL_STORE
if (-not $storePath) {
    $storePath = Join-Path $env:USERPROFILE '.cpv\credentials.env'
}

$loaded  = @()
$present = $false

if (Test-Path $storePath) {
    $present = $true
    foreach ($line in (Get-Content -LiteralPath $storePath -Encoding UTF8)) {
        $t = $line.Trim()
        if ($t.Length -eq 0)      { continue }
        if ($t.StartsWith('#'))   { continue }
        $eq = $t.IndexOf('=')
        if ($eq -lt 1)            { continue }

        $name = $t.Substring(0, $eq).Trim()
        $val  = $t.Substring($eq + 1).Trim()

        # Quitar comillas envolventes si las hay.
        if ($val.Length -ge 2) {
            if (($val.StartsWith('"') -and $val.EndsWith('"')) -or
                ($val.StartsWith("'") -and $val.EndsWith("'"))) {
                $val = $val.Substring(1, $val.Length - 2)
            }
        }

        if ($val.Length -gt 0) {
            Set-Item -Path "env:$name" -Value $val
            $loaded += $name
        }
    }
}

# --- 4. Reporte de estado, SIN valores -----------------------------------------------------------
if (-not $Quiet) {
    Write-Host ''
    Write-Host 'CPV - estado de accesos de esta sesion' -ForegroundColor Cyan
    Write-Host ('-' * 62)

    if ($present) {
        Write-Host ("almacen local : {0}  ({1} variables cargadas)" -f $storePath, $loaded.Count)
    } else {
        Write-Host ("almacen local : NO EXISTE  -> {0}" -f $storePath) -ForegroundColor Yellow
        Write-Host '                crear con docs/orquestador/ACCESS_BOOTSTRAP.md seccion 3.1' -ForegroundColor Yellow
    }

    Write-Host ''
    $watched = @(
        'CPV_GITHUB_TOKEN',
        'CLOUDFLARE_API_TOKEN',
        'CLOUDFLARE_ACCOUNT_ID',
        'SUPABASE_ACCESS_TOKEN',
        'DB_PGSQL_HOST',
        'DB_PGSQL_PASSWORD'
    )
    foreach ($name in $watched) {
        $v = [Environment]::GetEnvironmentVariable($name)
        if ($v -and $v.Length -gt 0) {
            Write-Host ("  {0,-24} SET" -f $name) -ForegroundColor Green
        } else {
            Write-Host ("  {0,-24} MISSING" -f $name) -ForegroundColor DarkGray
        }
    }

    Write-Host ''
    Write-Host '  nota: leer Issue #2 NO requiere token (los repos son publicos).' -ForegroundColor DarkGray
    Write-Host '  nota: DEBUG_TOKEN vive solo en Cloudflare y no es recuperable (GATE D).' -ForegroundColor DarkGray

    # .env de Laravel: se comprueba la EXISTENCIA, nunca se lee su contenido.
    $dotenv = Join-Path $PSScriptRoot '..\..\.env'
    if (Test-Path $dotenv) {
        Write-Host '  .env de Laravel          PRESENTE (artisan/DB operativos)' -ForegroundColor Green
    } else {
        Write-Host '  .env de Laravel          AUSENTE  (artisan no podra conectar)' -ForegroundColor Yellow
    }
    Write-Host ''
}

# --- 5. Verificacion opcional de conectividad (no destructiva) -----------------------------------
if ($Verify) {

    function Get-CpvHttpStatus {
        param([string]$Uri)
        try {
            $r = Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 20 -Method Get
            return [int]$r.StatusCode
        } catch {
            if ($_.Exception.Response) {
                return [int]$_.Exception.Response.StatusCode.value__
            }
            return 0
        }
    }

    Write-Host 'Verificacion de conectividad (solo lectura)' -ForegroundColor Cyan
    Write-Host ('-' * 62)

    $repoApi = "https://api.github.com/repos/$($env:CPV_REPO)/branches/$($env:CPV_BRANCH)"
    $s = Get-CpvHttpStatus -Uri $repoApi
    if ($s -eq 200) {
        Write-Host '  GitHub API (anonima)     PASS' -ForegroundColor Green
    } else {
        Write-Host ("  GitHub API (anonima)     FAIL (HTTP {0})" -f $s) -ForegroundColor Red
    }

    $s = Get-CpvHttpStatus -Uri 'https://pruebas.camarapetrolera.app/'
    if ($s -ge 200 -and $s -lt 400) {
        Write-Host ("  staging app              PASS (HTTP {0})" -f $s) -ForegroundColor Green
    } else {
        Write-Host ("  staging app              FAIL (HTTP {0})" -f $s) -ForegroundColor Red
    }

    $s = Get-CpvHttpStatus -Uri 'https://perfilafiliados-mcp.sisteg.workers.dev/'
    if ($s -gt 0) {
        Write-Host ("  Worker                   ALCANZABLE (HTTP {0})" -f $s) -ForegroundColor Green
    } else {
        Write-Host '  Worker                   SIN RESPUESTA' -ForegroundColor Red
    }

    if (Get-Command git -ErrorAction SilentlyContinue) {
        Push-Location $PSScriptRoot
        try {
            $sha   = (& git rev-parse HEAD)
            $dirty = (& git status --short)
            Write-Host ("  git HEAD                 {0}" -f $sha)
            if ($dirty) {
                Write-Host ("  working tree             {0} archivo(s) sin commitear" -f @($dirty).Count) -ForegroundColor Yellow
            } else {
                Write-Host '  working tree             LIMPIO' -ForegroundColor Green
            }
        } finally {
            Pop-Location
        }
    } else {
        Write-Host '  git                      NO DISPONIBLE EN PATH' -ForegroundColor Red
    }
    Write-Host ''
}
