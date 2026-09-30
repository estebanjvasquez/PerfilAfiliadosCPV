# Tarea activa

**TASK-0004** — Phase C2: Reviewed Immutable Payload Application (Issue #2 comentario `5886148283`),
**correcciones del re-audit, ronda 4** (comentarios `5890113782` + `5890195271` + `5892711739` +
`5909267134`)

Archivo: [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md) (incluye
el texto verbatim de los cuatro comentarios del re-audit)

**Estado:** READY_FOR_REVIEW

Ver `PROTOCOL.md` antes de tocar esta tarea. Precondición verificada: Phase C1 (TASK-0001 +
TASK-0003) sigue `APPROVED` (comentario `5886125405`, HEAD revisado `ce11d36`) — no invalidada por
esta ronda.

## Qué pidió el re-audit (ronda 4, comentario `5909267134`) y qué se corrigió

El comentario reconfirmó A/B/C/D de la ronda 3 como correctos (nada de eso se reabrió) y acotó el
alcance a exactamente 2 defectos semánticos sobre esas mismas correcciones, más una aclaración de
fraseo en documentación (sin cambio de comportamiento).

- **Defecto 1 (CREATE_NEW conflaba el nombre revisado con el nombre sugerido)**: corregido.
  `freeze()` ahora congela DOS campos con roles distintos para CREATE_NEW: `new_concept_name` (el
  valor REVISADO/elegido por el humano - lo único que `apply()` publica) y
  `source_suggested_new_concept_name` (snapshot de la sugerencia del Builder en el instante de
  `freeze()` - lo único que `apply()` compara contra la fila viva para detectar drift de la fuente).
  Antes, `apply()` comparaba el nombre revisado contra la sugerencia viva, lo cual hacía imposible
  que un revisor corrigiera/normalizara legítimamente el nombre sugerido (Builder sugiere "X",
  humano aprueba "Y" -> abortaba tratando "Y != X" como drift de fuente, aunque "X" nunca cambió).
  1 test nuevo (revisor elige un nombre distinto al sugerido, sin mutación de la fuente -> `apply()`
  publica el nombre revisado), 1 test retenido sin cambios de intención (la fuente SÍ cambia después
  de `freeze()` -> aborta con cero escrituras), 1 aserción reforzada en un test existente.
- **Defecto 2 (CONTEXT_REQUIRED saltaba la revalidación de identidad del término)**: corregido. El
  chequeo de drift de `term_id` (compartido con MAP_TO_EXISTING/CREATE_NEW) se movió para correr
  ANTES de la rama CONTEXT_REQUIRED - antes corría después, y esa rama retornaba temprano sin pasar
  por ese chequeo. CONTEXT_REQUIRED es una decisión semántica SOBRE un término particular (a
  diferencia de REJECT, que no resuelve semánticamente ningún término), así que si
  `suggested_term_id` cambia después de `freeze()`, ahora aborta `SOURCE_FIELD_DRIFTED` con cero
  escrituras en vez de resolver un término distinto al revisado. 1 test nuevo: freeze CONTEXT_REQUIRED
  para el término A, muta `suggested_term_id` a B, `apply()` -> abort, candidato permanece pending,
  cero mapeos escritos.
- **Aclaración de fraseo (evidencia de búsqueda de CONTEXT_REQUIRED)**: la documentación decía que el
  candidato "sigue siendo evidencia contextual/de búsqueda válida", una redacción que sugiere uso
  activo por algún consumidor real. Corregido en todos los docblocks/comentarios: el término/motivo
  queda preservado para un POSIBLE uso FUTURO como evidencia contextual, aclarando explícitamente que
  ningún consumidor de búsqueda lo lee hoy. Sin cambios de código ni de comportamiento - y,
  siguiendo la instrucción explícita del comentario, NO se agregó ningún consumidor de búsqueda
  nuevo (eso habría invalidado la regresión de búsqueda heredada y ampliado el alcance).

Detalle completo de cada corrección: `audit/phase4_c2_corrections_2026-09-29.md` (sección "Ronda 4").
State machine completo: `audit/phase4_c2_immutable_apply.md`.

## Evidencia (A=heredada, B=nueva, C=invalidada-y-recorrida, D=no aplica)

- **[B] `ReviewedProposalServiceTest`: 41/41 PASS (120 assertions)** — 2 tests nuevos (1 por
  defecto), incluidos ambos escenarios que el comentario pidió explícitamente ("candidate suggestion
  = X, reviewer explicitly chooses Y ... apply succeeds and creates Y" y "freeze CONTEXT_REQUIRED for
  term A, mutate ... to term B, apply → ABORT_SOURCE_DRIFT"), más el test retenido sin cambios de
  intención y la aserción reforzada. Verificado en aislamiento.
- **[A] Suite de taxonomía completa**: no re-corrida completa esta ronda - ninguna corrección de
  ronda 4 toca código fuera de `ReviewedProposalService`/sus modelos relacionados/sus propios tests,
  y el gate de suite completa quedó explícitamente clasificado como bloqueo de entorno (no de código
  C2) en la ronda 3 con evidencia real (187/189 PASS, 595 assertions, 5619.25s) - el propio
  comentario `5909267134` reconfirma esa clasificación como vigente y no pide una re-corrida
  completa, solo que el archivo de test directamente afectado quede verde.
- **[A] `CandidateConceptApprovalServiceTest`, `TaxonomyConceptRelationValidationTest`,
  `TaxonomyCandidateConceptLinkReviewTest`, `CanonicalConceptApplyServiceTest`**: sin cambios de
  código en esta ronda - ninguno de estos archivos toca `ReviewedProposalService` ni fue modificado.
- **[A] DB invariants**: `taxonomy_candidate_concept_links=10`, `taxonomy_concept_relations=2`,
  `taxonomy_term_concepts=142`, `taxonomy_canonical_concepts=79`, `taxonomy_term_cpv_relations=9749`,
  `taxonomy_reviewed_proposals=0` — sin cambios respecto a la ronda anterior (ningún candidato/relación
  real fue tocado por esta ronda; los tests de los defectos 1/2 usan fixtures propios exclusivamente).
- **[A] Regresión de 32 queries**: heredada de `ce11d36`/comentario `5886125405` (32/32, 0 errores, 0
  diffs), no invalidada — ninguna corrección de esta ronda toca un consumidor de búsqueda, ranking, o
  datos de taxonomía publicados (y, per la aclaración de fraseo, se confirma explícitamente que no se
  agregó ningún consumidor nuevo).
- **[A] Phase C1 (`CanonicalConceptApplyService`)**: `APPROVED`, no tocada.
- **[A] TASK-0002**: `APPROVED`, no tocada.
- **[D] Migraciones de esquema**: ninguna nueva - `source_suggested_new_concept_name` es una clave
  más dentro del JSONB `decision_payload` (sin esquema propio), no una columna nueva.

## Nota de entorno (continuación de rondas anteriores)

Sin cambios esta ronda - el comentario `5909267134` reconfirma explícitamente que el bloqueo de
entorno de Docker Desktop / el gap de `ext-intl` sigue vigente y no pide una re-corrida completa de
la suite ("keep the full-suite environment blocker explicitly documented"). Ver la sección GATE 4 de
la ronda 3 en `audit/phase4_c2_corrections_2026-09-29.md` para la evidencia completa (187/189 PASS,
595 assertions, 5619.25s, con el fallo transitorio de conexión a Supabase confirmado no-reproducible).

## Fuera de alcance de esta ronda (documentado, no oculto)

- Wiring de UI de Filament para que un humano dispare `freeze()`/CONTEXT_REQUIRED desde el panel
  (sigue igual que rondas anteriores - fuera del modo de ejecución pedido).
- Ningún consumidor de búsqueda nuevo para CONTEXT_REQUIRED (instrucción explícita del comentario:
  agregarlo invalidaría la regresión de búsqueda heredada y ampliaría el alcance).
- Ninguna aplicación real autorizada contra los 10 candidatos/2 relaciones ni contra ningún dato
  compartido/producción. Todos los tests de esta ronda usan fixtures propios.
- Ninguna re-corrida de la suite completa de taxonomía (el comentario no la pidió; el gate de entorno
  ya está documentado con evidencia real de la ronda anterior).

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
| TASK-0004 (ronda 3, correcciones A/B/C) | CORRECTIONS_REQUIRED (final semantic defects, ronda 4) | mismo archivo, sección "Re-audit — comentario `5892711739`" |
| TASK-0004 (ronda 4, defectos 1/2) | READY_FOR_REVIEW | mismo archivo, sección "Re-audit — comentario `5909267134`" |
