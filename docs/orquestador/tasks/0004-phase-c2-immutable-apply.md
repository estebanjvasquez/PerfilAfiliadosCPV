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

## Estado

Ver `docs/orquestador/current_task.md` para el estado vigente y la máquina de estados exacta
implementada (documentada en `audit/phase4_c2_immutable_apply.md`).
