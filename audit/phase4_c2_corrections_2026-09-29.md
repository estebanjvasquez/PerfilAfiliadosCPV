# TASK-0004 — Correcciones del re-audit (Issue #2, comentarios `5890113782` + `5890195271`)

**Fecha:** 2026-09-29
**Revisado en:** HEAD `5bcf561` (TASK-0004 original, READY_FOR_REVIEW)
**Verdict:** `CORRECTIONS_REQUIRED` para los hallazgos sustantivos de Phase C2 (HIGH-1, HIGH-2, GATE-4,
MEDIUM-5). El comentario `5890195271` corrigió/reemplazó ÚNICAMENTE la interpretación de GATE-3
(regresión de 32 queries) - todo lo demás de `5890113782` sigue vigente sin cambios.

Texto verbatim de ambos comentarios verificado vía la API de GitHub antes de tocar código (protocolo
de esta sesión: no asumir contenido de un comentario que no fue leído textualmente).

---

## HIGH 1 — El payload congelado no congelaba todos los datos fuente decision-relevantes

**Hallazgo:** `taxonomy_reviewed_proposals` fingerprinteaba la fila de la propuesta, pero para
`TERM_CONCEPT_LINK` el payload congelado guardaba esencialmente el id del candidato + el payload de
decisión explícito. En `apply()`, el servicio releía campos MUTABLES de la fila viva
(`$candidate->suggested_term_id` para MAP_TO_EXISTING/CREATE_NEW; `$candidate->suggested_new_concept_name`
como fallback para CREATE_NEW) - un candidato pending podía cambiar después de `freeze()` y la MISMA
propuesta revisada podía terminar publicando un mapeo/nombre distinto sin romper `payload_fingerprint`
(porque esos campos nunca formaban parte del payload fingerprinteado). La tabla de candidatos tampoco
formaba parte del fingerprint de estado de taxonomía (`dryRunInputFingerprint()`).

**Corrección:**

- `freeze()` ahora congela TODOS los campos fuente decision-relevantes DENTRO del `decision_payload`,
  leídos una sola vez, bajo lock, en el instante de la revisión:
  - `TERM_CONCEPT_LINK`: `term_id` (siempre, desde `$candidate->suggested_term_id`);
    `new_concept_name` para CREATE_NEW (resuelto una sola vez: el valor explícito del llamador, o si
    no vino, `$candidate->suggested_new_concept_name` EN ESE INSTANTE - nunca diferido a `apply()`).
  - `CONCEPT_RELATION`: `source_concept_id`, `target_concept_id`, `relation_type`, siempre, desde la
    relación viva en el instante de `freeze()`.
- `apply()` usa EXCLUSIVAMENTE estos valores congelados para decidir qué escribir - nunca vuelve a
  leer `$candidate->suggested_term_id`/`suggested_new_concept_name` ni
  `$relation->source_concept_id`/`target_concept_id`/`relation_type` para ese propósito.
- La fila viva SÍ se relee, pero solo para IDENTIDAD/status/compatibilidad: `apply()` compara cada
  campo congelado contra el correspondiente campo vivo y aborta (`ABORT_SOURCE_DRIFT` /
  `SOURCE_FIELD_DRIFTED`) si drifearon, en vez de publicar en silencio con el valor congelado
  (potencialmente ya no representativo) o con el valor vivo (nunca revisado por el humano).
- `CREATE_NEW` ya no tiene fallback en `apply()` - si `new_concept_name` no está en el payload
  congelado (ej. un payload de una versión anterior del contrato), aborta por drift en vez de leer
  el campo mutable.
- **Tests nuevos** (los 4 escenarios que el hallazgo pidió explícitamente, "mutate each source
  decision field after freeze and prove apply aborts with zero taxonomy writes"):
  - `apply_aborts_with_zero_writes_when_the_candidates_term_id_drifted_after_freeze`
  - `apply_aborts_with_zero_writes_when_the_candidates_new_concept_name_drifted_after_freeze`
  - `apply_aborts_with_zero_writes_when_the_relations_source_concept_id_drifted_after_freeze`
  - Más 2 tests de "freeze snapshots the field" que verifican el contenido exacto del payload
    congelado (prueba directa de que el snapshot ocurre, no solo indirecta vía el aborto).

---

## HIGH 2 — El camino de publicación inmediata existente evade Phase C2

**Hallazgo:** `ReviewedProposalService`'s propio docblock decía explícitamente que
`CandidateConceptApprovalService` seguía siendo un camino de "aprobación inmediata" "en uso" - lo
cual contradice el requisito de Phase C2 de que las filas candidate/pending permanezcan sin publicar
hasta que una acción de C2 explícitamente autorizada las publique. Si Filament podía seguir
aprobando/publicando directo, C2 era opcional, no el contrato real de publicación.

**Corrección elegida** (de las dos opciones que el propio hallazgo ofrecía: "route review decisions
into FREEZE/PENDING_APPLY, **or otherwise prevent direct publication**"): la segunda - bloqueo a
nivel de MODELO, no una reescritura de `CandidateConceptApprovalService`.

**Mecanismo:**

- `ReviewedProposalService::isApplyingC2Publication()` (bandera de contexto estática) +
  `withC2PublicationContext(\Closure $callback)` (único punto donde se enciende/apaga, con
  `finally` - se enciende SOLO alrededor de las dos escrituras que constituyen "publicación" real:
  `taxonomy_candidate_concept_links.status -> published` y
  `taxonomy_concept_relations.status -> approved`).
- `TaxonomyCandidateConceptLink::booted()` (guard NUEVO - antes no tenía ninguno): bloquea
  CUALQUIER guardado que deje `status=published` mientras la bandera esté apagada.
- `TaxonomyConceptRelation::booted()` (guard YA EXISTENTE de TASK-0003 hallazgo 6, EXTENDIDO):
  además de la revalidación semántica que ya hacía, ahora también exige la bandera encendida para
  `status=approved`.
- **Por qué el modelo y no el servicio:** ambas escrituras problemáticas
  (`CandidateConceptApprovalService::approve()`/`resolveNewConceptProposal()`, y el guardado directo
  de `EditTaxonomyConceptRelation`) corren DENTRO de una transacción que también toca
  `taxonomy_term_concepts`/crea un concepto nuevo ANTES de la transición de status bloqueada -
  cuando el guard lanza, TODA la transacción revierte (Postgres), incluida esa escritura anterior.
  No hace falta reescribir cada punto de entrada; alcanza con bloquear el único paso final que
  todos comparten.
- `TaxonomyCanonicalConcept` (creación de concepto nuevo) NO se guardó - tiene su propio recurso de
  administración directa de Filament, sin relación con la revisión de candidatos; crear un concepto
  no es "publicar" por sí solo (esa tabla no tiene status - el link en `taxonomy_term_concepts` es
  lo que constituye publicación, y ESO sí está bloqueado).
- **UX**: `TaxonomyCandidateConceptLinkResource` (acciones `approve`/`resolveNewConcept`) y
  `EditTaxonomyConceptRelation::handleRecordUpdate()` atrapan la condición ANTES/al momento de
  llegar al guard y muestran una `Notification` legible ("requiere el flujo autorizado de Phase
  C2"), en vez de dejar que la `RuntimeException` cruda llegue hasta el usuario.

**Consecuencia en tests existentes (TASK-0001/TASK-0003, ya aprobados):** `approve()` y
`resolveNewConceptProposal()` (MAP_TO_EXISTING/CREATE_NEW) ya NO publican - los tests que antes
afirmaban éxito ahora afirman el bloqueo (renombrados para reflejarlo, con comentario explicando la
corrección). `reject()` NO está afectado (nunca escribió una tabla protegida). Ningún test se alteró
para "manufacturar verde" sin más - cada cambio documenta explícitamente por qué el comportamiento
cambió y qué prueba ahora en su lugar. Ver la lista completa de archivos de test tocados en la
sección "Archivos" abajo.

**Tests nuevos que prueban explícitamente el cierre del bypass** (lo que el hallazgo pidió: "add
regression tests proving the reviewer UI/service cannot directly create taxonomy_term_concepts,
active concepts, or approved concept relations without the C2 apply step"):

- `CandidateConceptApprovalServiceTest`: `approve_no_longer_publishes_anything_it_is_blocked_by_the_c2_bypass_guard`,
  `resolve_new_concept_proposal_create_new_no_longer_publishes_blocked_by_c2_guard`,
  `resolve_new_concept_proposal_map_to_existing_no_longer_publishes_blocked_by_c2_guard` (y las
  variantes de idempotencia/audit-log actualizadas).
- `TaxonomyCandidateConceptLinkReviewTest` (a través del panel real de Filament, con Livewire):
  `resolve_new_concept_action_map_to_existing_no_longer_publishes_blocked_by_c2_gate`,
  `resolve_new_concept_action_create_new_no_longer_publishes_blocked_by_c2_gate`,
  `approve_action_no_longer_publishes_end_to_end_through_the_panel_blocked_by_c2_gate`,
  `resolve_new_concept_action_stays_blocked_on_repeated_calls_never_publishes`.
- `TaxonomyConceptRelationValidationTest` (a través de `EditTaxonomyConceptRelation`):
  `editing_a_relation_to_approve_it_is_blocked_publication_requires_phase_c2`.

---

## GATE 3 — Regresión de 32 queries

El comentario `5890195271` corrigió esto explícitamente: la regresión aceptada en la aprobación de
Phase C1 (`ce11d36`, comentario `5886125405` - 32/32, 0 errores, 0 diffs) es evidencia HEREDADA
válida, no invalidada automáticamente por abrir TASK-0004, salvo que las correcciones toquen "a live
search consumer, ranking/query logic, published taxonomy data, or another dependency that can
reasonably affect those results". Las correcciones de esta ronda (HIGH-1/HIGH-2) no publican nada
real (0 filas reales tocadas, ver sección DB invariants) ni tocan ningún consumidor de búsqueda -
por lo tanto, **no se re-corrió** de nuevo esta ronda tampoco. Esto NO es una decisión unilateral de
esta sesión - es la aplicación literal del criterio que el propio orquestador corrigió. Sigue sin
haber `DEBUG_TOKEN` disponible en esta sesión si hiciera falta rotar de nuevo.

---

## GATE 4 — Suite de taxonomía completa debe estar verde en un entorno válido

Ver `docs/orquestador/current_task.md` para el resultado exacto y la evidencia de qué se intentó
(Docker Desktop, autorizado explícitamente por el usuario, con Virtual Machine Platform habilitado y
la máquina reiniciada) y por qué no se pudo completar esa vía en esta sesión (el motor de Docker
devolvió error 500 en cada endpoint durante todo el tiempo disponible, incluso después de
reintentos largos). El resultado que SÍ se obtuvo es una corrida completa y real contra la instancia
compartida de Supabase, con una instalación local de PHP 8.2.34 (autorizada explícitamente por el
usuario en la ronda anterior), con el único fallo confirmado como 100% ajeno a este código (falta
`ext-intl`, bloqueada por una política de Windows sin privilegios de administrador para resolverla -
mismo stack trace exacto que en la ronda anterior, en una página de TASK-0002 que ninguna corrección
de esta ronda toca).

**Resultado final: 179/180 PASS (572 assertions), 5228.69s.** Único fallo:
`TaxonomyCandidateConceptLinkReviewTest::viewing_a_propose_new_concept_candidate_with_duplicate_signals_does_not_500`
- idéntico en causa y ubicación a la ronda anterior.

---

## MEDIUM 5 — SHA/branch exacto del Worker sibling auditado

`perfilafiliados-mcp` (`C:\Proyectos\GitHub\perfilafiliados-mcp`):

- **Branch:** `master`
- **HEAD:** `29de993c12fbdadf0577c3630cc07611af020a46`
- **Fecha del commit:** 2026-09-28 16:25:12 +0200
- **Working tree:** limpio (`git status --porcelain` sin salida) al momento de la auditoría.

Re-verificado en este SHA exacto: `grep` recursivo de `taxonomy_candidate_concept_links`,
`taxonomy_concept_relations`, y `taxonomy_reviewed_proposals` sobre `src/` - sin resultados. Esta
sesión no tiene forma de verificar cuál versión está DESPLEGADA en el Worker de Cloudflare en este
momento (requeriría `wrangler deployments list` o acceso a la API de Cloudflare, que no están
disponibles en esta sesión) - la afirmación de "no filtración" es sobre el CÓDIGO en este SHA, no
sobre el estado del deploy en vivo. Dado que ninguna corrección de TASK-0004 modificó este repo
sibling, y dado que Phase C2 nunca corrió un `apply()` real, esto no representa un riesgo adicional
no divulgado.

---

## Archivos tocados en esta ronda de correcciones

- `app/Services/Taxonomy/ReviewedProposalService.php` — snapshot de campos fuente, drift detection,
  `withC2PublicationContext()`.
- `app/Models/TaxonomyCandidateConceptLink.php` — guard nuevo `booted()`.
- `app/Models/TaxonomyConceptRelation.php` — guard existente extendido.
- `app/Services/Taxonomy/CandidateConceptApprovalService.php` — docblock actualizado (bloqueado como
  camino de publicación).
- `app/Filament/Resources/TaxonomyCandidateConceptLinkResource.php` — Notification legible en vez de
  excepción cruda para `approve`/`resolveNewConcept`.
- `app/Filament/Resources/TaxonomyConceptRelationResource/Pages/EditTaxonomyConceptRelation.php` —
  ídem para aprobar una relación.
- `tests/Unit/Taxonomy/ReviewedProposalServiceTest.php` — 5 tests nuevos (drift + snapshot).
- `tests/Unit/Taxonomy/CandidateConceptApprovalServiceTest.php` — 6 tests actualizados/renombrados.
- `tests/Feature/Filament/TaxonomyCandidateConceptLinkReviewTest.php` — 4 tests actualizados/renombrados.
- `tests/Feature/Filament/TaxonomyConceptRelationValidationTest.php` — 4 tests actualizados
  (2 fixtures a INSERT crudo, 1 dividido en dos, 1 aislado con `withC2PublicationContext()`).
- `tests/Unit/Taxonomy/CanonicalConceptApplyServiceTest.php` — 1 fixture a INSERT crudo (sin cambio
  de comportamiento bajo prueba).
