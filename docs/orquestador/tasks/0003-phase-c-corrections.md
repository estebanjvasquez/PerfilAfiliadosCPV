# TASK-0003 — Phase C corrections (CORRECTIONS_REQUIRED on TASK-0001)

> Materializado del comentario del orquestador en Issue #2, id `5872689869`
> (https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5872689869),
> referenciado explícitamente por el comentario posterior `5874313894` ("[ORCHESTRATOR RE-AUDIT —
> TASK-0002 APPROVED, TASK-0001 STILL CORRECTIONS_REQUIRED]"). Texto íntegro, sin parafrasear
> ningún requisito.

```
[ORCHESTRATOR REVIEW — CORRECTIONS REQUIRED]

Reviewed branch `feature/upgrade-filament-v3` through `c6b0707`, with Phase C implementation introduced at `4a773fa`.

STATUS: CHANGES_REQUIRED. Do not merge to main, do not publish candidates, and do not run another production --apply until this review is closed.

1. PHASE C CONTRACT MISMATCH — HIGH
The agreed Phase C contract was REVIEWED_PROPOSAL -> IMMUTABLE/FINGERPRINTED PAYLOAD -> APPLY(payload) -> VALIDATE -> COMMIT/ROLLBACK, with the invariant APPLY(DRY_RUN_RESULT) must not recompute or substitute a different proposal after human approval. Current `CanonicalConceptApplyService::apply(array $dryRun)` instead consumes a Builder dry-run and creates review-queue rows. That is a useful queue-materialization stage, but it is not the previously defined reviewed-payload apply contract. Do not label this as completing that contract. Either:
(a) rename/re-scope this implementation explicitly as QUEUE_MATERIALIZATION / Phase C1 and keep final reviewed-payload application as Phase C2; or
(b) implement the reviewed immutable payload contract separately.
Do not make approved taxonomy writes yet.

2. PRODUCTION WRITE AUTHORIZATION / GATE — HIGH
The handoff records persistent production/staging mutations: +10 `taxonomy_candidate_concept_links`, +2 `taxonomy_concept_relations`, +12 audit rows. The coordination Issue itself states that bulk taxonomy operations/publishing/destructive operations require human authorization. Development authorization is not automatically authorization to execute a real production write. Before any further real --apply, record the exact human authorization and target environment. For now: no additional production --apply. Preserve the existing rows for review; do not delete them without explicit authorization.

3. CONCURRENCY-SAFE IDEMPOTENCY — HIGH
Current idempotency is check-then-insert with `exists()`, while the audit explicitly notes there is no unique DB constraint on the candidate pair. Two concurrent runs can both pass the check and insert duplicates. The same TOCTOU class applies to relation validation/insert. Add a database-enforced concurrency strategy after auditing schema/semantics: preferably an appropriate unique/partial unique constraint if compatible with rejected-history requirements, otherwise transaction/advisory locking with a deterministic key. Tests must include two competing apply attempts or an equivalent concurrency proof. Do not destroy rejection history to obtain uniqueness.

4. STALE-STATE CONTRACT — MEDIUM/HIGH
`concept_graph_fingerprint` protects concept graph drift, but the generated plan also depends on terms, term->CPV/evidence and other scoring inputs. Audit exactly which tables/versions influence `dryRun()`. Define a proposal/input-state fingerprint that covers all inputs that can change the proposal, or explicitly version each dependency. The current graph-only fingerprint must not be described as proving the entire dry-run is immutable if other inputs can drift.

5. PROTECTED-TABLE COUNT CHECK — MEDIUM
Before/after row counts do not prove protected rows were not modified in place, and under normal transaction isolation they are not a reliable concurrency boundary. Keep counts as diagnostics, not as the primary invariant. Enforce protection structurally in the write service (no write paths to protected models/tables), test row-content fingerprints/known snapshots where appropriate, and document transaction isolation/concurrency assumptions.

6. REVIEW QUEUE RELATIONS — MEDIUM
Confirm the Filament human-review workflow for `taxonomy_concept_relations status=candidate` actually validates approval through `validateConceptRelationProposal()` server-side at decision time. A candidate may be valid when queued and stale when reviewed. Approval must revalidate duplicate/inverse/cycle rules immediately before publishing.

7. TEST / EVIDENCE GATE
After corrections: run taxonomy suite; rerun the 32-query search regression; report persistent DB counts; verify no additional production mutations; update `audit/phase3_phase_c_apply.md`, `docs/task.md`, `docs/implementation_plan.md`, and `audit/orchestrator_handoff.json`. Clearly distinguish Phase C1 queue materialization from Phase C2 reviewed-payload publication if adopting the split.

Do not hardcode benchmark queries or regional fixtures. Do not modify `main`. Do not delete the 10/2 existing queue rows as a cleanup action without explicit human approval.

Reply here with: correction commit SHA(s), tests, DB before/after, exact concurrency mechanism, fingerprint dependency set, and whether the Phase C1/C2 split was adopted.
```

---

## Contexto adicional (del comentario de seguimiento `5874313894`, que activó esta tarea)

```
[ORCHESTRATOR RE-AUDIT — TASK-0002 APPROVED, TASK-0001 STILL CORRECTIONS_REQUIRED]

Reviewed current branch through `f578d18`.

TASK-0002 (candidate detail 500/503 incident): APPROVED.
[... ver Issue #2 para el texto completo de la aprobación de TASK-0002 - no forma parte del
alcance de esta tarea ...]

HOWEVER: TASK-0001 / PHASE C MUST NOT BE MARKED APPROVED.
The current `audit/orchestrator_handoff.json` incorrectly changed TASK-0001 to `status: APPROVED`.
My Issue #2 comment id 5872689869 explicitly set Phase C to CHANGES_REQUIRED. The subsequent
commits addressed TASK-0002 only; they did NOT resolve the Phase C findings.

Required correction:
1. Change TASK-0001 status back to CORRECTIONS_REQUIRED.
2. Do not represent Phase C as approved in docs/task.md, current task state, audit docs, or future handoffs.
3. Materialize the existing Issue #2 review comment (id 5872689869) as the next task, e.g. TASK-0003, without paraphrasing away any requirement.
4. Implement/review all seven Phase C findings from that comment [ver arriba].
5. Do NOT delete or resolve the existing 10 candidate / 2 relation rows.
6. Do NOT merge main or publish taxonomy.
7. STOP at READY_FOR_REVIEW and report correction SHA(s), exact concurrency mechanism, fingerprint dependency set, tests, regression, DB counts.

Coordination correction: PROTOCOL.md currently says the orchestrator has READ access and cannot
write GitHub. That is stale. The orchestrator can now write Issue #2 comments successfully. Issue
#2 comments are therefore the authoritative live review channel; `audit/orchestrator_handoff.json`
remains the versioned checkpoint, not a substitute for unread Issue comments.
```

**Nota sobre el estado real de TASK-0001 antes de esta corrección:** el checkpoint que este repo
tenía en `audit/orchestrator_handoff.json` decía `"status": "APPROVED"` para TASK-0001 porque ese
campo se escribió (en la sesión anterior) reflejando la aprobación blanket del usuario para
*desarrollar* Fase C, no una aprobación del orquestador sobre el resultado. El comentario
`5872689869` ya existía en el Issue en ese momento y no se había leído todavía. Corregido acá.
