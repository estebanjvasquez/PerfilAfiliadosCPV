param([string]$Variant, [string]$Inputs, [int]$Reps = 3)
# TASK-0031: ciclo completo de una variante en una copia aislada: crear -> activar -> correr -> desactivar -> recoger -> borrar.
$ErrorActionPreference = 'Stop'
. C:\Proyectos\GitHub\PerfilAfiliadosCPV\docs\orquestador\bootstrap_access.ps1 -Quiet
$S = "C:\Users\esteb\AppData\Local\Temp\claude\53ba9420edd75997\7f185c39-162c-4ed1-851b-0c5e782cd157\scratchpad\t31"
$R = "C:\Proyectos\GitHub\PerfilAfiliadosCPV\docs\cira\task0031\bench"
$h = @{'X-N8N-API-KEY' = $env:N8N_API_KEY}; $base = $env:N8N_BASE_URL.TrimEnd('/')
$live = Invoke-RestMethod -Uri "$base/api/v1/workflows/zbVLoCdR09IA9yQK" -Headers $h
if ($live.activeVersionId -ne '7feb48bc-218d-4c4e-af19-8a471b7512bc') { throw "flujo vivo cambió: $($live.activeVersionId)" }
node "$R\build_variant.mjs" "$HOME\.cpv\n8n_backups\zbVLoCdR09IA9yQK_2026-11-16_t31_baseline.json" $Variant "$S\wf_$Variant.json"
$wf = Invoke-RestMethod -Method Post -Uri "$base/api/v1/workflows" -Headers $h -ContentType 'application/json; charset=utf-8' -Body ([IO.File]::ReadAllBytes("$S\wf_$Variant.json"))
$id = $wf.id
try {
  Invoke-RestMethod -Method Post -Uri "$base/api/v1/workflows/$id/activate" -Headers $h -ContentType 'application/json' -Body '{}' | Out-Null
  $path = Get-Content "$S\wf_$Variant.json.webhook_path.txt"
  node "$R\run_variant.mjs" $Inputs $path $Variant $Reps "$S\run_$Variant.json" 2>> "$S\run_$Variant.log"
} finally {
  Invoke-RestMethod -Method Post -Uri "$base/api/v1/workflows/$id/deactivate" -Headers $h -ContentType 'application/json' -Body '{}' | Out-Null
}
node "$R\collect.mjs" $id "$S\run_$Variant.json" "$S\col_$Variant.json"
$d = Invoke-RestMethod -Method Delete -Uri "$base/api/v1/workflows/$id" -Headers $h
"$Variant copia=$id borrada=$($d.id)"
