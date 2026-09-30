# Tarea activa

**TASK-0004** — Phase C2: Reviewed Immutable Payload Application (Issue #2 comentario `5886148283`),
**ronda 6: despliegue controlado a staging + validación** (comentarios `5890113782` + `5890195271` +
`5892711739` + `5909267134` + `5913324183` + `5913574545`)

Archivo: [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md) (incluye
el texto verbatim de los seis comentarios del re-audit/despliegue)

**Estado:** READY_FOR_REVIEW

Ver `PROTOCOL.md` antes de tocar esta tarea. Precondición verificada: Phase C1 (TASK-0001 +
TASK-0003) sigue `APPROVED` (comentario `5886125405`, HEAD revisado `ce11d36`) — no invalidada por
esta ronda. TASK-0004/Phase C2 sigue `CLOSED/APPROVED` (comentario `5913324183`) — esta ronda no
tocó ningún código.

## Qué pidió el re-audit (ronda 6, comentario `5913574545`) y qué se hizo

Autorización explícita para despliegue CONTROLADO a STAGING (Contabo, `pruebas.camarapetrolera.app`)
+ validación no destructiva, con pre-checks/invariantes/límites obligatorios detallados. Docker local
dejó de ser requisito. **Ningún cambio de código de la aplicación se hizo esta ronda** - es
despliegue + validación puros.

Detalle completo (checkpoint pre-despliegue, despliegue, validación 1-9, incidente y corrección,
análisis de los 3 fallos): `audit/phase5_staging_deployment_2026-09-30.md`.

### Resumen

- **Despliegue:** ya había ocurrido automáticamente vía el workflow existente de GitHub Actions
  (dispara en cada push a `feature/upgrade-filament-v3`) - verificado que el run para HEAD `4f1b02e`
  completó con éxito antes de iniciar cualquier validación.
- **Checkpoint pre-despliegue:** todos los invariantes de DB coincidieron exactamente (10/2/142/79/
  9749/0), sin migraciones pendientes, target de DB confirmado como la misma instancia compartida de
  Supabase, `ext-intl` confirmado cargado en el servidor.
- **Incidente real (causado y corregido en esta ronda):** un contenedor efímero de pruebas
  (necesario para tener PHPUnit disponible, ausente en la imagen `--no-dev` de staging) sobrescribió,
  vía un bind mount compartido (`bootstrap/cache/`), la caché de auto-discovery de paquetes del
  contenedor REAL que sirve tráfico - staging quedó caído (HTTP 500) unos minutos. Diagnosticado y
  corregido de inmediato (regenerar la caché desde el propio `vendor/` del contenedor real) - **el
  usuario fue informado de forma transparente e inmediata y autorizó explícitamente la corrección
  antes de que se ejecutara** (el clasificador de auto-modo de esta sesión bloqueó la escritura
  remota pidiendo esa confirmación). Staging restaurado y verificado en HTTP 200. Ningún código,
  dato, ni fila real fue tocado por el incidente ni su corrección.
- **Suite completa de taxonomía en el servidor real (`ext-intl`, HEAD `4f1b02e`): 188/191 PASS (605
  assertions), 275.45s.** El test bloqueado en TODAS las rondas anteriores por el gap de `ext-intl`
  ahora pasa limpio. `ReviewedProposalServiceTest` (evidencia C2 directa): **41/41 PASS**, sin
  ninguna excepción.
- **Los 3 fallos restantes** tienen causa raíz precisa e identificada (precedencia de `APP_ENV` entre
  la variable de entorno real del contenedor y el `<env>` no forzado de `phpunit.xml`) - ninguno es
  una regresión de C2/taxonomía, los 3 están en archivos de TASK-0003 no tocados por ninguna ronda de
  TASK-0004. Un segundo intento de corrida limpia (autorizado por el usuario) tropezó con un problema
  distinto y las siguientes acciones de escritura remota fueron bloqueadas repetidamente por el
  clasificador - se aceptó la evidencia real ya obtenida en vez de seguir pidiendo autorización
  repetida por un número perfecto.
- **Invariantes de DB después de toda la suite: sin cambios** (10/2/142/79/9749/0) - los 10
  candidatos/2 relaciones reales permanecieron intactos durante todo el despliegue y validación.
- **Búsqueda:** re-verificado sobre el código REALMENTE DESPLEGADO que `BuildEmpresaSearchDocuments`
  no lee ninguna tabla de candidatos/propuestas. Worker verificado accesible (health check público,
  sin tokens). No se re-corrió la regresión de 32 queries (el comentario lo exime explícitamente si
  no hay cambio de código/búsqueda/datos publicados - no lo hay).

## Evidencia

- **[B] Suite completa de taxonomía (servidor real, Contabo, `ext-intl`): 188/191 PASS (605
  assertions), 275.45s** - ver análisis completo de los 3 fallos (causa raíz no-C2) en
  `audit/phase5_staging_deployment_2026-09-30.md`.
- **[B] `ReviewedProposalServiceTest` (servidor real): 41/41 PASS** - limpio.
- **[A] DB invariants antes y después: 10/2/142/79/9749/0** - sin cambios, verificado por SSH de
  solo lectura antes y después de toda la suite.
- **[A] Regresión de 32 queries:** heredada, no invalidada (sin cambio de búsqueda/ranking/datos
  publicados en esta ronda).
- **[A] Phase C1, TASK-0002, TASK-0004 (implementación C2):** `APPROVED`/`CLOSED`, no tocadas.
- **[D] Migraciones de esquema:** ninguna nueva - todas ya habían corrido.

## Fuera de alcance de esta ronda (documentado, no oculto)

- Producción, merge a `main`, rotación de credenciales, operaciones masivas de taxonomía,
  publicación, o cualquier freeze/apply/review/reject de los 10 candidatos/2 relaciones reales -
  explícitamente NO autorizado por el comentario, y no se hizo.
- Verificación completa Worker→Hyperdrive→Supabase con `/mcp`/`/debug-search` (requeriría
  `MCP_TOKEN`/`DEBUG_TOKEN`, cuya rotación no está autorizada por separado) - solo se verificó el
  health check público del Worker.
- Un segundo intento de corrida de suite "perfectamente limpia" - autorizado parcialmente por el
  usuario, pero se aceptó la evidencia ya obtenida en vez de seguir pidiendo autorización repetida
  para acciones de escritura remota adicionales sobre el servidor compartido.

**STOP.** No se llamó `freeze()`/`apply()` contra ningún candidato/relación real, no se tocaron los
10 candidatos/2 relaciones de TASK-0001, no se mergeó a `main`, no se desplegó a producción, no se
rotó ningún secreto. La decisión de autorizar el flujo real de revisión humana o el siguiente paso de
producción queda en manos del orquestador/usuario.

## Tareas anteriores (histórico, no activas)

| Tarea | Estado | Archivo |
|---|---|---|
| TASK-0001 | APPROVED | (Fase C1, sin archivo de tarea propio - ver `audit/phase3_phase_c_apply.md`) |
| TASK-0002 | APPROVED | [`tasks/0002-review-503.md`](tasks/0002-review-503.md) |
| TASK-0003 | APPROVED | [`tasks/0003-phase-c-corrections.md`](tasks/0003-phase-c-corrections.md) |
| TASK-0004 (ronda 1) | CORRECTIONS_REQUIRED | [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md) |
| TASK-0004 (ronda 2, correcciones) | CORRECTIONS_REQUIRED (narrow, ronda 3) | mismo archivo, sección "Re-audit" |
| TASK-0004 (ronda 3, correcciones A/B/C) | CORRECTIONS_REQUIRED (final semantic defects, ronda 4) | mismo archivo, sección "Re-audit — comentario `5892711739`" |
| TASK-0004 (ronda 4, defectos 1/2) | IMPLEMENTATION PASS / ENVIRONMENT_GATE_PENDING (ronda 5) | mismo archivo, sección "Re-audit — comentario `5909267134`" |
| TASK-0004 (ronda 5, evidencia de entorno) | DEPLOYMENT AUTHORIZED (ronda 6) | mismo archivo, sección "Re-audit — comentario `5913324183`" |
| TASK-0004 (ronda 6, despliegue a staging + validación) | READY_FOR_REVIEW | mismo archivo, sección "Autorización de despliegue — comentario `5913574545`"; detalle completo en `audit/phase5_staging_deployment_2026-09-30.md` |
