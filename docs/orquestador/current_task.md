# Tarea activa

**TASK-0004** — Phase C2: Reviewed Immutable Payload Application (Issue #2 comentario `5886148283`),
**correcciones del re-audit** (comentarios `5890113782` + `5890195271`)

Archivo: [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md) (incluye
el texto verbatim de ambos comentarios del re-audit)

**Estado:** READY_FOR_REVIEW

Ver `PROTOCOL.md` antes de tocar esta tarea. Precondición verificada: Phase C1 (TASK-0001 +
TASK-0003) sigue `APPROVED` (comentario `5886125405`, HEAD revisado `ce11d36`) — no invalidada por
esta ronda.

## Qué pidió el re-audit y qué se corrigió

- **HIGH 1 (payload no congelaba todos los campos fuente)**: corregido. `freeze()` ahora congela
  `term_id`/`new_concept_name` (candidatos) y `source_concept_id`/`target_concept_id`/`relation_type`
  (relaciones) dentro del payload inmutable. `apply()` usa EXCLUSIVAMENTE esos valores para escribir,
  y aborta (`ABORT_SOURCE_DRIFT`) si la fila viva ya no coincide con lo congelado. 5 tests nuevos,
  incluidos los 4 escenarios "mutate each source field, prove apply aborts with zero writes" que el
  hallazgo pidió explícitamente.
- **HIGH 2 (camino de publicación legacy evade C2)**: corregido. Bloqueo a nivel de MODELO
  (`TaxonomyCandidateConceptLink::booted()` nuevo, `TaxonomyConceptRelation::booted()` extendido) -
  ninguna transición hacia `status=published`/`status=approved` puede ocurrir fuera del `apply()`
  autorizado de `ReviewedProposalService`. `CandidateConceptApprovalService::approve()`/
  `resolveNewConceptProposal()` (MAP_TO_EXISTING/CREATE_NEW) y el guardado directo de
  `EditTaxonomyConceptRelation` ahora fallan/revierten en vez de publicar. UI actualizada para
  mostrar un mensaje legible en vez de una excepción cruda. 9 tests nuevos/actualizados prueban
  explícitamente el cierre del bypass, a nivel de servicio Y a través del panel real de Filament
  (Livewire).
- **GATE 3 (regresión de 32 queries)**: el propio comentario `5890195271` corrigió esto - la
  regresión aceptada en `ce11d36` (comentario `5886125405`) es evidencia HEREDADA válida, no
  invalidada por esta ronda (ninguna corrección toca un consumidor de búsqueda, ranking, o datos de
  taxonomía publicados - ver ledger abajo). No se re-corrió, consistente con ese criterio explícito.
- **GATE 4 (suite completa verde en entorno válido)**: intentado con Docker Desktop (autorizado
  explícitamente por el usuario, con Virtual Machine Platform habilitado y la máquina reiniciada
  desde la ronda anterior) - el motor de Docker devolvió error 500 en TODOS los endpoints durante
  todo el tiempo disponible en esta sesión (múltiples reintentos, incluida una espera larga),
  genuinamente no disponible, no solo lento. Ver evidencia real obtenida abajo.
- **MEDIUM 5 (SHA exacto del Worker sibling)**: registrado - `perfilafiliados-mcp` branch `master`,
  HEAD `29de993c12fbdadf0577c3630cc07611af020a46`, working tree limpio. Re-verificado en ese SHA
  exacto (grep sin resultados sobre `taxonomy_candidate_concept_links`/`taxonomy_concept_relations`/
  `taxonomy_reviewed_proposals` en `src/`).

Detalle completo de cada corrección: `audit/phase4_c2_corrections_2026-09-29.md`. State machine
completo (sin cambios de fondo, solo el contrato de snapshot/drift): `audit/phase4_c2_immutable_apply.md`.

## Evidencia (distinguida per lo que pide el comentario `5890195271`: A=heredada, B=nueva, C=invalidada-y-recorrida, D=no aplica)

- **[B] `ReviewedProposalServiceTest`: 30/30 PASS (82 assertions)** — 5 tests nuevos de drift/snapshot.
- **[B] `CandidateConceptApprovalServiceTest`: 27/27 PASS** — 6 tests actualizados para el bypass cerrado.
- **[B] `TaxonomyConceptRelationValidationTest`: 10/10 PASS** — 1 test dividido en 2, 1 aislado con `withC2PublicationContext()`.
- **[B] `TaxonomyCandidateConceptLinkReviewTest`: 9/10 PASS** — 4 tests actualizados; el único fallo
  es el gap de `intl` ya documentado en la ronda anterior (ajeno, ver abajo).
- **[B] `CanonicalConceptApplyServiceTest`: 21/21 PASS** — sin cambio de comportamiento, solo 1 fixture a INSERT crudo.
- **[B] Suite de taxonomía completa: 179/180 PASS (572 assertions), 5228.69s (~87 min).** El único
  fallo es exactamente el mismo gap de `intl` documentado en la ronda anterior
  (`TaxonomyCandidateConceptLinkReviewTest::viewing_a_propose_new_concept_candidate_with_duplicate_signals_does_not_500`,
  misma excepción exacta, misma página de TASK-0002, ajena a esta corrección). No se alteró ni se
  saltó ningún test para forzar verde - el número real completo es 179/180, no 180/180.
- **[A] DB invariants**: `taxonomy_candidate_concept_links=10`, `taxonomy_concept_relations=2`,
  `taxonomy_term_concepts=142`, `taxonomy_canonical_concepts=79`, `taxonomy_term_cpv_relations=9749`,
  `taxonomy_reviewed_proposals=0` — verificado de nuevo por consulta directa después de esta ronda de
  cambios, sin cambios respecto a la ronda anterior.
- **[A] Regresión de 32 queries**: heredada de `ce11d36`/comentario `5886125405` (32/32, 0 errores, 0
  diffs), no invalidada — ver justificación en la sección GATE 3 de arriba.
- **[A] Phase C1 (`CanonicalConceptApplyService`)**: `APPROVED`, no tocada por esta ronda.
- **[A] TASK-0002**: `APPROVED`, no tocada por esta ronda.
- **[D] Migraciones de esquema**: sin migraciones nuevas en esta ronda de correcciones (la única
  migración de TASK-0004, `create_taxonomy_reviewed_proposals_table`, ya corrió en la ronda anterior
  y sigue sin cambios de esquema).

## Nota de entorno (continuación de la ronda anterior)

Docker Desktop, con Virtual Machine Platform habilitado y la máquina reiniciada (autorizado por el
usuario en la ronda anterior), sigue sin poder levantar su motor en esta sesión - devuelve
`500 Internal Server Error` en cada endpoint (`/version`, `/info`, `/containers/json`) de forma
consistente durante todo el tiempo disponible, incluida una espera de ~200s adicional. No es un
problema de "todavía está arrancando" - el log del backend muestra reintentos repetidos fallando de
la misma forma. Se documenta como bloqueo real de esta sesión, no como omisión. La suite se corrió
igual con la instalación local de PHP 8.2.34 (autorizada explícitamente por el usuario, ver ronda
anterior) - el único fallo confirmado en toda la suite (ambas rondas) es el mismo gap de `intl`
(política de Application Control de Windows, sin admin para resolverla), en una página de TASK-0002
que ninguna corrección de esta tarea toca.

## Fuera de alcance de esta ronda (documentado, no oculto)

- Wiring de UI de Filament para que un humano dispare `freeze()` desde el panel (sigue igual que la
  ronda anterior - fuera del modo de ejecución pedido).
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
| TASK-0004 (ronda 1) | CORRECTIONS_REQUIRED | [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md) |
| TASK-0004 (ronda 2, correcciones) | READY_FOR_REVIEW | mismo archivo, sección "Re-audit" |
