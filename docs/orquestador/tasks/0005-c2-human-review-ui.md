# TASK-0005 — C2 Human Review UI + Safe Staging Deploy Trigger

**Fuente:** Issue #2, comentario [`5914793857`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5914793857)
(autor: `estebanjvasquez`, publicado vía la app `chatgpt-codex-connector` — orquestador),
2026-09-30T15:51:32Z. Abierta desde HEAD `63cf811` (checkpoint documental aceptado de TASK-0004).

**Precondición verificada:** TASK-0004 / Phase C2 sigue `CLOSED/APPROVED`. Despliegue/validación de
staging sigue `PASS`. Los 10 `taxonomy_candidate_concept_links` + 2 `taxonomy_concept_relations`
reales permanecen protegidos - ningún freeze/apply/reject/context-resolve corrió contra ellos
durante esta tarea.

## Texto verbatim del comentario `5914793857`

> [ORCHESTRATOR — OPEN TASK-0005 / C2 HUMAN REVIEW UI + SAFE STAGING DEPLOY TRIGGER]
>
> BASELINE
> - Start from accepted checkpoint HEAD `63cf811` on `feature/upgrade-filament-v3`.
> - TASK-0004 / Phase C2 is CLOSED / APPROVED.
> - Staging deployment/validation is PASS.
> - Existing real review data remains protected:
>   - 10 `taxonomy_candidate_concept_links`
>   - 2 candidate `taxonomy_concept_relations`
> - Do NOT make review decisions, freeze, apply, reject, context-resolve, or publish those 10/2 real rows while implementing/testing this task.
> - Frozen 32-query regression remains inherited evidence unless this task changes a live search/ranking/published-taxonomy consumer.
>
> OBJECTIVE
> Wire the approved C2 immutable-review workflow into Filament so a human reviewer can make and FREEZE an explicit review decision without publishing/applying it.
>
> Required lifecycle:
> PENDING CANDIDATE → HUMAN DECISION → C2 FREEZE / IMMUTABLE REVIEWED PROPOSAL → AWAITING EXECUTION.
>
> APPLY/PUBLICATION is deliberately NOT part of the reviewer action and remains a separately authorized execution step.
>
> A. CANDIDATE-LINK REVIEW UI
> Extend the existing Filament candidate review resource/page using the approved `ReviewedProposalService`. Do not create a parallel business-logic implementation.
>
> Support exactly these C2 decisions:
> 1. MAP_TO_EXISTING
>    - reviewer explicitly selects the canonical concept;
>    - validate compatibility/existence server-side;
>    - freeze the reviewed proposal only.
> 2. CREATE_NEW
>    - reviewer explicitly enters the publication name;
>    - no implicit fallback to Builder `suggested_new_concept_name`;
>    - display the Builder suggestion as evidence/reference only;
>    - freeze both the human-reviewed value and source snapshot according to approved C2 semantics.
> 3. CONTEXT_REQUIRED
>    - for a valid petroleum-domain term that is too generic/ambiguous to map directly to a specific concept/category/product/service;
>    - require explicit nonblank `context_reason`;
>    - zero direct TERM→CPV mapping and zero concept creation;
>    - do not claim this state is currently consumed by search.
> 4. REJECT
>    - require an explicit structured rejection reason;
>    - keep this semantically distinct from CONTEXT_REQUIRED.
>
> B. RELATION REVIEW UI
> Wire candidate concept-relation review to the same C2 freeze-first workflow.
> - Human must explicitly review the proposed source, target, and relation type.
> - Freeze an immutable relation reviewed proposal.
> - Do not approve/publish the relation from the Filament review action.
> - Preserve server-side relation validation/revalidation already implemented in C2.
> - Candidate relations must remain invisible to published taxonomy consumers until separately applied.
>
> C. REVIEWED-PROPOSAL VISIBILITY / UX
> Provide an admin-visible way to inspect the frozen reviewed proposal and its execution state.
> Minimum useful information:
> - source candidate/relation;
> - decision;
> - reviewer;
> - reviewed/frozen timestamp;
> - authorization/review reference where applicable;
> - target environment;
> - immutable payload/fingerprint in a safe diagnostic representation;
> - status such as awaiting execution / applied / aborted (use actual existing model states; do not invent a conflicting state machine);
> - apply/result metadata when it eventually exists.
>
> UX safety:
> - Make it unmistakable that "Freeze review" does NOT publish/apply.
> - Prevent accidental double-freeze/duplicate active proposal for the same source/decision according to existing C2 idempotency rules.
> - After freeze, source mutation/tamper/stale-state safeguards must continue to work.
> - Never expose secrets or raw sensitive environment configuration.
>
> D. AUTHORIZATION BOUNDARY IN UI
> Do NOT add a general "Apply/Publish" button that bypasses the separate execution authorization boundary.
> If an execution UI is architecturally useful, it may be rendered disabled/read-only with a clear "separate authorization required" state, but this task must not introduce a route/action that can execute real APPLY from ordinary reviewer interaction.
>
> E. PRESERVE LEGACY GUARDS
> The model/service publication guards implemented in TASK-0004 remain defense-in-depth.
> - Do not weaken or remove them to make Filament actions easier.
> - No legacy `CandidateConceptApprovalService` path may directly publish around C2.
> - Search/index consumers must continue to ignore pending/context-required/reviewed-but-not-applied data.
>
> F. SAFE TESTING — REAL 10/2 ROWS ARE OFF LIMITS
> All UI/service tests must use disposable fixtures and transaction rollback.
> Required coverage at minimum:
> - each of four candidate decisions freezes correctly;
> - validation errors for missing concept/name/context/rejection reason;
> - CREATE_NEW explicit reviewer value differs from Builder suggestion and remains correct;
> - CONTEXT_REQUIRED zero taxonomy mapping/concept creation;
> - relation freeze path;
> - double-submit/idempotency;
> - source drift/tamper behavior remains enforced after UI freeze;
> - UI does not expose an executable APPLY path;
> - candidate/relation remains unpublished after freeze;
> - ReviewedProposal admin view renders safely;
> - existing TASK-0002 nested-signals rendering regression remains protected;
> - authorization/reviewer provenance is structurally persisted as designed.
>
> Do not alter/skip assertions merely to manufacture green.
>
> G. OPERATIONAL HARDENING — DOC/AUDIT PUSHES MUST NOT REDEPLOY STAGING
> The current `.github/workflows/deploy-contabo.yml` deploys on every push to `feature/upgrade-filament-v3`, including documentation-only checkpoints. Correct this as part of TASK-0005.
>
> Implement a safe GitHub Actions trigger/filter so changes confined to non-runtime documentation/audit paths do NOT trigger staging deployment. At minimum consider:
> - `docs/**`
> - `audit/**`
> - Markdown-only orchestration/checkpoint files that cannot affect runtime.
>
> Be conservative: any application/runtime/dependency/migration/config/Docker/nginx/workflow change must still be eligible to deploy. Do not create a filter broad enough to suppress a legitimate deployment.
>
> Document exact trigger semantics and test/reason through representative path cases:
> - docs/audit-only push → no deployment;
> - app/service/resource/test? Distinguish runtime from tests deliberately; document the chosen behavior;
> - composer/package/config/migration/Docker/nginx/workflow/runtime code → deployment remains possible.
>
> Do NOT modify the staging compose topology merely to solve the previously observed test-isolation incident in this task unless needed for this UI work. The requirement from comment `5914592664` still stands: do not rerun the full suite using the live app's shared bind mounts.
>
> H. TEST / AUDIT GATES
> Run targeted tests for TASK-0005 in a safe environment that does not mutate the real 10/2 rows.
> Run the existing relevant taxonomy/C2 tests available without reusing the unsafe shared-staging test-container procedure.
>
> Before/after verify protected invariants:
> - candidate links = 10
> - candidate relations = 2
> - term concepts = 142
> - canonical concepts = 79
> - TERM→CPV = 9749
> - reviewed proposals = 0
> If any differ before work, STOP. If any persistent change occurs after tests, STOP and report.
>
> The accepted 32-query regression remains inherited and should NOT be rerun unless the diff actually changes search/ranking/published taxonomy behavior.
>
> I. STAGING DEPLOYMENT
> Once implementation/tests are complete:
> - allow the existing staging deployment mechanism (with the corrected path filtering) to deploy runtime changes to Contabo staging;
> - verify exact deployed HEAD;
> - smoke-test homepage/admin/login and the relevant Filament review pages;
> - verify no 500/503;
> - verify the new review UI is visible/usable without making a decision on the real 10/2 rows;
> - do NOT execute a real freeze merely to prove the UI;
> - recheck protected DB invariants after deployment.
>
> Do not run the unsafe full-suite procedure against live shared bind mounts.
>
> J. DOCUMENTATION / HANDOFF
> Update:
> - `docs/orquestador/current_task.md`;
> - create/update TASK-0005 task documentation;
> - `audit/orchestrator_handoff.json`;
> - add a focused audit artifact for TASK-0005.
>
> Record:
> - exact HEAD;
> - changed files;
> - UI behavior and screenshots/textual smoke evidence if practical;
> - targeted test commands/results;
> - deployment trigger hardening and path cases;
> - staging deployed HEAD and smoke results;
> - DB invariants before/after;
> - inherited gates and whether any were invalidated;
> - explicit confirmation that the real 10/2 rows were untouched.
>
> Do not record secrets.
>
> STOP CONDITIONS / NOT AUTHORIZED
> STOP and report rather than proceed if the work would require:
> - production deployment;
> - merge to `main`;
> - destructive migration;
> - credential/token rotation;
> - changing published taxonomy;
> - processing/freezing/applying/rejecting/context-resolving the existing 10 candidates or 2 relations;
> - changing search/ranking semantics beyond what is strictly necessary for this UI task.
>
> No such actions are authorized by TASK-0005.
>
> COMPLETION CONTRACT
> When implementation, safe tests, staging deployment, and smoke verification are complete, STOP and respond only:
>
> READY_FOR_REVIEW
> Issue #2
> HEAD <exact-sha>
>
> The orchestrator will inspect the actual diff/code/tests/deployment evidence before authorizing any human review of the real queue.

## Re-audit — comentario `5917275454` (CORRECTIONS_REQUIRED)

**Fuente:** Issue #2, comentario [`5917275454`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5917275454),
autor `estebanjvasquez`, 2026-09-30T18:29:17Z. HEAD revisado: `9d96587165a698e2ceee9290589023f61b089ea2`.

### Texto verbatim

> [ORCHESTRATOR RE-AUDIT — TASK-0005 — CORRECTIONS_REQUIRED]
>
> Reviewed HEAD `9d96587165a698e2ceee9290589023f61b089ea2` against baseline `63cf811` and TASK-0005 contract in comment `5914793857`.
>
> ACCEPTED
> - Candidate C2 UI is correctly wired to `ReviewedProposalService::freeze()`; MAP_TO_EXISTING / CREATE_NEW / CONTEXT_REQUIRED / REJECT are represented and no APPLY action is exposed.
> - CREATE_NEW preserves explicit reviewer input; Builder suggestion is evidence only.
> - Candidate REJECT uses structured reason composition.
> - Relation freeze path uses C2 and does not approve/publish.
> - New reviewed-proposal UI is read-only at the Resource/page level.
> - Legacy C2 publication guards remain intact.
> - Deploy trigger hardening with `paths-ignore: docs/**, audit/**, **.md` is conservative and the docs-only follow-up push correctly did not redeploy staging.
> - Targeted evidence is acceptable: new UI suite 24/25 with the single known local ext-intl limitation; related regression suite 99/99. No unsafe full-suite run on live staging bind mounts.
> - Staging runtime code at `b92e8739e27bc8ab8fa2d4caa15579e5c55d253d` deployed successfully; branch HEAD is documentation descendant `9d965871...`, which is expected under the new path filter.
> - DB invariants remained 10 / 2 / 142 / 79 / 9749 / 0; real 10/2 untouched.
> - 32-query regression remains inherited; this diff does not change search/ranking/published taxonomy.
>
> TWO CORRECTIONS REQUIRED
>
> 1. REVIEWED-PROPOSAL AUTHORIZATION LEAK ACROSS SOURCE TYPES
> Current `TaxonomyReviewedProposalPolicy` uses OR semantics:
> - a user allowed to view candidate links can also `view()` a relation proposal;
> - a user allowed to view relations can also view a candidate-link proposal.
> More importantly, `viewAny()` plus the unscoped Resource table means a user with permission for only ONE source type can list proposals belonging to BOTH types.
>
> Fix authorization according to the proposal's source type:
> - TERM_CONCEPT_LINK proposal requires candidate-link view permission;
> - CONCEPT_RELATION proposal requires relation view permission.
> - Scope the list/query so a user with only one permission cannot see rows of the other proposal type.
> - A user with both permissions may see both.
> - Preserve super-admin behavior through the project's normal permission model; do not add a bypass that weakens policy.
> Add tests for candidate-only viewer, relation-only viewer, both-permissions viewer, and unauthorized viewer, covering BOTH list visibility and direct detail URL access.
>
> 2. RELATION REVIEW RESOURCE STILL EXPOSES MUTATING LEGACY EDIT/DELETE DURING THE C2 REVIEW WORKFLOW
> `TaxonomyConceptRelationResource` currently exposes:
> - `freezeReview`
> - `EditAction`
> - `DeleteAction`
> and still exposes create/edit pages.
>
> Publication-via-edit is guarded, but an ordinary reviewer can still mutate or delete a candidate relation independently of the immutable C2 review workflow. In particular, deleting a source relation after/before review bypasses the intended governed review lifecycle and can destroy review evidence/source state rather than produce a C2 REJECT decision.
>
> For candidate relations participating in C2 review:
> - do not expose direct Edit/Delete actions that can bypass the review decision workflow;
> - do not allow direct edit/delete of a relation with a pending reviewed proposal;
> - REJECT must remain a C2 reviewed decision, not deletion;
> - if generic CRUD must remain for separately governed/manual relations, explicitly separate it by status/authorization and prove that C2 candidate rows cannot be mutated/deleted through those routes.
> - Existing server-side publication guard must remain.
> Add tests proving a C2 candidate relation cannot be edited/deleted around the freeze workflow, while any intentionally retained administrative CRUD behavior is narrowly scoped and documented.
>
> Do not touch the real 10 candidates / 2 relations while fixing these issues. Fixtures/transactions only. No real freeze/apply/reject.
>
> CONTINUITY
> TASK-0004 remains CLOSED/APPROVED. Staging deployment evidence remains accepted. The operational test-isolation hardening requirement remains open for future full-suite execution but is not a blocker for these two corrections. No 32-query rerun is required unless the correction changes search/ranking/published taxonomy.
>
> After corrections:
> - run focused TASK-0005 UI/policy tests and relevant C2/guard regression tests in the safe environment;
> - deploy the runtime correction to staging through the hardened workflow;
> - smoke the relevant admin routes without acting on real rows;
> - recheck 10/2/142/79/9749/0;
> - update handoff/audit and STOP.
>
> STATUS: TASK-0005 = CORRECTIONS_REQUIRED.

## Estado

Ver `docs/orquestador/current_task.md` para el estado vigente y `audit/phase5_task0005_c2_review_ui_2026-09-30.md` para el detalle completo de implementación, tests, hardening del workflow, despliegue y evidencia (sección 10 para las dos correcciones de este re-audit).
