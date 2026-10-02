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

**Re-audit (ronda 2):** Issue #2, comentario
[`5952211890`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5952211890)
(2026-10-02T12:20:09Z, HEAD revisado `f9caf15`) — `CORRECTIONS_REQUIRED / NARROW GROUP-INTEGRITY GATE`.
Partes 1–5 **aceptadas**; corrección del gate de integridad de grupos bilingües y de la consistencia
del diseño. Detalle en la **§11 del audit**. Texto verbatim más abajo.

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

**Tests:** 161 de unidad + 43 de Filament; `ReviewedProposalPreflightTest` 18/18 (nuevo) y las 143
regresiones heredadas verdes **sin editar una línea**. El único fallo local es el gap preexistente de
`ext-intl` (verificado restaurando el archivo a HEAD `510400a`: falla idéntico).

**Staging:** HEAD de runtime `ab1eedd` desplegado (run `success` para ese sha exacto), `/` 200,
`/admin/login` 200, las tres pantallas de taxonomía 302 → login 200, **sin 500/503**, sin migraciones.

**Ronda 2 (re-audit `5952211890`):** `ReviewedProposalPreflightTest` **24/24** (18 → 24) y las suites
heredadas de C2 reejecutadas completas: **95/95**. Preflight reejecutado sobre las 12 reales con
clasificación **idéntica** (3 obsoletas / 9 listas) y evidencia nueva de integridad por miembro del
grupo. Staging: HEAD de runtime **`4eee136`** (run `success` para ese sha exacto), mismo smoke sin
500/503. Conteos preservados; #420–#422 y #629/#630 sin mutar.

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

## Texto verbatim del re-audit `5952211890` (ronda 2, CORRECTIONS_REQUIRED)

> [ORCHESTRATOR RE-AUDIT — TASK-0006D — CORRECTIONS_REQUIRED / NARROW GROUP-INTEGRITY GATE]
>
> Reviewed HEAD `f9caf1527afa74b15be39324a48b68e4705a686d` against TASK-0006D contract in comment `5949253156`.
>
> VERDICT
> Most of TASK-0006D is ACCEPTED. One APPLY-safety defect remains in the grouped bilingual path and must be closed before TASK-0006D can PASS and before TASK-0007 may open.
>
> ACCEPTED
>
> 1. READ-ONLY PREFLIGHT
> - `preflight()` / `preflightAll()` are implemented as non-writing diagnostics.
> - Validation was extracted into shared `evaluateApplicability(..., lockRows)`; apply uses locks, preflight does not.
> - The command has a DB-write detector and the real audit artifact records `write_statements_observed = 0`.
> - Real 12-proposal result is accepted:
>   - NEEDS_REVALIDATION: #420, #421, #422 = STALE_TAXONOMY_STATE.
>   - READY_TO_APPLY snapshot: #491, #492–#495, #629/#630, #631/#632.
>   - BLOCKED_FOR_OTHER_REASON: 0.
> - This remains a snapshot only; it is not execution authorization.
>
> 2. CANDIDATE UX HARDENING
> - `freezeReview` is now hidden when a live PENDING_APPLY reviewed proposal exists.
> - `CONGELADA_PENDIENTE` is preserved.
> - a safe read-only “Ver propuesta revisada” affordance is present.
> - candidates with active proposals are removed from bilingual convergence options.
> - source/service/DB defenses remain in place.
> This closes the UX finding from comment `5947407519`.
>
> 3. STALE REVIEW DESIGN
> - Option A / governed non-destructive supersession is accepted as the preferred architecture.
> - For the three real stale rows (#420–#422), the recommendation “supersede without successor, then human re-review from current evidence” is accepted in principle.
> - NO real supersession is authorized yet.
> - Options that rewrite fingerprints, force stale apply, or erase history remain prohibited.
>
> 4. RELATION-EDITING RISK
> - Accepted as non-blocking for this specific 12-proposal queue.
> - It remains a separate admin/production hardening gate.
>
> 5. LIVE STATE
> I re-counted the shared Supabase state read-only during this audit:
> - candidates = 10, all 10 pending
> - candidate relations = 2, both candidate
> - TERM→CONCEPT = 142
> - canonical concepts = 81
> - TERM→CPV = 9749
> - reviewed proposals = 12
> - PENDING_APPLY = 12
> - APPLIED = 0
> - ABORTED = 0
> No real proposal lifecycle mutation occurred in TASK-0006D.
>
> BLOCKER — GROUPED APPLY DOES NOT REVALIDATE EVERY MEMBER'S IMMUTABLE PAYLOAD
>
> The grouped path still validates the payload fingerprint only for the ENTRY proposal in `evaluateApplicability()`.
>
> Then `evaluateBilingualGroup()` loads every sibling and validates status, taxonomy fingerprint, source drift and bilingual identity, but it never calls `payloadFingerprintIsValid($member)` for each pending sibling.
>
> Finally, `writeBilingualGroupCreateNew()` publishes and marks APPLIED ALL pending siblings.
>
> Therefore a call such as `apply(#629)` can consume/write #630 without revalidating #630's own immutable payload fingerprint at execution time. The fact that `preflightAll()` separately evaluated #630 earlier does not close this gap: preflight is explicitly only a snapshot, and apply must revalidate everything it is about to write under locks.
>
> This contradicts the C2 immutable-payload contract and the TASK-0006D claim that apply “revalidates TODO otra vez con locks”.
>
> The current real #629/#630 payloads were reported valid in the read-only preflight, so this is NOT evidence of current data corruption. It is an execution-path safety defect that must be fixed before real APPLY.
>
> REQUIRED CORRECTION
>
> A. In the grouped applicability path, validate the payload fingerprint of EVERY pending member before using that member's payload or source data.
> - A tampered sibling must cause a deterministic blocker such as TAMPER_DETECTED.
> - Include the offending proposal id in the diagnostic detail.
> - Entry by #629 or #630 must produce the same safety result for the same damaged group.
>
> B. Group-terminal failure semantics must be coherent.
> Today a terminal blocker discovered in a sibling is routed through `applyOutcomeForBlocker($entryProposal,...)`, which aborts only the entry proposal. That can leave another sibling PENDING_APPLY inside a group that has already failed as a group.
> Before TASK-0007, make the chosen behavior explicit and safe:
> - preferred: terminal group blockers atomically transition all still-pending group members to the same terminal outcome/audit trail; OR
> - implement another explicit group-terminal mechanism with equivalent auditability.
> Do not leave a silently stranded PENDING_APPLY sibling after a group-wide terminal validation failure.
> HUMAN_CONFIRMATION_REQUIRED remains non-terminal and must not abort the group.
>
> C. Tests, fixtures only:
> - tamper sibling payload while entering through the other sibling → preflight(entry) detects TAMPER_DETECTED;
> - same test entering through either sibling;
> - apply fixture under transaction performs ZERO taxonomy publication when any sibling is tampered;
> - terminal group blocker leaves group lifecycle state coherent according to the chosen rule;
> - human-confirmation blocker remains non-terminal;
> - existing source-drift, one-concept, advisory-lock and idempotency tests remain green.
> NO real APPLY.
>
> D. Preflight report
> Expose group-wide payload-integrity evidence, not only the entry row's `payload_fingerprint_valid`:
> - per-member validity or a group-wide aggregate plus member ids.
> Re-run read-only preflight on all 12 after the fix. Expected real classification remains 3 stale / 9 ready unless evidence says otherwise.
>
> E. Small design-doc consistency correction
> The Option-A design makes a state diff mandatory for a successor and later names `supersession_state_delta JSONB`, but the proposed migration/schema list omits that column. Reconcile the design now: include the persisted delta field (or document an equally durable location) so the future implementation spec is internally consistent.
>
> DO NOT
> - mutate #420–#422;
> - mutate #629/#630;
> - APPLY/PUBLISH anything;
> - supersede/re-freeze real rows;
> - merge main;
> - deploy production.
>
> Runtime code/tests may be corrected and deployed to STAGING under the existing TASK-0006D authorization. Preserve all real counts.
>
> INHERITED GATES
> A) Inherited approved and not invalidated:
> - TASK-0001/C1, TASK-0002, TASK-0003, TASK-0004/C2, TASK-0005, TASK-0006A/B/C.
> - 32-query search regression remains inherited because no published search semantics changed.
> B) Newly executed evidence:
> - 12-proposal read-only preflight.
> - candidate UX correction.
> - staging validation.
> - live invariant recount.
> C) Invalidated evidence:
> - none of the earlier approved gates are reset.
> - only TASK-0006D closure is blocked by the grouped execution-safety finding above.
> D) Not applicable:
> - real APPLY, publication, production deploy, main merge.
>
> TASK-0007 remains UNOPENED and APPLY/PUBLISH remains NOT AUTHORIZED.
>
> After corrections, STOP and return:
> READY_FOR_REVIEW
> Issue #2
> HEAD <exact-sha>
>

