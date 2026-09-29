# Tarea activa

**TASK-0004** — Phase C2: Reviewed Immutable Payload Application (Issue #2 comentario `5886148283`),
**correcciones del re-audit, ronda 3** (comentarios `5890113782` + `5890195271` + `5892711739`)

Archivo: [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md) (incluye
el texto verbatim de los tres comentarios del re-audit)

**Estado:** READY_FOR_REVIEW

Ver `PROTOCOL.md` antes de tocar esta tarea. Precondición verificada: Phase C1 (TASK-0001 +
TASK-0003) sigue `APPROVED` (comentario `5886125405`, HEAD revisado `ce11d36`) — no invalidada por
esta ronda.

## Qué pidió el re-audit (ronda 3, comentario `5892711739`) y qué se corrigió

El comentario acotó explícitamente el alcance a A/B/C más el gate D (documentado, sin acción de
código) — confirmó como aceptadas/heredadas: Phase C1, la regresión de 32 queries, los invariantes de
DB, HIGH-1/HIGH-2 (sustancialmente mejorados) y el ledger de gates heredados de la ronda 2.

- **A (CREATE_NEW seguía sin cumplir el contrato de revisión explícita)**: corregido. La ronda 2 movió
  el fallback mutable de `apply()` a `freeze()`, pero seguía siendo un fallback implícito
  (`?? $candidate->suggested_new_concept_name`). `freeze()` ahora exige `new_concept_name` EXPLÍCITO y
  no vacío en el payload de decisión - `RESULT_VALIDATION_FAILED` si falta o está en blanco, sin
  congelar nada. Nunca lo completa desde el campo sugerido, que queda disponible solo como sugerencia
  para la UI. 2 tests nuevos + 3 tests existentes actualizados para pasar el nombre explícito.
- **B (matriz de tests de drift de relación incompleta)**: corregido. 2 tests nuevos
  (`target_concept_id`, `relation_type`) - mismo patrón que el test existente de `source_concept_id`.
  Sin cambios de código de producción (el chequeo ya escribía correctamente desde la ronda 2); solo
  faltaba la cobertura de test explícita por campo.
- **C (nuevo desenlace de revisión CONTEXT_REQUIRED)**: implementado. Cuarto desenlace para
  candidatos término→concepto, para términos válidos del dominio pero demasiado genéricos/
  inespecíficos para un mapeo directo producto/servicio/CPV (sin hardcodear los 10 términos reales).
  `TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED` +
  `TaxonomyCandidateConceptLink::STATUS_CONTEXT_REQUIRED` (nuevo, distinto de `rejected` y de
  `published`). `freeze()` exige `context_reason` explícito no vacío. `apply()` nunca escribe
  `taxonomy_term_concepts` ni crea un concepto - cero escrituras, preserva el motivo del revisor en
  `review_notes`, participa del contrato C2 completo (fingerprint, audit log de revisión y de
  ejecución). Resistencia a tamper hacia MAP_TO_EXISTING/CREATE_NEW verificada explícitamente (el
  fingerprint ya cubría esto genéricamente vía el campo `decision`). Auditados los consumidores de
  búsqueda/índice: `BuildEmpresaSearchDocuments` (único consumidor real) lee exclusivamente
  `taxonomy_term_concepts`, nunca el status del candidato - estructuralmente imposible que este estado
  filtre una asociación CPV directa. 5 tests nuevos, todos con fixtures propios.
- **D (gate de suite completa)**: sin acción de código (el propio comentario lo pide explícitamente:
  "do not let this trigger unrelated code changes"). Sigue clasificado como bloqueo de entorno, no
  como falla de código C2 - ver nota de entorno abajo.

Detalle completo de cada corrección: `audit/phase4_c2_corrections_2026-09-29.md` (sección "Ronda 3").
State machine completo: `audit/phase4_c2_immutable_apply.md`.

## Evidencia (A=heredada, B=nueva, C=invalidada-y-recorrida, D=no aplica)

- **[B] `ReviewedProposalServiceTest`: 39/39 PASS (108 assertions)** — 2 tests nuevos (corrección A),
  2 tests nuevos (corrección B), 5 tests nuevos (corrección C); 3 tests existentes actualizados para
  pasar `new_concept_name` explícito (sin cambio de lo que prueban). Verificado en aislamiento antes
  de correr la suite completa.
- **[B] Suite de taxonomía completa (`--filter=Taxonomy`)**: <<PENDIENTE — corriendo en background
  contra la instancia compartida de Supabase, ver actualización en el commit de handoff>>.
- **[A] `CandidateConceptApprovalServiceTest`, `TaxonomyConceptRelationValidationTest`,
  `TaxonomyCandidateConceptLinkReviewTest`, `CanonicalConceptApplyServiceTest`**: sin cambios de
  código en esta ronda (ninguno de los archivos que tocan corrección A/B/C) - se re-verifican como
  parte de la corrida completa de arriba, no se relanzan aislados de nuevo (ya se corrieron
  aislados y en verde en la ronda 2, y esta ronda no modificó ningún comportamiento que ejerciten).
- **[A] DB invariants**: `taxonomy_candidate_concept_links=10`, `taxonomy_concept_relations=2`,
  `taxonomy_term_concepts=142`, `taxonomy_canonical_concepts=79`, `taxonomy_term_cpv_relations=9749`,
  `taxonomy_reviewed_proposals=0` — sin cambios respecto a la ronda anterior (ningún candidato/relación
  real fue tocado por esta ronda; los tests de corrección A/B/C usan fixtures propios exclusivamente).
- **[A] Regresión de 32 queries**: heredada de `ce11d36`/comentario `5886125405` (32/32, 0 errores, 0
  diffs), no invalidada — ninguna corrección de esta ronda toca un consumidor de búsqueda, ranking, o
  datos de taxonomía publicados (de hecho, la corrección C audita explícitamente el único consumidor
  real y confirma que no puede leer el estado nuevo).
- **[A] Phase C1 (`CanonicalConceptApplyService`)**: `APPROVED`, no tocada.
- **[A] TASK-0002**: `APPROVED`, no tocada.
- **[D] Migraciones de esquema**: ninguna nueva - `status`/`decision` son `VARCHAR` sin `CHECK`
  constraint en ambas tablas relevantes (`taxonomy_candidate_concept_links.status` VARCHAR(20),
  `taxonomy_reviewed_proposals.decision` VARCHAR(30)), así que los valores nuevos
  (`context_required`/`CONTEXT_REQUIRED`) no requirieron alterar el esquema.

## Nota de entorno (continuación de rondas anteriores)

Docker Desktop sigue sin estar disponible en esta sesión - el CLI `docker` ni siquiera resuelve en el
PATH de esta terminal (rondas anteriores ya habían encontrado el motor devolviendo error 500 en todo
endpoint). Consistente con el pedido explícito del comentario `5892711739` ("do not let this trigger
unrelated code changes"), no se reintentó activamente esta ronda - se mantiene la clasificación de
"entorno bloqueado, no falla de código C2". La suite se corre igual con la instalación local de PHP
8.2.34 (autorizada explícitamente por el usuario en una ronda anterior) contra la instancia compartida
de Supabase real.

## Fuera de alcance de esta ronda (documentado, no oculto)

- Wiring de UI de Filament para que un humano dispare `freeze()`/CONTEXT_REQUIRED desde el panel
  (sigue igual que rondas anteriores - fuera del modo de ejecución pedido). Solo se actualizó el badge
  color/filtro de `status` para mostrar el estado nuevo de forma legible si llegara a existir en la
  tabla.
- Ninguna aplicación real autorizada contra los 10 candidatos/2 relaciones ni contra ningún dato
  compartido/producción. Todos los tests de esta ronda usan fixtures propios.

**STOP.** No se llamó `freeze()`/`apply()` contra ningún candidato/relación real, no se tocaron los
10 candidatos/2 relaciones de TASK-0001, no se mergeó a `main`. La decisión de APPROVED queda en manos
del orquestador.

## Tareas anteriores (histórico, no activas)

| Tarea | Estado | Archivo |
|---|---|---|
| TASK-0001 | APPROVED | (Fase C1, sin archivo de tarea propio - ver `audit/phase3_phase_c_apply.md`) |
| TASK-0002 | APPROVED | [`tasks/0002-review-503.md`](tasks/0002-review-503.md) |
| TASK-0003 | APPROVED | [`tasks/0003-phase-c-corrections.md`](tasks/0003-phase-c-corrections.md) |
| TASK-0004 (ronda 1) | CORRECTIONS_REQUIRED | [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md) |
| TASK-0004 (ronda 2, correcciones) | CORRECTIONS_REQUIRED (narrow, ronda 3) | mismo archivo, sección "Re-audit" |
| TASK-0004 (ronda 3, correcciones A/B/C) | READY_FOR_REVIEW | mismo archivo, sección "Re-audit — comentario `5892711739`" |
