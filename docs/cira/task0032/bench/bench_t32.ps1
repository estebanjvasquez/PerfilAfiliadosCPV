param([ValidateSet('V0','V1b')][string]$Variant, [string]$Scratch, [int]$Reps = 3, [string]$Inputs = 'C:\Proyectos\GitHub\PerfilAfiliadosCPV\docs\cira\task0032\resultados\entradas.json', [string]$Tag = '')
# TASK-0032 fase A: ciclo de una variante en una COPIA AISLADA del flujo vivo (crear -> activar -> correr -> desactivar
# -> recoger -> borrar). Reutiliza run_variant.mjs y collect.mjs de TASK-0031. La copia no registra (Log Search y
# CIRA_log desactivados) y tiene su propio webhook. El flujo vivo no se toca: se verifica su versión antes de empezar.
$ErrorActionPreference = 'Stop'
. C:\Proyectos\GitHub\PerfilAfiliadosCPV\docs\orquestador\bootstrap_access.ps1 -Quiet
$R = 'C:\Proyectos\GitHub\PerfilAfiliadosCPV\docs\cira'
$h = @{'X-N8N-API-KEY' = $env:N8N_API_KEY}; $base = $env:N8N_BASE_URL.TrimEnd('/')
$backup = "$HOME\.cpv\n8n_backups\zbVLoCdR09IA9yQK_2026-11-16_pre-t32_live.json"
$live = Invoke-RestMethod -Uri "$base/api/v1/workflows/zbVLoCdR09IA9yQK" -Headers $h
if ($live.activeVersionId -ne '7feb48bc-218d-4c4e-af19-8a471b7512bc') { throw "flujo vivo cambió: $($live.activeVersionId)" }
$wfFile = Join-Path $Scratch "wf_$Variant.json"
if ($Variant -eq 'V0') { node "$R\task0032\n8n\build_v1b.mjs" $backup $wfFile --copia-prueba V0 } else { node "$R\task0032\n8n\build_v1b.mjs" $backup $wfFile --copia-prueba }
if ($LASTEXITCODE) { throw 'build falló' }
$wf = Invoke-RestMethod -Method Post -Uri "$base/api/v1/workflows" -Headers $h -ContentType 'application/json; charset=utf-8' -Body ([IO.File]::ReadAllBytes($wfFile))
$id = $wf.id
"copia $Variant = $id"
try {
  Invoke-RestMethod -Method Post -Uri "$base/api/v1/workflows/$id/activate" -Headers $h -ContentType 'application/json' -Body '{}' | Out-Null
  $path = Get-Content "$wfFile.webhook_path.txt"
  node "$R\task0031\bench\run_variant.mjs" $Inputs $path "t32$Variant$Tag" $Reps (Join-Path $Scratch "run_$Variant$Tag.json") 2>> (Join-Path $Scratch "run_$Variant$Tag.log")
} finally {
  Invoke-RestMethod -Method Post -Uri "$base/api/v1/workflows/$id/deactivate" -Headers $h -ContentType 'application/json' -Body '{}' | Out-Null
}
node "$R\task0031\bench\collect.mjs" $id (Join-Path $Scratch "run_$Variant$Tag.json") (Join-Path $Scratch "col_$Variant$Tag.json")
$d = Invoke-RestMethod -Method Delete -Uri "$base/api/v1/workflows/$id" -Headers $h
"$Variant copia=$id borrada=$($d.id)"
$after = Invoke-RestMethod -Uri "$base/api/v1/workflows/zbVLoCdR09IA9yQK" -Headers $h
"vivo después: $($after.activeVersionId)"
