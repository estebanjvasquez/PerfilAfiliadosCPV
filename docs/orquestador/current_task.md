# Tarea activa

**TASK-0004** — Phase C2: Reviewed Immutable Payload Application (Issue #2 comentario `5886148283`)

Archivo: [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md)

**Estado:** READY_FOR_REVIEW

Ver `PROTOCOL.md` antes de tocar esta tarea. Precondición verificada: Phase C1 (TASK-0001 +
TASK-0003) está `APPROVED` (comentario `5886125405`, HEAD revisado `ce11d36`).

## Qué se implementó

Contrato `REVIEWED_PROPOSAL -> payload inmutable con fingerprint -> APPLY(payload) -> VALIDATE
server-side -> COMMIT/ROLLBACK -> AUDIT`, en `app/Services/Taxonomy/ReviewedProposalService.php`
(`freeze()` + `apply()`) sobre una tabla nueva (`taxonomy_reviewed_proposals`). Detalle completo del
state machine, motivos de aborto, idempotencia/concurrencia, y auditoría de la invariante de
no-filtración a búsqueda: `audit/phase4_c2_immutable_apply.md`.

**No modifica ningún código de Phase C1/B3 existente** - es aditivo.

## Evidencia

- **`tests/Unit/Taxonomy/ReviewedProposalServiceTest.php`: 25/25 PASS (68 assertions).**
- **Suite de taxonomía completa: 174/174 PASS** (562 assertions) **+ 1 fallo ajeno.** El único
  fallo (`TaxonomyCandidateConceptLinkReviewTest::viewing_a_propose_new_concept_candidate...`, una
  página de TASK-0002 ya aprobada, sin relación alguna con TASK-0004) es un artefacto del entorno de
  esta sesión: la instalación local ad-hoc de PHP 8.2 (ver nota de entorno abajo) no pudo cargar la
  extensión `intl` porque una política de Application Control de Windows bloqueó específicamente
  `php_intl.dll` (`Get-MpPreference`/unblock no la destraban sin privilegios de administrador, que
  esta sesión no tiene). El error es exactamente `"The intl PHP extension is required to use the
  [format] method"` en `Illuminate\Support\Number::format()` - nada que TASK-0004 escribió toca esa
  ruta de código. No se oculta ni se cuenta como PASS: **174 passed, 1 failed (562 assertions)** es
  el resultado real y completo de la corrida.
- **Invariantes de DB (antes = después, verificado con consulta directa después de la corrida
  completa):** `taxonomy_candidate_concept_links=10`, `taxonomy_concept_relations=2`,
  `taxonomy_term_concepts=142`, `taxonomy_canonical_concepts=79`, `taxonomy_term_cpv_relations=9749`,
  `taxonomy_reviewed_proposals=0` (la tabla nueva - cero filas reales, todo lo que la corrieron los
  tests fue revertido por `DatabaseTransactions`). Los 10 candidatos/2 relaciones de TASK-0001 **no
  se tocaron**.
- **Migración `2026_09_29_193000_create_taxonomy_reviewed_proposals_table` corrió contra la
  instancia compartida de Supabase** (confirmado con `migrate:status`) - tabla nueva, aditiva, sin
  tocar ninguna fila existente.
- **Regresión de 32 queries: NO re-corrida en esta ronda.** No quedó guardado ningún `DEBUG_TOKEN`
  del Worker en ningún archivo (por diseño, ver TASK-0003) y esta sesión no tiene el token de
  Cloudflare API para rotarlo de nuevo. Justificación de por qué es seguro no re-correrla: TASK-0004
  auditó (sin modificar) tanto el comando Laravel que construye el índice de búsqueda como el Worker
  `perfilafiliados-mcp` - ninguno de los dos lee `taxonomy_candidate_concept_links` ni
  `taxonomy_concept_relations` ni la tabla nueva `taxonomy_reviewed_proposals`; solo
  `taxonomy_term_concepts`/`taxonomy_term_cpv_relations`, ambas verificadas sin cambios de conteo. No
  se ejecutó ningún `apply()` real. El único cambio persistente es una tabla nueva y vacía. Si el
  orquestador considera esto insuficiente y pide una re-corrida real, hace falta autorización
  explícita para rotar `DEBUG_TOKEN` de nuevo (mismo gate de TASK-0003).

## Nota de entorno (transparencia de proceso)

Esta sesión (VSCode extension) no tenía `php` accesible (no en PATH, sin Herd/XAMPP/Laragon). Se
intentó primero con Docker Desktop (autorizado por el usuario) pero el motor no arrancó por falta de
"Virtual Machine Platform" de Windows (WSL2), que requiere privilegios de administrador que esta
sesión no tiene - no se pudo elevar. El usuario autorizó explícitamente instalar PHP directamente:
se descargó PHP 8.2.34 NTS oficial desde `windows.php.net` a `C:\Users\esteb\php82`, configurado con
`pdo_pgsql`/`pgsql`/`bcmath`/`gd`/`zip`/`mbstring` (todos cargan correctamente) - solo `intl` quedó
bloqueada por una política de Application Control de Windows, sin poder resolverlo sin admin. Todo
lo demás (migración real, dos corridas completas de test contra Supabase) corrió con esta instalación
sin problema.

## Fuera de alcance de esta ronda (documentado, no oculto)

- Wiring de UI de Filament para que un humano dispare `freeze()` desde el panel (hoy solo invocable
  por servicio/comando `taxonomy:apply-reviewed-proposal`/tinker). Modo de ejecución pedido:
  "DESIGN + IMPLEMENT + TEST", sin mandato de UI.
- Ninguna aplicación real autorizada contra los 10 candidatos/2 relaciones ni contra ningún dato
  compartido/producción.

**STOP.** No se llamó `freeze()`/`apply()` contra ningún candidato/relación real, no se tocaron los
10 candidatos/2 relaciones de TASK-0001, no se mergeó a `main`. La decisión de APPROVED queda en
manos del orquestador.

## Tareas anteriores (histórico, no activas)

| Tarea | Estado | Archivo |
|---|---|---|
| TASK-0001 | APPROVED | (Fase C1, sin archivo de tarea propio - ver `audit/phase3_phase_c_apply.md`) |
| TASK-0002 | APPROVED | [`tasks/0002-review-503.md`](tasks/0002-review-503.md) |
| TASK-0003 | APPROVED | [`tasks/0003-phase-c-corrections.md`](tasks/0003-phase-c-corrections.md) |
| TASK-0004 | READY_FOR_REVIEW | [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md) |
