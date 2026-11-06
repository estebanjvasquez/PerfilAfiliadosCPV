# Genera un DEBUG_TOKEN nuevo para el Worker perfilafiliados-mcp (/debug-search), lo carga en Cloudflare,
# lo guarda en el almacén local como CPV_DEBUG_TOKEN y lo deja en el portapapeles para copiarlo al
# gestor de contraseñas. No imprime el token.
#
# Por qué rotar y no "recuperar": Cloudflare no deja leer un secret ya cargado. El token anterior solo
# existía en la máquina anterior. Nada más lo usa (ni Laravel ni n8n; verificado el 2026-11-06), así que
# cambiarlo no rompe ningún servicio: solo afecta a quien llame /debug-search a mano o por script.
#
# Uso:  powershell -ExecutionPolicy Bypass -File docs\orquestador\rotate_debug_token.ps1
$ErrorActionPreference = 'Stop'
$store  = Join-Path $env:USERPROFILE '.cpv\credentials.env'
$worker = 'C:\Proyectos\GitHub\perfilafiliados-mcp'
$url    = 'https://perfilafiliados-mcp.sisteg.workers.dev/debug-search'

if (-not (Test-Path $worker)) { throw "No existe el repo del Worker en $worker" }

Push-Location $worker
try {
    # 1. Sesión de Cloudflare
    & npx wrangler whoami 2>&1 | Out-Host
    $r = Read-Host '1) Si arriba dice que NO hay sesión, escriba L para iniciarla (Enter para seguir)'
    if ($r -match '^[Ll]') { & npx wrangler login; if ($LASTEXITCODE -ne 0) { throw 'wrangler login falló' } }

    # 2. Token nuevo: 32 bytes aleatorios en base64url
    $b = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
    $tok = [Convert]::ToBase64String($b).TrimEnd('=').Replace('+', '-').Replace('/', '_')

    # 3. Cargarlo en el Worker. Se usa 'secret bulk' con un archivo temporal porque el pipe agrega
    #    un salto de línea al valor.
    $tmp = Join-Path $env:TEMP ('dbg_' + [guid]::NewGuid().ToString('N') + '.json')
    try {
        [IO.File]::WriteAllText($tmp, (@{ DEBUG_TOKEN = $tok } | ConvertTo-Json -Compress), (New-Object Text.UTF8Encoding $false))
        & npx wrangler secret bulk $tmp 2>&1 | Out-Host
        if ($LASTEXITCODE -ne 0) { throw 'wrangler secret bulk falló: el Worker NO cambió' }
    } finally { Remove-Item $tmp -Force -ErrorAction SilentlyContinue }
} finally { Pop-Location }

# 4. Almacén local: reemplaza CPV_DEBUG_TOKEN si ya existía
$dir = Split-Path $store
if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }
$lines = @(); if (Test-Path $store) { $lines = @(Get-Content $store -Encoding UTF8 | Where-Object { $_ -notmatch '^\s*CPV_DEBUG_TOKEN\s*=' }) }
$lines += "CPV_DEBUG_TOKEN=$tok"
[IO.File]::WriteAllLines($store, $lines, (New-Object Text.UTF8Encoding $false))
Write-Host "`n2) Guardado en el almacén local como CPV_DEBUG_TOKEN." -ForegroundColor Green

# 5. Copia de resguardo en el gestor de contraseñas (para que no se pierda con la máquina)
Set-Clipboard -Value $tok
Write-Host "`n3) El token está en el portapapeles. Créelo en su gestor de contraseñas:" -ForegroundColor Yellow
Write-Host '   nombre : CPV - perfilafiliados-mcp DEBUG_TOKEN'
Write-Host '   usuario: CPV_DEBUG_TOKEN'
Write-Host '   nota   : Worker perfilafiliados-mcp, endpoint /debug-search. Guardar también aquí MCP_TOKEN y N8N_API_KEY.'
Read-Host 'Guarde la entrada y pulse Enter (se borrará el portapapeles)'
Set-Clipboard -Value ' '

# 6. Verificación: /debug-search tiene que aceptar el token nuevo (puede tardar unos segundos en propagarse)
$h = @{ Authorization = "Bearer $tok" }
$code = $null
foreach ($i in 1..5) {
    try {
        $code = (Invoke-WebRequest -UseBasicParsing -Method Post -Uri $url -Headers $h -ContentType 'application/json' -Body '{"query":"cabria"}' -TimeoutSec 60).StatusCode
    } catch { $code = $_.Exception.Response.StatusCode.value__ }
    if ($code -eq 200) { break }
    Start-Sleep -Seconds 5
}
Remove-Variable tok, b
if ($code -eq 200) { Write-Host "`n4) /debug-search acepta el token nuevo (HTTP 200)." -ForegroundColor Green }
else { Write-Host "`n4) /debug-search respondió HTTP $code. Avise antes de reintentar." -ForegroundColor Red }
