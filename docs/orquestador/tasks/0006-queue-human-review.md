# TASK-0006 — Revisión humana de la cola real

**Apertura/reanudación:** Issue #2, comentario
[`5933152293`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5933152293)
(cierra TASK-0006A como PASS/APPROVED y autoriza reanudar TASK-0006 solo para la revisión/freeze de la
cola restante).

**Re-audit vigente:** Issue #2, comentario
[`5934324928`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5934324928)
(autor `estebanjvasquez`, 2026-10-01T15:11:56Z). HEAD revisado `bd70ac7b3cf012bdd1f57329f4891fe08a0445f4`.

**Estado:** `CORRECTIONS_REQUIRED / WAITING FOR HUMAN GOVERNANCE DECISIONS`. Análisis completo de los
tres bloqueos y del hueco de workflow reportado en la sección 10 de
[`audit/phase6_task0006_queue_review_2026-10-01.md`](../../../audit/phase6_task0006_queue_review_2026-10-01.md).

## Texto verbatim del comentario `5934324928`

> [ORCHESTRATOR RE-AUDIT — TASK-0006 — CORRECTIONS_REQUIRED / HUMAN GOVERNANCE GATE]
>
> Reviewed HEAD `bd70ac7b3cf012bdd1f57329f4891fe08a0445f4` against TASK-0006 contract and the resume authorization in comment `5933152293`.
>
> ACCEPTED
> - This HEAD is documentation/audit only; no application/runtime code changed.
> - No APPLY/publication occurred.
> - Published mappings remain `taxonomy_term_concepts=142` and `taxonomy_term_cpv_relations=9749`.
> - Canonical concepts remain 81, including the two human-created pipeline concepts already accepted in the previous audit.
> - The four previously frozen proposals (#420/#421/#422/#491) remain intact.
> - Candidates 270/271 and relations 61/62 were correctly left undecided rather than guessed.
> - 32-query regression remains inherited because no search/runtime/published mapping changed.
>
> BLOCKER 1 — 266–269 WERE NOT HUMAN REVIEW DECISIONS
> The TASK-0006 contract is explicitly a CONTROLLED HUMAN REVIEW of the real queue. The resume instruction said to “review/freeze” the remaining real candidates and to stop for human clarification where necessary.
>
> The audit now states that proposals #492–#495 for:
> - 266 `exploration`
> - 267 `upstream`
> - 268 `midstream`
> - 269 `downstream`
>
> were DECIDED BY THE AGENT and merely frozen under human account #3, with explanatory text inserted into `context_reason`.
>
> That is not equivalent to a human taxonomy decision and creates an attribution problem: `reviewer_id=3` / `actor_type=user` structurally says user #3 reviewed the proposal, while the durable free-text note says Claude Code made the decision. A governance audit must not rely on contradictory attribution.
>
> I accept the agent's analysis of those four terms as RECOMMENDATIONS/EVIDENCE, not as completed human decisions.
>
> REQUIRED ACTION
> - DO NOT APPLY proposals #492–#495.
> - DO NOT delete, mutate, replace or re-freeze them during this correction without a separate explicit cleanup authorization. Preserve them as existing frozen records and mark them in handoff/audit as `AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED`.
> - The HUMAN reviewer must explicitly inspect and confirm or reject/revise each of 266–269 before TASK-0006 can close.
> - If the current immutable C2 design cannot record human confirmation of an already-frozen agent-prepared proposal without deletion/re-freeze, STOP and report the workflow gap. Do not invent a bypass.
>
> BLOCKER 2 — 270/271 REQUIRE HUMAN DESIGN DECISION
> The analysis is sound: `refinery` and `refinería` represent one bilingual concept, but current CREATE_NEW freezes only one publication name and cannot safely create one bilingual concept and attach both terms before an APPLY.
>
> Do not create duplicate concepts and do not use an APPLY as an implicit sequencing workaround.
>
> Human/orchestrator decision required before closing TASK-0006:
> A. extend C2 CREATE_NEW to freeze explicit ES + EN canonical names and support a reviewed bilingual identity flow; or
> B. define another governed sequence for creating one concept and subsequently mapping the translation alias.
>
> This is a design decision, not something the agent should choose autonomously.
>
> BLOCKER 3 — RELATIONS 61/62 REQUIRE HUMAN CURATION POLICY
> Leaving them undecided was correct. Their evidence is too weak/semantically ambiguous to infer a publication decision solely from lexical similarity.
>
> Human decision required:
> - relation #61 `production → production casing`;
> - relation #62 `oil → oil-base mud`.
>
> Until an explicit curation rule or case-by-case human decision exists, leave both candidate/unfrozen.
>
> CURRENT GOVERNED STATE FOR NEXT REVIEW
> Treat current live state as:
> - 10 candidate source rows
> - 2 candidate relations
> - 142 published TERM→CONCEPT rows
> - 81 canonical concepts
> - 9749 TERM→CPV rows
> - 8 frozen reviewed proposals
> - 0 applied proposals
> - #420/#421/#422/#491 = human-reviewed protected proposals
> - #492/#493/#494/#495 = agent-prepared frozen proposals, HUMAN_CONFIRMATION_REQUIRED
> - #270/#271 = undecided
> - relations #61/#62 = undecided
>
> TASK-0006 STATUS
> CORRECTIONS_REQUIRED / WAITING FOR HUMAN GOVERNANCE DECISIONS.
> TASK-0007 remains unopened and APPLY/PUBLISH remains NOT AUTHORIZED.
>
> DO NOT perform additional real writes from this comment. The next step is human clarification/confirmation, not autonomous agent execution.
>
> When the human has supplied the required decisions, update Issue #2/handoff without changing real data unless separately authorized, then return READY_FOR_REVIEW.

## Qué se hizo en esta ronda de corrección

**Cero escrituras reales**, conforme a la instrucción explícita del comentario. Solo documentación:

1. Reclasificación de #492–#495 como `AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED` en el audit, en
   `current_task.md` y en `audit/orchestrator_handoff.json`.
2. **Auditoría de solo lectura del diseño C2** para responder al condicional del BLOQUEO 1. **El hueco
   de workflow existe y se reporta sin inventar bypass** — detalle en la sección 10.3 del audit:
   - no hay columna de segundo actor (`confirmed_by`/`confirmed_at`) en
     `taxonomy_reviewed_proposals`;
   - los estados son solo `PENDING_APPLY` / `APPLIED` / `ABORTED`, sin `CONFIRMED`;
   - `ReviewedProposalService` solo expone `freeze()` y `apply()`; `abort()` es privado y todos sus
     puntos de invocación están dentro de la ruta de `apply()`, así que `ABORTED` es inalcanzable sin
     APPLY;
   - el índice único parcial `one_pending_per_candidate` **impide por base de datos** insertar un
     segundo `PENDING_APPLY` para el mismo candidato, así que un re-freeze humano exigiría borrar o
     abortar #492–#495 primero;
   - editar `decision_payload` a mano rompería `payload_fingerprint`, que existe justamente para
     detectar eso.
3. Cuatro opciones reportadas (extensión aditiva con `confirm()`; limpieza autorizada + re-freeze;
   entrada en `taxonomy_audit_log` sin compuerta; solo documental). **Ninguna implementada ni
   elegida** — ninguna está autorizada y la decisión es de diseño/gobernanza.

**No se tocaron** #492–#495, 270, 271, ni las relaciones 61/62. Sin APPLY, sin publicación, sin merge
a `main`, sin producción.
