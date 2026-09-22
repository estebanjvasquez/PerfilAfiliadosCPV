# Regression Suite — intento de ejecución

**Fecha de intento:** 2026-09-23 (línea base de la auditoría que motiva este intento: 2026-09-22)
**Commit de referencia (Laravel):** `feature/upgrade-filament-v3` @ `46fb0a5`
**Suite:** `perfilafiliados-mcp/scripts/regression-suite.mjs`

## Resultado: BLOCKED — AUTH CREDENTIAL REQUIRED

No es un fallo de motor ni de calidad de búsqueda. La suite requiere dos argumentos CLI
obligatorios:

- `--url <base>`: URL del Worker desplegado (se cuenta con ella:
  `https://perfilafiliados-mcp.sisteg.workers.dev`, confirmada activa hoy con `GET /` → 200).
- `--token <debug_token>`: el secreto `DEBUG_TOKEN` del Worker, que protege específicamente el
  endpoint `POST /debug-search` contra el que pega la suite (no usa `/mcp` ni `/embed`).

`DEBUG_TOKEN` es un secreto de Cloudflare Worker — no vive en ningún archivo trackeado de ningún
repo (se verificó que no hay `.dev.vars` ni referencia en `wrangler.toml`), y no se pudo obtener
un valor legible: un token de API de Cloudflare con scope `Hyperdrive` + `Cloudflare Tunnel`
(usado en esta sesión para otras tareas) no tiene permiso sobre
`accounts/{id}/workers/scripts/{script}/secrets` — intento real devolvió
`403 No access to the specified resource`. Los secretos de Workers, además, no son legibles una
vez seteados ni con el permiso correcto (solo se pueden rotar/sobreescribir, no leer).

## Qué se necesitaría para desbloquear esto

Una de estas dos cosas, a pedir directamente al usuario (Esteban):

1. El valor de `DEBUG_TOKEN` (si lo tiene guardado en algún gestor de secretos personal), para
   correr la suite directamente: `node scripts/regression-suite.mjs --url https://perfilafiliados-mcp.sisteg.workers.dev --token <valor> --save baseline.json`.
2. Un token de API de Cloudflare con permiso `Account → Workers Scripts → Edit` para poder
   rotar `DEBUG_TOKEN` a un valor conocido (esto invalida el valor anterior — solo hacerlo con
   autorización explícita, ya que cualquier otro proceso que dependa del valor viejo del token
   dejaría de funcionar).

## Qué SÍ se verificó como sustituto parcial

- Worker en línea: `GET https://perfilafiliados-mcp.sisteg.workers.dev/` → `200 {"ok":true,"service":"perfilafiliados-mcp"}`.
- El fixture de la suite (`scripts/fixtures/regression-cases.json`) fue inspeccionado por código
  (no ejecutado) — contiene 32 casos reales, incluyendo el grupo `B_regional` con los términos
  citados en auditorías previas (`cabria`/`cabrias`, `mechurrio`, `macolla`, `guaya fina`, etc.).
  Ningún cambio de código de resolución ocurrió en esta sesión, por lo que no hay razón para
  esperar una regresión de comportamiento — pero esto es una inferencia, no una verificación.
- La contraseña de Supabase rotada hoy (ver `docs/task.md`) ya se propagó al Hyperdrive del Worker
  y se confirmó que el mismo Supabase responde correctamente desde otro cliente (el servidor de
  Contabo) con la contraseña nueva — es razonable, pero no está confirmado con una llamada real al
  Worker, que el camino Worker→Hyperdrive→Supabase funcione end-to-end.

## Clasificación

**BLOCKED — AUTH CREDENTIAL REQUIRED.** No se debe interpretar como fallo de búsqueda ni como
señal para modificar ninguna lógica de resolución/taxonomía.

---

## Adenda (2026-09-23) — investigación de dónde vive `DEBUG_TOKEN` antes de pedir un token nuevo

Se investigó, antes de pedir un token de Cloudflare `Workers Scripts:Edit`, si `DEBUG_TOKEN` (o
`MCP_TOKEN`, que el README de `perfilafiliados-mcp` documenta que debería reflejarse en el `.env`
de Laravel como `MCP_TOKEN`/`MCP_EMBED_TOKEN`) ya existía en algún entorno desplegado. Verificado
(solo existencia de clave, nunca valor) en `/opt/perfilafiliados/.env` del servidor de staging:
**ninguna de las 4 variables (`MCP_EMBED_TOKEN`, `MCP_EMBED_URL`, `MCP_TOKEN`, `DEBUG_TOKEN`)
existe ahí.** La integración MCP↔Laravel documentada nunca se configuró en este entorno. Además,
`DEBUG_TOKEN` está diseñado deliberadamente para nunca vivir en un archivo (se tipea a mano en el
navegador, `src/index.ts:35-38`). Conclusión: no es un problema de permisos de Cloudflare — es que
el valor de aplicación no es recuperable desde ningún entorno accesible. Detalle completo en
`audit/phase3_runtime_validation.md`, sección 1. Se mantiene **BLOCKED — APPLICATION AUTH TOKEN
REQUIRED**, ahora con la causa exacta documentada.
