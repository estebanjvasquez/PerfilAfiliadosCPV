# CPV security programme — manual execution
Date: 2026-10-20. Owner-authorized plan; implementation begins with SEC-01.
Governance: Issue #2 comment 6293112309 plus the branch-start instruction assigned by the owner.
Active branch: `audit/security-access-01`.
Deployed baseline: `87dd9975e7cfcc2c9a5eb72d4686fbc11fc907f4`.
Continuity parent: `dae87f3093f24c1eba2f9c82ddb8057a29b6670a`.
This document records a plan, NOT an audit completion or a security guarantee.

## Resume without broad onboarding
Read SESSION_RESUME.md once, the assigned comment, and this plan. The assigned comment overrides the older resume's branch/next-task statement. Do not reread the whole issue or history. Preserve dirty worktrees and use a separate worktree as needed.
Bootstrap credentials per command using the documented per-executor store. Never copy keys into git or public evidence.
Read code only as required for the active subtask.
One foreground executor; no subagents, polling, autonomous reviewer or paid API calls.
Return one reviewable delivery and stop. No deploy without a separate owner authorization.

## Work breakdown
| ID | State | Scope | Exit evidence |
|---|---|---|---|
| SEC-01 | ROUNDS 1-2 DELIVERED FOR REVIEW. R1 2026-10-20 (audit/security_sec01_2026-10-20.md): F-01 fixed. R2 2026-10-21 (audit/security_sec01_r2_2026-10-21.md): privilege-grant injection fixed (F-02 account facet), F-04 removed, F-05 not reproduced, path boundaries clear. F-01 and R2 fixes accepted-pending-deployment, NOT deployed. F-02 role editor / F-03 / F-06 owner decisions. Remaining relation-manager/search batch pending. | Surface inventory; actor/action/company authorization; account privilege and path boundaries; bounded confirmed repairs | Coverage matrix, isolated reproductions, minimal fixes and regressions |
| SEC-02 | PLANNED | All reports/exports, temporary files/storage URLs, cache boundaries, renderer safety | Access and content isolation; safe file/cache behaviour |
| SEC-03 | PLANNED | Authentication, verification/recovery/session, inputs/uploads, privileged fields/associations | Allowed/denied flow and input regressions |
| SEC-04 | PLANNED | Dependencies, integrations/outbound requests, infrastructure/configuration review | Versioned advisory/configuration evidence and ranked proposals |
| SEC-05 | PLANNED | Consolidated regressions, residual risk and closure | Explicit scope denominator, unresolved issues and owner disposition |

Use short fix branches for independent findings; avoid a long-lived umbrella code branch.
SEC-01 is active. Later scopes receive separate bounded instructions; inventorying them does not authorize claiming their completion.
No security implementation is authorized in the managerial planning branch.

## SEC-01 acceptance
1. Verify effective deployed/remote baseline and enumerate registered routes/components in the isolated app, not just web.php.
2. Inventory public/panel/API entrypoints, Livewire methods/lazy widgets, resources/relations/search/bulk operations, downloads/jobs and file delivery. Record middleware, authorization, data scope, operation and storage/cache boundary.
3. Track verified / suspected / not-tested / excluded-with-reason. Show inventoried and tested denominators separately.
4. Actors: guest, unverified, affiliate A, affiliate B, linked staff, existing privileged roles, no-permission and revoked-access users.
5. Operations: list/read/search/create/update/delete/attach/detach/bulk/export/aggregate/settings. Preserve current owner-approved privileges; global reports have their own legitimate capability.
6. Test direct and Livewire calls, mismatched parent/child IDs, mixed-authority batches and protected fields. Include allowed cases, not only denials.
7. Use synthetic identities/companies in existing disposable PostgreSQL/runner; no shared-database fixtures, staging writes, real-company exports or active scanners against live staging.
8. Confirm hypotheses before patching. Scope filters can compensate for broad policies: inspect the complete request path.
9. Repair at most one connected critical/high root cause OR three small independent findings in one round. Register remaining findings; urgent notification is never deferred by this cap.
10. Regression must fail before and pass after. Run focused checks and one full isolated suite for final authorization/session/write changes. Synthetic browser checks where flow changes warrant them.
11. Update user manual when behaviour changes; otherwise state unchanged.

## Evidence and disclosure
Public repo/Issue: sanitized scope/status, opaque finding IDs, severity, synthetic counts and safe outcomes.
Keep unresolved exploit details, payloads, sensitive scan/log output and real identifiers outside git and webroot in restricted executor storage. State a secure handoff method; do not assume cross-executor access.
Screen staged artifacts and code/test disclosures before pushing. Do not publish a working exploit for another unresolved path.
No secret extraction, database dumps or token fragments. No destructive testing.
Immediately report confirmed serious live exposure safely, with proposed containment; do not change live infrastructure without authorization.

## Boundaries
No runtime-branch push/deploy, secret or privilege changes, schema edits, blanket dependency upgrades, Shield permission regeneration, CDN purge, infrastructure reconfiguration or new paid services.
Existing per-company export rule remains verified email + existing permission + company link; no automatic admin exception.
A missing intended business rule is a decision to surface, not permission to broaden access.
Pre-existing patterns from preliminary review are investigation leads, not confirmed vulnerabilities.

## Delivery and continuation
Return READY_FOR_REVIEW — MANUAL / CPV-SEC-01, branch, base, TESTED_HEAD, REVIEW_HEAD, sanitized report, surface/access matrix, confirmed vs suspected findings, fixes, failing-before/passing-after evidence, tests, disclosure screening, restricted handoff, manual, staging health, pending decisions, DEPLOYED: NO and resume entrypoint.
Maintain SEC-01..05 statuses with dated evidence, not prose claims of total security.
The security gate for managerial work is: agreed access model, applicable high/critical findings repaired and accepted (deployed if runtime controls are required), plus explicit disposition of residual risks. Unknown or deferred security coverage remains visible; no unlimited promise to find every bug.

## Next programme
See MANAGEMENT_TAXONOMY_PLAN.md. Its planning branch exists for continuity only and remains ON HOLD until security gate acceptance and explicit activation. Start future implementation from the accepted updated runtime/checkpoint, NOT this frozen planning branch's old runtime.
