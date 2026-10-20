# CPV managerial information and taxonomy — proposed implementation plan
Date: 2026-10-20.
State: DOCUMENTED / ON HOLD FOR SECURITY; no implementation authorization.
Planning branch: `planning/management-taxonomy`.
Active security branch: `audit/security-access-01`; see SECURITY_REVIEW_PLAN.md.
Runtime at planning: `87dd9975e7cfcc2c9a5eb72d4686fbc11fc907f4`.
This plan must survive session changes without rereading historical Issue #2 discussions.

## Activation and branch lifecycle
Complete the agreed security gate first: permission model reviewed, relevant critical/high findings resolved and accepted, required runtime protections deployed, residual risks explicitly recorded/dispositioned.
Then obtain an explicit bounded managerial implementation instruction.
This branch preserves the specification only. It is NOT a future deployment candidate.
Create feature branches from the latest accepted runtime/checkpoint after security work, carrying these documentation files forward. Do not merge the frozen planning runtime over newer security fixes.
One feature/reviewable subtask per short branch; no parallel agent sessions or permanent mega-branch.
No deploy, schema changes, taxonomy publication or SMTP changes are authorized by this plan.

## Objective
Give Cámara managers reliable answers about affiliate capabilities and data quality using the new CPV hierarchy, while preserving clearly labelled legacy indicators.
Do not confuse dictionary terms/relationships, enterprise category assignments, self-declarations, validation and unconfirmed suggestions.

## Work breakdown
| ID | State | Scope | Acceptance |
|---|---|---|---|
| MGT-01 | ON HOLD | Role/visibility model, KPI dictionary and report inventory | Owner-reviewed metric definitions and filters |
| MGT-02 | ON HOLD | Shared authorized query/aggregation layer | Synthetic expected counts, no double-counting, bounded query cost |
| MGT-03 | ON HOLD | Dashboard UX and drill-down | Same filtered population; accessible desktop/mobile |
| MGT-04 | ON HOLD | Executive PDF, category/company matrix and quality/pending exports | Screen/PDF/Excel parity and protected delivery |
| MGT-05 | ON HOLD | Regression, performance, manual and release | Tested exact SHA, reviewed evidence, separate deployment authorization |

## Proposed views
1. Executive summary: active affiliates, category coverage, freshness, incomplete profiles and actionable gaps.
2. Sector capabilities: Group -> Family -> Category, unique companies, geographic/chapter breakdown and authorized company drill-down.
3. Taxonomy quality/governance: declared, validated and suggested assignments separately; unclassified companies, requests and pending review. Restrict governance operations/visibility by existing approved roles.

## Metric contracts before coding
For EACH KPI define business question, source, unit, numerator/denominator, status/origin eligibility, distinct key, null/No aplica handling, filters, freshness and access rule.
Count distinct companies at each hierarchy level. Multi-category companies must not inflate parent totals. Explain overlapping percentages.
Unconfirmed suggestions never silently count as confirmed capabilities.
Distinguish “has a published category assignment” from “has validated assignments”; finalized terminology must match actual schema/governance.
Retain legacy sector/service metrics as explicitly labelled legacy references; do not combine them with CPV categories without a reviewed mapping.
Missing data is not zero capability. No aplica differs from no response.
Do not infer exact employment/revenue totals from range buckets.
Do not display historical category trends without suitable historical evidence. Snapshot/history design, if needed, is a later explicit schema decision.
Define company/affiliation activity versus deleted/inactive records.
Date/filter/timezone/as-of labels must be consistent and visible in all outputs.

## Existing correctness leads to verify
- GerenciaMetrics::resumen counts Experience rows, though a row contains multiple work entries. Define and test actual project/work counting.
- “Empresas activas” must not mislabel a filtered population including inactive enterprises.
- The old JoinViews report still has incomplete-company, missing PDF action and experience/presence interpretation issues; record disposition before reuse.
- Several metrics independently load company relationships. Measure request/query/time/memory cost before adding graphs; do not assume eager-loading eliminates repeated work.
- Current taxonomy overview describes dictionary/governance counts; it is not the same as affiliate capability coverage.

## Data and authorization architecture
Prefer incremental repair of existing services/widgets, not a new database/BI platform.
Use a shared normalized filter/population and reusable aggregate service for dashboard and exports.
Validate filters server-side; recheck permission for actions/downloads and tenant/company drill-down. Lazy widget/Livewire requests must preserve the intended filter/access scope.
Any cache key must include effective scope, filters and relevant version/invalidation context. Do not cache sensitive exports publicly.
Never materialize all companies/relationships repeatedly merely to count rows if bounded aggregates suffice; justify optimization with measured results.
No automatic taxonomy approvals, remapping or client-file import.

## Proposed first reports
- Executive summary: selected population, main KPIs, data gaps and interpretation notes.
- Category/company matrix: hierarchical code/label, distinct authorized companies, principal flag and assignment origin/status.
- Classification quality/pending work: unclassified, declared/validated/suggested distinctions and actionable review queues, only for authorized roles.
UI, PDF and Excel share definitions, filters and data sources.
Excel text fields need safe handling of spreadsheet formula injection; PDF/HTML must escape untrusted data. Preserve section selection and complete affiliate dossier behaviour.

## Verification and completion
Synthetic scenarios: multiple categories per company/family/group; missing classification; suggested versus validated; inactive; No aplica; sparse profile; several experience entries in one row; unauthorized user; cross-company drill-down; identical filter outcomes in all formats.
Test filtered totals, distinct rollups, safe errors, protected exports and screen/export parity.
Measure representative page/PDF timings and query/memory costs against an agreed budget before claiming performance.
Update user manual and admin/operator help in every behaviour-changing round.
Return one READY_FOR_REVIEW — MANUAL comment per assigned MGT task with base/tested/review SHA, KPI contracts, expected/actual counts, access tests, UX/export evidence, performance, manual, limitations and DEPLOYED: NO.
Do not begin now; security is the only active programme.
