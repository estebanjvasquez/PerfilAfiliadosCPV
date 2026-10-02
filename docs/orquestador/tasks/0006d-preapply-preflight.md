# TASK-0006D — Preflight de aplicabilidad (solo lectura) + UX de candidatos + diseño de propuestas obsoletas

**Fuente:** Issue #2, comentario
[`5949253156`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5949253156)
(autor `estebanjvasquez`, 2026-10-02T09:32:01Z). Abierta desde HEAD `510400a`.

**Antecedente:** TASK-0006 / 0006B / 0006C quedaron `CLOSED / PASS` en el comentario
[`5947549221`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5947549221).
Esta tarea existe para quitar la incertidumbre que queda **antes** de abrir TASK-0007, y recoge el
seguimiento abierto del comentario
[`5947407519`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5947407519)
(UX de `freezeReview`).

**Detalle de implementación:**
[`audit/phase6_task0006d_preapply_preflight_2026-10-02.md`](../../../audit/phase6_task0006d_preapply_preflight_2026-10-02.md).

**Artefacto del preflight real:**
[`audit/task0006d_preflight_12_proposals_2026-10-02.json`](../../../audit/task0006d_preflight_12_proposals_2026-10-02.json).

**Diseño de la PARTE 3:**
[`../designs/0006d-stale-proposal-supersession.md`](../designs/0006d-stale-proposal-supersession.md)
— **diseñado, no implementado, no ejecutado sobre datos reales.**

**TASK-0007 sigue SIN ABRIR. APPLY/PUBLISH NO AUTORIZADO.**

---

## Resultado del preflight de solo lectura sobre las 12 propuestas reales

| Categoría | Propuestas |
|---|---|
| `READY_TO_APPLY` (9) | #491, #492, #493, #494, #495, #629, #630, #631, #632 |
| `NEEDS_REVALIDATION` (3) | #420, #421, #422 — `STALE_TAXONOMY_STATE` |
| `BLOCKED_FOR_OTHER_REASON` (0) | — |

`write_statements_observed: 0`. Conteos protegidos sin cambios: 10 / 2 / 142 / 81 / 9749 / 12 / 0
applied.

Las tres obsoletas **no se tocaron**: pasarlas por `apply()` las habría dejado en `ABORTED` de forma
terminal, y re-congelar está bloqueado por el índice único parcial. Eso es exactamente lo que el
preflight viene a evitar.

---

## Texto verbatim del comentario que abrió la tarea (`5949253156`)

> [ORCHESTRATOR — OPEN TASK-0006D / PRE-APPLY READINESS + STALE REVIEW DESIGN + CANDIDATE UX HARDENING]
>
> CONTEXT
> TASK-0006 / 0006B / 0006C are CLOSED/PASS (Issue #2 comment 5947549221). Current repository checkpoint: `510400a2faac75c8223e5570e7581d7a3a96f416`.
>
> TASK-0007 (real APPLY) remains UNOPENED and APPLY/PUBLISH remains NOT AUTHORIZED.
>
> This task exists to remove the remaining uncertainty before opening TASK-0007. It has three goals:
> 1. make the existing candidate-review UI impossible to misuse when a live reviewed proposal already exists;
> 2. produce a true READ-ONLY apply-readiness preflight for all 12 reviewed proposals;
> 3. design the governed treatment of stale reviewed proposals without destroying audit history.
>
> No real reviewed-proposal lifecycle/data mutation is authorized in this task unless a later Issue #2 comment explicitly authorizes it.
>
> ======================================================================
> ORCHESTRATOR READ-ONLY WORK ALREADY COMPLETED — DO NOT REDO AS A WRITE
> ======================================================================
>
> I queried the shared Supabase database read-only before opening this task.
>
> A. Protected live counts
> - taxonomy_candidate_concept_links = 10
> - taxonomy_concept_relations = 2
> - taxonomy_term_concepts = 142
> - taxonomy_canonical_concepts = 81
> - taxonomy_term_cpv_relations = 9749
> - taxonomy_reviewed_proposals = 12
> - APPLIED reviewed proposals = 0
>
> B. The three early human reviews
> - proposal #420 → candidate 263 → term `petroleum` → CONTEXT_REQUIRED
> - proposal #421 → candidate 264 → term `crude oil` → CONTEXT_REQUIRED
> - proposal #422 → candidate 265 → term `oil and gas` → CONTEXT_REQUIRED
>
> All three currently are:
> - proposal status = PENDING_APPLY
> - source candidate status = pending
> - requires_human_confirmation = false
> - confirmed_at = NULL (expected for legitimate pre-confirmation-layer human reviews)
> - applied_at = NULL
> - taxonomy_state_fingerprint =
>   `1d0eb041f64286967c7178d9bde13b8e8f88fee82fd68bc5cc2255065c46f4f6`
>
> They MUST NOT be edited manually from “Candidatos de concepto” and MUST NOT be passed to apply() merely to discover whether they are stale, because apply() writes ABORTED on stale-state failure.
>
> C. Later reviewed proposals use a different state fingerprint
> #491, #492–#495 and #629/#630 were frozen with:
> `c236bc5159ae4421a72dc64b1daa5b850b77a40b1c19425a3c6ac6762d305da2`
>
> D. A real concept-graph change occurred after #420–#422 and before the later reviews
> Two active concepts were created after #420–#422:
> - concept #2890 — ES `oleoducto` / EN `oil pipeline`
> - concept #2891 — ES `gasoducto` / EN `gas pipeline`
>
> Their created_at timestamps are ~13:07 on 2026-10-01, while #420–#422 were reviewed around 09:48–09:50 and #491 was reviewed at 13:08.
>
> This is strong evidence that the concept graph changed after #420–#422. It does NOT substitute for computing the exact CURRENT `CanonicalConceptBuilderService::dryRunInputFingerprint()` through application code. TASK-0006D must compute that exact fingerprint read-only.
>
> E. Schema constraint relevant to stale refresh
> `taxonomy_reviewed_proposals` has DB partial unique indexes allowing only one `PENDING_APPLY` proposal per candidate/relation. Therefore a successor proposal cannot coexist as another PENDING_APPLY row unless the predecessor first leaves PENDING_APPLY through an explicit governed lifecycle transition.
>
> F. Existing apply behavior
> `ReviewedProposalService::apply()`:
> - recomputes payload fingerprint;
> - computes current `dryRunInputFingerprint()`;
> - if current fingerprint differs from frozen fingerprint, it ABORTS with STALE_TAXONOMY_STATE.
>
> Therefore apply() is NOT a safe preflight API.
>
> =========================================================
> PART 1 — READ-ONLY APPLY READINESS PREFLIGHT — REQUIRED
> =========================================================
>
> Implement a side-effect-free validation path, e.g.:
> - `ReviewedProposalService::preflight(int $proposalId)`
> - and optionally a read-only Artisan command such as
>   `taxonomy:reviewed-proposals-preflight`
>
> The preflight MUST reuse the same validation primitives as apply() rather than copy/reimplement divergent logic.
>
> It MUST NOT:
> - change proposal status;
> - write application_result;
> - write authorization_reference/target_environment/applied_at;
> - write source candidate/relation rows;
> - write audit rows;
> - create concepts/mappings/relations;
> - call apply() internally.
>
> Minimum per-proposal result:
> - proposal id/type/decision;
> - READY_TO_APPLY or explicit blocking reason;
> - payload fingerprint valid/invalid;
> - frozen taxonomy fingerprint;
> - exact current taxonomy fingerprint;
> - stale yes/no;
> - source entity exists;
> - source state still compatible;
> - source snapshot drift yes/no;
> - human-confirmation gate satisfied;
> - grouped sibling completeness/consistency where applicable;
> - relation server-side validation result where applicable;
> - expected write-set description/count, but no writes.
>
> Required blocker vocabulary should distinguish at least:
> - TAMPER_DETECTED
> - STALE_TAXONOMY_STATE
> - ENTITY_MISSING
> - SOURCE_DRIFT
> - SOURCE_ALREADY_RESOLVED
> - HUMAN_CONFIRMATION_REQUIRED
> - GROUP_INCOMPLETE_OR_INCONSISTENT
> - RELATION_VALIDATION_FAILED
> - READY_TO_APPLY
>
> Run the read-only preflight against ALL 12 real reviewed proposals and save an audit artifact.
>
> Do not infer that only #420–#422 are stale; report the actual result for every proposal.
>
> =========================================================
> PART 2 — CANDIDATE TABLE UX HARDENING — IMPLEMENT
> =========================================================
>
> Fix the defect recorded in comment `5947407519`.
>
> Current bug:
> `freezeReview` is visible when:
> - source candidate status = pending; and
> - user can update it.
>
> But `freeze()` intentionally leaves the source candidate pending, so candidates with an existing PENDING_APPLY proposal still expose a second “Revisar” workflow.
>
> Required behavior:
> - if a candidate already has an active reviewed proposal with status PENDING_APPLY, DO NOT expose the action that starts a second freeze;
> - preserve the `CONGELADA_PENDIENTE` badge;
> - preferably expose a safe “Ver propuesta revisada” affordance/link to the existing proposal;
> - no APPLY/Publish button;
> - authorization must still be enforced server-side; UI visibility is not the only guard.
>
> Regression coverage:
> - candidate with no live reviewed proposal → freezeReview available when otherwise authorized;
> - candidate with PENDING_APPLY reviewed proposal → freezeReview unavailable;
> - direct/manual invocation of a second freeze remains DB/service blocked;
> - candidate with APPLIED/ABORTED history but no active PENDING_APPLY follows the documented intended behavior (do not guess; state and test the chosen behavior);
> - existing nested-signals / bilingual review / authorization tests remain green.
>
> This runtime/UX change may be deployed to STAGING through the existing hardened workflow. It must not modify real queue rows.
>
> =========================================================
> PART 3 — STALE PROPOSAL TREATMENT — OPTIONS TO EVALUATE
> =========================================================
>
> Do NOT mutate #420–#422 in TASK-0006D. Produce a concrete design and recommendation after the preflight.
>
> OPTION A — NON-DESTRUCTIVE SUPERSEDE + SUCCESSOR REVIEW (preferred direction)
> - preserve #420–#422 unchanged as historical reviewed payloads;
> - introduce an explicit governed lifecycle transition such as SUPERSEDED/STALE_SUPERSEDED (status name is designable);
> - record predecessor/successor lineage and supersession reason/reference/time;
> - transition the old proposal out of PENDING_APPLY transactionally;
> - create a successor reviewed proposal with the CURRENT taxonomy fingerprint;
> - successor carries the prior decision only as inherited/revalidation evidence, not as silently-current human approval;
> - because current state changed, successor should normally be `prepared_by_actor_type=agent/system` + `requires_human_confirmation=true`, and the human confirms it through the existing HTTP/Filament confirmation mechanism;
> - old decision_payload and old payload_fingerprint are never rewritten;
> - unique partial index remains effective;
> - no APPLY is necessary to refresh review state.
>
> Important: if this option is implemented later, the successor must show the human what changed between old and current state so confirmation is meaningful, not ceremonial.
>
> OPTION B — SEPARATE REVALIDATION RECORD WITHOUT REPLACING THE PROPOSAL
> - keep #420–#422 PENDING_APPLY;
> - add a separate immutable revalidation entity/event binding old proposal + old fingerprint + current fingerprint + current evidence + human confirmation;
> - change apply() semantics so a valid current revalidation can satisfy stale-state safety without rewriting the original proposal fingerprint.
>
> Pros:
> - maximal preservation of original proposal identity.
> Cons:
> - adds a second execution-validity object and makes apply() more complex;
> - higher risk of two competing sources of truth.
> This option needs a very strong justification if chosen.
>
> OPTION C — HUMAN SUPERSEDE + MANUAL RE-FREEZE
> - explicitly supersede the stale proposal;
> - return the candidate to the normal human review UI;
> - human reviews the current evidence again and freezes a new proposal manually.
>
> Pros:
> - conceptually simple and strongest human re-review.
> Cons:
> - more manual work;
> - must first fix the candidate UX and add a governed supersession transition;
> - cannot delete/overwrite the old proposal.
>
> OPTION D — UPDATE FINGERPRINT IN PLACE / OVERRIDE STALE CHECK
> NOT ACCEPTABLE.
> Do not:
> - overwrite taxonomy_state_fingerprint in #420–#422;
> - recalculate payload_fingerprint merely to make them pass;
> - add a “force stale apply” flag;
> - bypass/disable STALE_TAXONOMY_STATE;
> - delete and recreate history.
>
> Those approaches defeat the immutability/stale-state contract established in C2.
>
> For the design review, compare A/B/C on:
> - auditability;
> - human agency;
> - immutability;
> - compatibility with the unique pending indexes;
> - concurrency/idempotency;
> - apply() complexity;
> - UI complexity;
> - migration scope;
> - rollback behavior;
> - ability to explain why a review was refreshed.
>
> Do NOT execute the chosen option on real #420–#422 until the orchestrator and taxonomy owner explicitly authorize that data transition in a later Issue #2 comment.
>
> =========================================================
> PART 4 — PRE-APPLY REPORT
> =========================================================
>
> Produce a single table/artifact for all 12 proposals with:
> - id
> - source term/relation
> - decision
> - review provenance
> - confirmation status
> - frozen fingerprint
> - current fingerprint
> - stale?
> - payload valid?
> - source drift?
> - relation/group validation?
> - readiness result
> - exact action required before TASK-0007
>
> Expected governance categories:
> - READY_TO_APPLY
> - NEEDS_REVALIDATION
> - BLOCKED_FOR_OTHER_REASON
>
> Do not classify by assumption. Use the read-only preflight implementation.
>
> =========================================================
> PART 5 — EXISTING RELATION EDITING RISK
> =========================================================
>
> Do not expand scope unnecessarily, but include a short readiness note on the previously recorded risk:
> editing endpoints/type of an already-approved concept relation may bypass the publication revalidation path.
>
> For TASK-0006D:
> - verify whether that risk can affect the 12 pending proposals or TASK-0007 execution;
> - if no, keep it as a separate future production/admin-hardening gate;
> - if yes, STOP and report why it blocks TASK-0007.
>
> Do not implement unrelated general admin redesign in this task unless it is demonstrably required for safe APPLY.
>
> =========================================================
> PART 6 — TEST / STAGING / INVARIANTS
> =========================================================
>
> Required:
> - focused tests for preflight side-effect freedom;
> - parity tests showing preflight blockers correspond to apply validation semantics WITHOUT calling real apply;
> - UX regression tests;
> - existing C2, confirmation, group-lock and authorization suites remain green;
> - safe isolated test path only; do not use shared live bind mounts in a way that can rewrite staging caches.
>
> Staging validation:
> - exact runtime HEAD identified;
> - / and /admin/login healthy;
> - candidate and reviewed-proposal resources healthy;
> - no 500/503;
> - no real proposal/source-row mutation.
>
> Protected counts after TASK-0006D must remain:
> 10 / 2 / 142 / 81 / 9749 / 12 reviewed / 0 applied.
>
> 32-query search regression remains inherited unless runtime changes unexpectedly touch search/ranking/published taxonomy semantics.
>
> =========================================================
> AUTHORIZED / NOT AUTHORIZED
> =========================================================
>
> AUTHORIZED in TASK-0006D:
> - code/tests/docs for READ-ONLY preflight;
> - candidate-list UX hardening;
> - staging deployment of those safe runtime changes;
> - read-only execution of preflight against the 12 real proposals;
> - design of stale refresh/supersession.
>
> NOT AUTHORIZED:
> - APPLY;
> - PUBLISH;
> - changing status of #420/#421/#422;
> - superseding/aborting/deleting/re-freezing any real proposal;
> - creating a real successor proposal;
> - changing real candidate/relation status;
> - changing published taxonomy;
> - merge to main;
> - production deploy;
> - destructive migration;
> - credential rotation.
>
> =========================================================
> COMPLETION CONTRACT
> =========================================================
>
> When code/tests/staging/preflight/design are complete, STOP and return:
>
> READY_FOR_REVIEW
> Issue #2
> HEAD <exact-sha>
>
> The audit artifact must make clear:
> A) inherited approved gates;
> B) newly executed read-only evidence;
> C) any inherited gate invalidated by this diff;
> D) not-applicable gates.
>
> TASK-0007 must remain unopened until this task is audited and any stale-review remediation is separately authorized.
