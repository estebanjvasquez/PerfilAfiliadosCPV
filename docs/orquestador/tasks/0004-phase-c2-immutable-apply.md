# TASK-0004 — Phase C2: Reviewed Immutable Payload Application

**Fuente:** Issue #2, comentario [`5886148283`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5886148283)
(autor: `estebanjvasquez`, publicado vía la app `chatgpt-codex-connector` — orquestador), 2026-09-29T08:01:26Z.

**Precondición verificada:** Phase C1 / TASK-0001 + TASK-0003 quedaron `APPROVED` en el comentario
[`5886125405`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5886125405)
(mismo autor/app, HEAD revisado `ce11d36`). Texto verbatim de ese comentario en
`audit/orchestrator_handoff.json` (checkpoint TASK-0003).

## Texto verbatim del comentario `5886148283`

> [ORCHESTRATOR — OPEN PHASE C2 / TASK-0004]
>
> Phase C1 is CLOSED / APPROVED at reviewed HEAD `ce11d36` (Issue #2 comment 5886125405).
>
> Open TASK-0004 — PHASE C2: REVIEWED IMMUTABLE PAYLOAD APPLICATION.
>
> Objective:
> Implement the previously deferred C2 contract:
>
> REVIEWED_PROPOSAL → immutable/fingerprinted payload → APPLY(payload) → server-side VALIDATE → transactional COMMIT/ROLLBACK → auditable outcome.
>
> Required design constraints:
>
> 1. C2 must consume an explicitly reviewed/approved proposal payload. It MUST NOT rerun CanonicalConceptBuilderService to rediscover or silently alter the proposal after human review.
>
> 2. Freeze the approved payload with a deterministic fingerprint covering all decision-relevant fields. Persist proposal/payload version, algorithm version, taxonomy/input-state fingerprint, reviewer decision context, authorization reference, target environment, timestamps, and provenance.
>
> 3. Before committing, validate server-side:
>    - payload fingerprint/integrity;
>    - stale-state/input fingerprint;
>    - referenced term/concept/relation/CPV entities still exist and remain compatible;
>    - uniqueness/idempotency/concurrency constraints;
>    - relation-type semantics, direction/inverse rules and cycle constraints where applicable;
>    - no candidate/rejected row is interpreted as published taxonomy merely because it exists.
>
> 4. Transactional application:
>    - all C2 writes for one reviewed proposal commit atomically or rollback;
>    - retry/idempotency must not duplicate mappings, concepts, relations, audit rows, or side effects;
>    - use DB-enforced concurrency protection where applicable, not check-then-insert alone.
>
> 5. Explicitly define supported reviewed actions, including at minimum:
>    - MAP_TO_EXISTING concept;
>    - CREATE_NEW concept;
>    - REJECT/no-publish;
>    - approved concept↔concept relation publication where applicable.
> Do not invent capabilities or mappings beyond the reviewed payload.
>
> 6. Candidate queue semantics:
>    - preserve the existing 10 candidate links and 2 candidate concept relations;
>    - do NOT bulk approve/reject them in this task;
>    - candidate/pending rows must remain invisible to production search/publication consumers until an explicit approved C2 action publishes them;
>    - audit Laravel and sibling `perfilafiliados-mcp` consumers to prove candidate rows cannot leak into live search.
>
> 7. Auditability:
>    - append-only audit/provenance sufficient to reconstruct exactly what was reviewed, authorized, validated, written, skipped, rejected, or rolled back;
>    - distinguish review decision from execution authorization;
>    - never store credentials/secrets in payload or audit data.
>
> 8. Safety:
>    - no hardcoded benchmark/query-specific fixes;
>    - no destructive migrations;
>    - no production candidate decisions/publication;
>    - no real C2 apply against shared/production data without a NEW explicit human authorization identifying the exact proposal(s) and target environment;
>    - no merge to main.
>
> 9. Testing/evidence:
>    - unit/integration coverage for immutable payload creation, tamper detection, stale state, duplicate/idempotent replay, concurrent apply, rollback on partial failure, MAP_TO_EXISTING, CREATE_NEW, REJECT, relation publication/revalidation, and authorization provenance;
>    - full taxonomy suite;
>    - frozen 32-query regression unchanged;
>    - before/after DB counts and content/version signatures for protected/live taxonomy tables;
>    - verify existing 10/2 candidate rows remain unchanged unless separately authorized.
>
> 10. Documentation:
>    - create TASK-0004 definition;
>    - update current_task, implementation plan and audit documentation;
>    - document exact C2 state machine and transaction boundaries;
>    - update orchestrator_handoff with implementation SHA(s), tests, regression evidence, DB invariants and any schema-only mutations.
>
> Execution mode for TASK-0004:
> DESIGN + IMPLEMENT + TEST only. No live publication/apply authorization is granted by opening the phase.
>
> STOP at READY_FOR_REVIEW. Report exact HEAD SHA, changed files, migrations, C2 state machine, transaction/idempotency mechanism, tests, 32-query regression result, and DB before/after invariants.

## Re-audit — comentario `5890113782` (2026-09-29T12:16:06Z) — CORRECTIONS_REQUIRED

Revisó `READY_FOR_REVIEW` en HEAD `5bcf561` contra el C1 aprobado `ce11d36`. Texto verbatim:

> [ORCHESTRATOR REVIEW — TASK-0004 / PHASE C2 — CORRECTIONS_REQUIRED]
>
> Reviewed READY_FOR_REVIEW at HEAD `5bcf561` against approved C1 HEAD `ce11d36`.
>
> The implementation establishes a useful C2 skeleton, but it does NOT yet satisfy the immutable reviewed-payload contract. Do not publish/apply any real candidate. Preserve the existing 10 candidate links + 2 candidate relations.
>
> HIGH 1 — THE FROZEN PAYLOAD DOES NOT FREEZE ALL DECISION-RELEVANT SOURCE DATA.
> `taxonomy_reviewed_proposals` fingerprints the proposal row, but for TERM_CONCEPT_LINK the frozen payload stores essentially the candidate id + decision payload. At apply time the service reads mutable source fields from `taxonomy_candidate_concept_links`, notably:
> - `$candidate->suggested_term_id` for MAP_TO_EXISTING and CREATE_NEW;
> - `$candidate->suggested_new_concept_name` as fallback for CREATE_NEW.
> Therefore a pending candidate can change after freeze and the same reviewed proposal can publish a different term mapping/name without breaking `payload_fingerprint`. The candidate table is not part of the C1 taxonomy-state fingerprint either. This violates "reviewed immutable payload" and "do not silently alter the proposal after human review".
>
> Required correction:
> - Freeze every decision-relevant source field needed by apply into the immutable payload/snapshot (term id, selected/existing concept id, explicit new concept name when applicable, and any other source field used to determine writes).
> - APPLY must write from the frozen payload, not rediscover values from the mutable candidate row.
> - The live candidate/relation may be read only for identity/status/compatibility revalidation; compare any decision-relevant live fields to the frozen snapshot and abort on mismatch.
> - CREATE_NEW must require the reviewed new concept name explicitly in the frozen payload; do not fall back at apply time to a mutable candidate field.
> - Apply the same principle to concept relations: freeze source_concept_id, target_concept_id, relation_type and any other publication-relevant fields; validate the live row still matches before publication.
> - Add tests that mutate each source decision field after freeze and prove apply aborts with zero taxonomy writes.
>
> HIGH 2 — LEGACY IMMEDIATE PUBLICATION PATH BYPASSES C2.
> The new service docblock explicitly says `CandidateConceptApprovalService` remains an "immediate approval" path "in use", and UI wiring to C2 is left out of scope. That conflicts with the Phase C2 requirement that candidate/pending rows remain unpublished until an explicit approved C2 action publishes them. If Filament can still approve/publish directly, C2 is optional rather than the publication contract.
>
> Required correction:
> - Audit every production write entry point for candidate links and candidate concept relations.
> - Route review decisions into FREEZE/PENDING_APPLY (or otherwise prevent direct publication) so no normal production UI/service path can bypass the C2 execution-authorization gate.
> - Do NOT actually decide/freeze the existing 10/2 rows while implementing this.
> - Add regression tests proving the reviewer UI/service cannot directly create `taxonomy_term_concepts`, active concepts, or approved concept relations without the C2 apply step and execution authorization.
> - If a legacy service must remain for backward compatibility, it must not remain reachable as a production publication path.
>
> GATE 3 — REQUIRED FROZEN 32-QUERY REGRESSION WAS NOT RUN.
> TASK-0004 explicitly required the unchanged frozen 32-query regression. The handoff says NOT_RE_RUN. Architectural reasoning/search-leak analysis does not replace the requested execution gate. Do not weaken it. Run the same frozen corpus and compare to the accepted baseline; no fixture changes and no query-specific fixes. If DEBUG_TOKEN rotation is required, do not rotate it without explicit human authorization.
>
> GATE 4 — FULL TAXONOMY SUITE IS NOT GREEN.
> The handoff reports "174/174 PASS + 1 environment-only failure". That is not a fully passing suite. I accept that ext-intl may be an environment issue rather than a C2 regression, but closure requires either:
> (a) run the full suite in the established project/CI environment where required extensions are available and report a clean result; or
> (b) provide CI evidence at this exact review HEAD proving the full suite passes.
> Do not alter/skip the pre-existing test to manufacture green.
>
> MEDIUM 5 — SEARCH-CONSUMER PROOF IS INCOMPLETE FOR THE SIBLING WORKER.
> The handoff says the `perfilafiliados-mcp` branch was "not checked this session". Record the exact audited repo SHA/branch (and, if relevant, deployed Worker version/commit) so the non-leak conclusion is reproducible. Verify that candidate/reviewed-proposal tables are not consumed by the actual production search path.
>
> What is accepted so far:
> - separate freeze/apply structure;
> - proposal-row tamper fingerprint;
> - explicit execution authorization reference + auto-captured target environment;
> - transaction + row lock replay behavior;
> - additive reviewed-proposals table and partial uniqueness approach;
> - no live C2 apply/data mutation reported;
> - existing 10/2 and protected counts reported unchanged.
>
> Closure requirements:
> 1. Fix immutable snapshot/source-drift issue.
> 2. Close/bypass-proof legacy direct publication paths.
> 3. Full taxonomy suite green in a valid environment.
> 4. Re-run frozen 32-query regression unchanged.
> 5. Re-verify DB invariants and existing 10/2 unchanged.
> 6. Record exact sibling Worker SHA used for leak audit.
> 7. No live freeze/apply on existing rows, no candidate decisions, no publication, no main merge.
> 8. Update audit/handoff and STOP at READY_FOR_REVIEW with exact HEAD and evidence.
>
> STATUS:
> - TASK-0004 / Phase C2: CORRECTIONS_REQUIRED.
> - Phase C1 remains APPROVED.
> - No production publication/apply authorization is granted by this review.

## Corrección de continuidad — comentario `5890195271` (2026-09-29T12:20:56Z)

Corrige ÚNICAMENTE la interpretación de GATE 3 del comentario anterior. Texto verbatim:

> [ORCHESTRATOR REVIEW — TASK-0004 — CLARIFICATION / CONTINUITY CORRECTION]
>
> This comment corrects and supersedes ONLY the regression-gate interpretation in comment 5890113782. All substantive C2 findings not explicitly changed below remain in force.
>
> AUDIT CONTINUITY RULE
> Before declaring any previously established gate missing/failed, the reviewer and development agent MUST trace the last accepted evidence in Issue #2 + orchestrator_handoff + audit artifacts. A gate already executed and APPROVED remains inherited evidence until a subsequent change invalidates it. Do not silently reset approved gates between phases.
>
> 32-QUERY REGRESSION:
> - At Phase C1 closure, HEAD ce11d36, comment 5886125405 explicitly APPROVED the unchanged frozen regression: 32/32 responses, 0 HTTP errors, 0 compared-field differences against audit/regression_baseline_2026-09-23.json.
> - TASK-0004 reports zero live C2 apply/publication, zero taxonomy/data mutation, and the new reviewed-proposals table is empty.
> - Therefore the absence of a new 32-query run at HEAD 5bcf561 is NOT, by itself, a blocker for this review.
> - The accepted ce11d36 regression remains the inherited search baseline unless TASK-0004 corrections modify a live search consumer, ranking/query logic, published taxonomy data, or another dependency that can reasonably affect those results.
> - If later corrections do affect such a dependency, rerun the unchanged 32-query corpus. Credential rotation still requires explicit human authorization.
>
> CURRENT TASK-0004 BLOCKERS THAT REMAIN:
> 1. Immutable payload/source-drift defect: freeze every decision-relevant source field; apply must write from the frozen reviewed payload and abort if live source fields relevant to the decision drift.
> 2. C2 bypass: normal production review/publication paths must not remain able to publish directly through CandidateConceptApprovalService or equivalent without the C2 execution-authorization step.
> 3. Full taxonomy test evidence must be clean in a valid environment or backed by CI evidence at the reviewed HEAD; do not alter/skip tests merely to get green.
> 4. Record the exact perfilafiliados-mcp SHA/branch (and deployed version if applicable) used for the search-consumer leak audit.
> 5. Reverify DB invariants and preserve the existing 10 candidate links + 2 candidate relations.
> 6. No live freeze/apply on those rows, no candidate decisions/publication, no main merge.
>
> MANDATORY HANDOFF CONTINUITY SECTION
> Update orchestrator_handoff with a compact inherited-gates ledger containing, for each important gate:
> - gate name;
> - last approved Issue #2 comment;
> - approved HEAD;
> - evidence artifact/result;
> - whether current changes invalidate it: YES/NO + reason;
> - whether rerun is required.
> At minimum track: 32-query regression, taxonomy suite, DB invariants, production mutation status, C1 approval, TASK-0002 approval, schema migrations, and current candidate/relation counts.
>
> READY_FOR_REVIEW must explicitly distinguish:
> A) inherited approved evidence;
> B) newly executed evidence;
> C) invalidated evidence that had to be rerun;
> D) gates not applicable to the current diff.
>
> STATUS remains TASK-0004 / Phase C2 = CORRECTIONS_REQUIRED for the substantive C2 findings above, NOT because the 32-query regression was omitted.
>
> Stop again at READY_FOR_REVIEW with exact HEAD and evidence.

## Estado

Ver `docs/orquestador/current_task.md` para el estado vigente, el ledger de gates heredados, y la
máquina de estados exacta implementada (documentada en `audit/phase4_c2_immutable_apply.md` y
`audit/phase4_c2_corrections_2026-09-29.md`).
