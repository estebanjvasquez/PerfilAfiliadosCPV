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

## Re-audit — comentario [`5892711739`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5892711739) (2026-09-29T14:51:38Z) — CORRECTIONS_REQUIRED (narrow)

> [ORCHESTRATOR RE-AUDIT — TASK-0004 / PHASE C2 — CORRECTIONS_REQUIRED (narrow)]
>
> Reviewed READY_FOR_REVIEW at HEAD `f64bbe5` against prior review HEAD `5bcf561`, preserving the continuity rule from comment 5890195271.
>
> INHERITED EVIDENCE / CONTINUITY
> - Phase C1 remains APPROVED at `ce11d36`.
> - The frozen 32-query regression remains inherited APPROVED evidence (32/32, 0 errors, 0 diffs). This diff does not touch live search/ranking or published taxonomy data, so NO rerun is required.
> - DB invariants remain reported/reverified at 10 candidate links / 2 candidate relations / 142 term concepts / 79 canonical concepts / 9749 TERM→CPV / 0 reviewed proposals.
> - No real C2 freeze/apply, candidate decision, publication, or main merge occurred.
> - Sibling `perfilafiliados-mcp` audit is now reproducible at branch `master`, SHA `29de993c12fbdadf0577c3630cc07611af020a46`; deployed Worker SHA remains unverified and must continue to be qualified as such.
>
> ACCEPTED FROM THE PREVIOUS FINDINGS
> 1. HIGH-1 is substantially improved: term_id and relation source/target/type are frozen, APPLY writes from the frozen snapshot, and source drift aborts rather than silently changing the reviewed decision.
> 2. HIGH-2 publication bypass is materially closed for the identified legacy paths by model-level guards around candidate `published` and relation `approved` transitions; Filament/service regression coverage was updated.
> 3. The inherited-gates ledger is present and correctly separates inherited/new/not-applicable evidence.
>
> REMAINING CORRECTIONS
>
> A. CREATE_NEW STILL DOES NOT MEET THE EXPLICIT-REVIEW CONTRACT.
> The previous review required: "CREATE_NEW must require the reviewed new concept name explicitly in the frozen payload; no mutable fallback." At `f64bbe5`, `freezeCandidateLink()` still does:
> `$decisionPayload['new_concept_name'] ?? $candidate->suggested_new_concept_name`.
> Moving the fallback from APPLY to FREEZE fixes post-freeze drift but still allows the system-generated candidate value to become the human-reviewed name without an explicit reviewer choice.
>
> Required:
> - CREATE_NEW must reject/return validation failure when `new_concept_name` is absent/blank from the human decision payload.
> - Never fill it implicitly from `suggested_new_concept_name`.
> - Keep the candidate suggestion available to the UI as a suggestion only.
> - Add a test proving CREATE_NEW without explicit nonblank `new_concept_name` cannot freeze.
>
> B. SOURCE-DRIFT TEST MATRIX IS INCOMPLETE.
> The implementation compares relation source/target/type, but the test suite only has a mutation test for `source_concept_id`; the other test only verifies that endpoints/type were snapshotted. The prior gate explicitly required mutation of EACH decision-relevant source field after freeze and proof of abort + zero taxonomy writes.
>
> Add dedicated post-freeze mutation tests for:
> - relation `target_concept_id`;
> - relation `relation_type`;
> and retain the existing candidate term/name drift tests.
>
> C. NEW DOMAIN-GOVERNANCE REQUIREMENT — GENERIC BUT VALID TERMS.
> Human domain review found that some valid oil & gas terms are too generic/underspecified to support a direct product/service/CPV mapping. Do NOT force those into MAP_TO_EXISTING, CREATE_NEW, or REJECT and do NOT hardcode the current 10 terms.
>
> Add a fourth candidate-review semantic outcome, recommended canonical decision `CONTEXT_REQUIRED`:
> - meaning: valid domain term/concept, but insufficiently specific by itself for a direct product/service/CPV association;
> - distinct from REJECT;
> - must preserve reviewer reason/provenance and remain usable as contextual/search evidence;
> - must create ZERO direct TERM→CPV mapping and must not invent a specific product/service/category;
> - must participate in the immutable C2 freeze/apply/audit contract;
> - APPLY must preserve the "no direct mapping" semantic;
> - source drift/tamper must not be able to turn it into MAP_TO_EXISTING/CREATE_NEW;
> - audit search/index consumers so retaining this state cannot leak an arbitrary direct CPV association;
> - tests must use fixtures only. DO NOT review/freeze/apply/alter the existing 10 real candidates.
>
> D. TAXONOMY SUITE GATE REMAINS ENVIRONMENT-BLOCKED, NOT A C2 CODE FAILURE.
> Current evidence is 179/180 PASS (572 assertions), with the same pre-existing `ext-intl` environment failure. Do not manufacture green or alter/skip the test. Keep this explicitly classified as an environment-blocked closure gate. If exact-head CI or a valid environment becomes available, provide the clean full-suite evidence. Do not let this trigger unrelated code changes.
>
> SAFETY / SCOPE
> - No production/shared candidate decisions, freeze/apply, publication, bulk operations, destructive migrations, or merge to main.
> - Preserve the real 10 candidate links + 2 candidate relations untouched.
> - Do not hardcode any of those terms or benchmark queries.
> - Do not rerun the 32-query regression unless a subsequent correction actually changes live search/ranking/published taxonomy or another dependency that invalidates the inherited evidence.
> - Keep the inherited-gates ledger authoritative and update it only when a gate is genuinely invalidated.
>
> STATUS: TASK-0004 / Phase C2 remains CORRECTIONS_REQUIRED, now narrowed to A/B/C plus the documented environment test gate D.
>
> Update code/tests/docs/handoff and STOP at READY_FOR_REVIEW with exact HEAD.

## Re-audit — comentario [`5909267134`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5909267134) (2026-09-30T10:19:59Z) — CORRECTIONS_REQUIRED (final semantic defects)

> [ORCHESTRATOR RE-AUDIT — TASK-0004 / PHASE C2 — CORRECTIONS_REQUIRED (final semantic defects)]
>
> Reviewed READY_FOR_REVIEW at HEAD `835fdae` against prior review HEAD `f64bbe5`.
>
> Continuity:
> - Phase C1 remains APPROVED at `ce11d36`.
> - Frozen 32-query regression remains inherited APPROVED evidence; this diff does not invalidate it.
> - Existing real rows remain reported unchanged: 10 candidate links / 2 candidate relations / 142 term concepts / 79 canonical concepts / 9749 TERM→CPV / 0 reviewed proposals.
> - No real C2 freeze/apply, publication, candidate decision, or main merge reported.
> - Environment gate remains honestly reported: full taxonomy suite is not clean because of the established ext-intl environment issue; the additional transient Supabase connection failure passed on isolated reruns. Do not alter tests to manufacture green.
>
> ACCEPTED
> A. CREATE_NEW now requires explicit nonblank `new_concept_name` at freeze; implicit fallback is removed.
> B. Dedicated post-freeze drift tests now exist for relation source, target and relation_type.
> C. CONTEXT_REQUIRED exists as a distinct reviewed decision/status, requires explicit reason, is fingerprinted/audited, and performs zero direct taxonomy mapping writes.
> D. No hardcoded handling of the current 10 real terms was introduced.
>
> TWO SEMANTIC DEFECTS REMAIN
>
> 1. CREATE_NEW conflates the HUMAN-REVIEWED NAME with the SOURCE-SUGGESTED NAME.
> At freeze, `decision_payload.new_concept_name` is now correctly the explicit reviewer-selected name. But at apply, the service compares that reviewed value directly to the live `$candidate->suggested_new_concept_name` and aborts SOURCE_DRIFT when they differ.
>
> That makes a legitimate reviewer correction/normalization of the suggested name impossible: if the Builder suggested "X" and the reviewer explicitly approves CREATE_NEW as "Y", freeze succeeds but apply later treats "Y != X" as source drift even though X never changed after freeze.
>
> Required correction:
> - Freeze TWO distinct values/roles:
>   - the explicit reviewed publication value, e.g. `new_concept_name`;
>   - the source snapshot used only for drift detection, e.g. `source_suggested_new_concept_name`.
> - APPLY must create/write from the reviewed `new_concept_name`.
> - Drift detection must compare live `candidate.suggested_new_concept_name` against frozen `source_suggested_new_concept_name`, NOT against the reviewer-selected publication name.
> - Add test: candidate suggestion = X, reviewer explicitly chooses Y, no source mutation after freeze → apply succeeds and creates Y.
> - Retain test: candidate suggestion = X at freeze, then source changes to Z → apply aborts with zero taxonomy writes, regardless of reviewed publication name Y.
>
> 2. CONTEXT_REQUIRED incorrectly skips source term identity drift.
> The frozen proposal includes `term_id`, but `applyCandidateLinkDecision()` handles CONTEXT_REQUIRED before the term-id drift check and explicitly says drift need not be checked. This violates the immutable reviewed-proposal rule: CONTEXT_REQUIRED is a semantic decision ABOUT a particular term. If `suggested_term_id` changes after freeze, applying the reviewed "needs context" decision to the mutated candidate means the system is resolving a different term than the human reviewed.
>
> Required correction:
> - CONTEXT_REQUIRED must revalidate frozen `term_id` against live `suggested_term_id` before changing status.
> - On mismatch abort `SOURCE_FIELD_DRIFTED` with zero candidate resolution/publication/taxonomy writes.
> - Add dedicated test: freeze CONTEXT_REQUIRED for term A, mutate candidate suggested_term_id to term B, apply → ABORT_SOURCE_DRIFT; candidate remains pending and no taxonomy mapping is written.
>
> SEARCH-EVIDENCE CLARIFICATION
> The current implementation safely prevents CONTEXT_REQUIRED from leaking an arbitrary direct CPV mapping because search consumers do not read the candidate table. That satisfies the immediate safety requirement. However, documentation should not claim it is currently consumed as contextual search evidence unless an actual consumer exists. Phrase it precisely: the state PRESERVES the term/reason for future/contextual evidence use while creating no direct CPV association. Do not add a search consumer in this correction; doing so would invalidate the inherited search regression and broaden scope.
>
> CLOSURE
> After these two code/test corrections:
> - run `ReviewedProposalServiceTest` clean;
> - reverify DB invariants and 10/2 untouched;
> - update audit/handoff;
> - keep the full-suite environment blocker explicitly documented;
> - no 32-query rerun unless search/ranking/published taxonomy is changed;
> - no real freeze/apply, candidate decisions, publication, main merge, or hardcoded term handling.
>
> STATUS: TASK-0004 / Phase C2 remains CORRECTIONS_REQUIRED, narrowed to the two semantic defects above plus the already-documented environment test gate.
>
> STOP at READY_FOR_REVIEW with exact HEAD.

## Re-audit — comentario [`5913324183`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5913324183) (2026-09-30T14:28:12Z) — IMPLEMENTATION PASS, ENVIRONMENT GATE REMAINS

> [ORCHESTRATOR RE-AUDIT — TASK-0004 / PHASE C2 — IMPLEMENTATION PASS, ENVIRONMENT GATE REMAINS]
>
> Reviewed READY_FOR_REVIEW at HEAD `571c55a` against prior review HEAD `835fdae`, preserving the continuity ledger and prior accepted gates.
>
> RESULT
> The two semantic defects from comment `5909267134` are correctly closed.
>
> 1. CREATE_NEW — PASS
> - `freeze()` now preserves two independent values:
>   - `new_concept_name`: explicit human-reviewed publication value.
>   - `source_suggested_new_concept_name`: source/Builder snapshot used only for drift detection.
> - `apply()` compares the live candidate suggestion against `source_suggested_new_concept_name`, not against the human-reviewed name.
> - Publication uses the frozen reviewed `new_concept_name`.
> - This correctly permits Builder X → reviewer Y without false SOURCE_DRIFT while still detecting a real post-freeze X → Z source mutation.
> - Directed test evidence: `ReviewedProposalServiceTest` 41/41 PASS, 120 assertions, including the required reviewer-X/Y case and retained real-drift case.
>
> 2. CONTEXT_REQUIRED term identity — PASS
> - frozen `term_id` is revalidated before the CONTEXT_REQUIRED branch.
> - post-freeze `suggested_term_id` mutation now aborts `SOURCE_FIELD_DRIFTED`.
> - CONTEXT_REQUIRED still performs zero direct taxonomy mapping/concept creation.
> - dedicated drift test is present and included in the clean 41/41 directed suite.
>
> 3. Search-evidence wording — PASS
> Documentation now correctly states that the term/reviewer reason is preserved for POSSIBLE FUTURE contextual use and that no current search consumer reads this state. No search consumer was added, so the inherited frozen search regression remains valid.
>
> CONTINUITY / INHERITED GATES
> - Phase C1 remains APPROVED at `ce11d36`.
> - TASK-0002 remains APPROVED.
> - Frozen 32-query regression remains inherited APPROVED evidence: 32/32, 0 HTTP errors, 0 compared-field diffs. Current diff does not modify live search/ranking/published taxonomy, so rerun is NOT required.
> - DB invariants remain reported unchanged: 10 candidate links / 2 candidate relations / 142 term concepts / 79 canonical concepts / 9749 TERM→CPV / 0 reviewed proposals.
> - Existing real 10/2 review rows remain untouched.
> - No live C2 freeze/apply, candidate decision, taxonomy publication, main merge, or new schema migration occurred in this round.
> - No hardcoded treatment of the 10 real terms was introduced.
> - Sibling search-consumer audit remains inherited from the prior reproducible check at `estebanjvasquez/perfilafiliados-mcp` branch `master`, SHA `29de993c12fbdadf0577c3630cc07611af020a46`; deployed Worker SHA remains unverified as previously qualified.
>
> ENVIRONMENT GATE
> The full taxonomy-suite gate remains ENVIRONMENT_BLOCKED rather than a known C2 code failure. Last full-suite evidence remains 187/189 PASS with the established missing `ext-intl` environment issue and one transient Supabase pooler disconnect that subsequently passed on isolated reruns. The directly affected `ReviewedProposalServiceTest` is now clean at this HEAD (41/41, 120 assertions).
>
> Therefore:
> - TASK-0004 implementation/code review: PASS.
> - TASK-0004 final closure: BLOCKED ONLY by the previously documented clean-full-suite environment gate.
> Do not modify unrelated production code or skip/alter tests to manufacture closure. The appropriate closure evidence is a clean full taxonomy suite at this exact implementation (or a descendant containing only environment/CI configuration needed to execute it) in a valid PHP environment with required extensions.
>
> No authorization is granted by this PASS to review/freeze/apply/publish the existing 10 candidates or 2 relations, merge to main, or deploy to production.
>
> NEXT ACTION
> Obtain clean full taxonomy-suite evidence in a valid environment/CI without changing C2 semantics. Then STOP at READY_FOR_REVIEW with exact HEAD and test evidence. If that run is clean and no substantive code/search/data change occurred, no 32-query rerun is required.
>
> STATUS: TASK-0004 C2 IMPLEMENTATION = PASS; FINAL CLOSURE = ENVIRONMENT_GATE_PENDING.

## Estado

Ver `docs/orquestador/current_task.md` para el estado vigente, el ledger de gates heredados, y la
máquina de estados exacta implementada (documentada en `audit/phase4_c2_immutable_apply.md`,
`audit/phase4_c2_corrections_2026-09-29.md` — incluye las secciones "Ronda 3" (comentario
`5892711739`), "Ronda 4" (comentario `5909267134`) y "Ronda 5" (comentario `5913324183`)).
