# TASK-0006E — Ciclo de vida SUPERSEDED no destructivo + supersesión autorizada de #420/#421/#422

**Fuente:** Issue #2, comentario
[`5955148859`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5955148859)
(autor `estebanjvasquez`, 2026-10-02T14:58:52Z) — **autorización humana explícita** del dueño de la
taxonomía. Abierta desde HEAD `af4abec`.

**Antecedente:** TASK-0006D quedó `PASS / CLOSED` en el comentario
[`5954835892`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5954835892),
que además dejó registrado el siguiente gate: #420/#421/#422 siguen obsoletas y necesitan un ciclo de
supersesión/re-revisión autorizado aparte antes de cualquier APPLY real.

**Detalle de implementación:**
[`audit/phase6_task0006e_supersession_2026-10-02.md`](../../../audit/phase6_task0006e_supersession_2026-10-02.md).

**Diseño que implementa:**
[`../designs/0006d-stale-proposal-supersession.md`](../designs/0006d-stale-proposal-supersession.md)
(OPCIÓN A, supersesión no destructiva **sin sucesor**).

**Artefacto del preflight posterior:**
[`audit/task0006e_preflight_after_supersession_2026-10-02.json`](../../../audit/task0006e_preflight_after_supersession_2026-10-02.json).

**Referencia de autorización usada en los datos:**
`Issue #2 — explicit owner authorization following orchestrator comment 5954835892`

**TASK-0007 sigue SIN ABRIR. APPLY/PUBLISH NO AUTORIZADO.**

---

## Resultado

| Propuesta | Candidato / término | Antes | Después | Sucesor |
|---|---|---|---|---|
| #420 | 263 / `petroleum` | `PENDING_APPLY` | **`SUPERSEDED`** | ninguno |
| #421 | 264 / `crude oil` | `PENDING_APPLY` | **`SUPERSEDED`** | ninguno |
| #422 | 265 / `oil and gas` | `PENDING_APPLY` | **`SUPERSEDED`** | ninguno |

**0 campos inmutables modificados** en las tres (comparación cruda de la fila completa: 17 campos por
propuesta más el estado del candidato). Candidatos 263/264/265 siguen `pending`, con el slot
`PENDING_APPLY` **libre** y `freezeReview` disponible otra vez.

Conteos protegidos sin cambios: **10 / 2 / 142 / 81 / 9749 / 12 propuestas / 0 aplicadas**; 0 candidatos
publicados, 0 relaciones aprobadas, 0 abortadas. `PENDING_APPLY` 12 → 9, `SUPERSEDED` 0 → 3.

**Delta de estado capturado en las tres:** fingerprint congelado `1d0eb041f642…` → actual
`c236bc5159ae…`, con los dos conceptos creados después de la revisión nombrados — #2890
`oleoducto / oil pipeline` (13:07:11) y #2891 `gasoducto / gas pipeline` (13:07:30). Eso es
exactamente la evidencia que vuelve a abrir la pregunta.

**Preflight posterior:** 9 `READY_TO_APPLY` / 3 `ALREADY_SUPERSEDED` / **0 `NEEDS_REVALIDATION`**,
`write_statements_observed: 0`.

**Tests:** `ReviewedProposalSupersessionTest` 20/20 (nueva, 140 assertions) + 119/119 heredadas de
unidad + `TaxonomyReviewedProposalResourceTest` 12/12 + 2 nuevos de UX de candidatos. Único fallo
local: el gap preexistente de `ext-intl`.

**Staging:** HEAD de runtime `451ba11` (run `success` para ese sha exacto), smoke antes y después de la
transición sin 500/503. Migración aplicada antes de desplegar; el `migrate --force` del deploy es un
no-op.

**STOP respetado:** el agente **no** tomó ninguna decisión semántica nueva sobre `petroleum` /
`crude oil` / `oil and gas`, no congeló ninguna propuesta para 263/264/265 y no preseleccionó nada. La
supersesión es sin sucesor precisamente para que no haya una decisión redactada esperando un clic. El
dueño re-revisará los tres personalmente por Filament contra el grafo actual.

---

## Texto verbatim del comentario que abrió la tarea (`5955148859`)

> [ORCHESTRATOR — EXPLICIT HUMAN AUTHORIZATION — OPEN TASK-0006E / STALE PROPOSAL SUPERSESSION + HUMAN RE-REVIEW GATE]
>
> The taxonomy owner has explicitly authorized the next governed step described in comment `5954835892`.
>
> SCOPE
> Implement the accepted non-destructive SUPERSEDED lifecycle (Option A architecture from TASK-0006D) and execute it ONLY for the three real stale reviewed proposals:
> - #420 → candidate 263 → `petroleum`
> - #421 → candidate 264 → `crude oil`
> - #422 → candidate 265 → `oil and gas`
>
> For these three specific rows, the authorized real-data transition is:
> - SUPERSEDE WITHOUT successor;
> - preserve every original reviewed-proposal row, decision payload, payload fingerprint, taxonomy-state fingerprint, reviewer, reviewed_at and prior audit history;
> - do NOT delete, overwrite, abort or apply them;
> - record supersession provenance/reference/time/reason/channel/actor;
> - release the unique PENDING_APPLY slot so each source candidate can return to the normal human review workflow against the CURRENT taxonomy state.
>
> AUTHORIZATION REFERENCE
> Use:
> `Issue #2 — explicit owner authorization following orchestrator comment 5954835892`
>
> IMPLEMENTATION REQUIREMENTS
> 1. Additive schema only. Implement the accepted lifecycle fields/constraints from the TASK-0006D design, including durable state-delta storage where applicable.
> 2. Add explicit status `SUPERSEDED` (or the exact already-approved equivalent if naming is implemented differently), keeping existing historical statuses semantically intact.
> 3. Supersession must be transactional, idempotent and concurrency-safe.
> 4. Revalidate before transition:
>    - proposal still PENDING_APPLY;
>    - payload fingerprint still valid;
>    - proposal is actually stale against the exact current dryRunInputFingerprint();
>    - source candidate still exists and remains pending;
>    - no APPLY has occurred.
> 5. For #420/#421/#422, supersede WITHOUT creating successor proposals. The intent is to force a genuine new human review from current evidence, not to carry the old decision forward.
> 6. Persist an auditable state delta / change evidence sufficient to explain why the old review became stale. At minimum capture old vs current fingerprint and the decision-relevant taxonomy changes available under the accepted design.
> 7. Filament behavior after supersession:
>    - the three source candidates must again be reviewable through the normal candidate-review UI;
>    - the old superseded proposal must remain visible/read-only with clear lineage/status;
>    - no APPLY/Publish control is introduced.
> 8. Preserve the TASK-0006D UX hardening: a candidate with a live PENDING_APPLY proposal must still not expose a second freeze flow.
> 9. Preserve the TASK-0006D grouped-APPLY protections. No regression to sibling payload validation / whole-group terminal semantics.
> 10. Normalize the previously noted non-blocking `detected_on_proposal_id` diagnostic attribution while touching lifecycle hardening, but do not broaden into unrelated admin redesign.
>
> REAL-DATA EXECUTION AUTHORIZED
> After code/tests/staging validation pass, execute the real supersession ONLY for #420/#421/#422.
> Do NOT supersede any other real proposal.
>
> EXPECTED REAL POST-STATE AFTER SUPERSESSION
> - #420/#421/#422 status = SUPERSEDED;
> - candidates 263/264/265 remain pending and become available for normal human review;
> - no successor proposal exists yet for 263/264/265;
> - #491/#492–#495/#629/#630/#631/#632 unchanged;
> - published taxonomy unchanged:
>   TERM→CONCEPT = 142
>   canonical concepts = 81
>   TERM→CPV = 9749
> - APPLIED reviewed proposals = 0
> - no published candidate/relation changes.
>
> HUMAN RE-REVIEW — STOP BEFORE DOING IT
> After the three supersessions are executed and verified:
> - STOP.
> - The development agent must NOT make the new semantic decisions for petroleum/crude oil/oil and gas.
> - The taxonomy owner will personally re-review those three candidates through Filament against the current concept graph.
> - The owner may choose CONTEXT_REQUIRED again or a different valid decision based on the current evidence. Do not preselect or auto-freeze a decision on the owner's behalf.
>
> TESTS / VALIDATION
> Required fixture coverage:
> - supersession preserves immutable predecessor content;
> - no successor path frees the candidate for re-review;
> - unique PENDING_APPLY invariant still holds;
> - duplicate/replay supersession is idempotent;
> - non-stale proposal cannot be superseded through stale-only flow;
> - grouped proposals cannot be half-superseded;
> - old proposal remains read-only/auditable;
> - new review after supersession can freeze a new PENDING_APPLY proposal while predecessor remains SUPERSEDED;
> - no APPLY/PUBLISH is called;
> - preflight understands SUPERSEDED as terminal historical state, not READY_TO_APPLY;
> - existing C2/confirmation/preflight/group/UX suites remain green.
>
> STAGING
> Deploy only to staging through the existing hardened workflow.
> Run smoke checks and a read-only post-transition recount.
>
> NOT AUTHORIZED
> - APPLY or PUBLISH;
> - creating a successor proposal for #420/#421/#422;
> - re-freezing/reviewing those three on behalf of the owner;
> - changing #491/#492–#495/#629/#630/#631/#632;
> - merge main;
> - production deployment;
> - destructive migration;
> - credential rotation;
> - search/ranking semantic changes.
>
> COMPLETION CONTRACT
> After implementation, staging validation and the narrowly authorized real supersession of #420/#421/#422, STOP and return:
>
> READY_FOR_REVIEW
> Issue #2
> HEAD <exact-sha>
>
> Also include in the audit artifact:
> A) inherited approved gates;
> B) new schema/runtime evidence;
> C) exact before/after state for #420/#421/#422;
> D) proof no successor was created;
> E) proof no published taxonomy mutation occurred;
> F) any gate invalidated by this diff (if none, state none).
>
> TASK-0007 remains UNOPENED and APPLY/PUBLISH remains NOT AUTHORIZED.

## Texto verbatim del PASS de TASK-0006D que habilitó este gate (`5954835892`)

> [ORCHESTRATOR RE-AUDIT — TASK-0006D ROUND 2 — PASS / CLOSED]
>
> Reviewed HEAD `af4abec0db7406381dc29df7807c3def1313ab63` against the correction gate in comment `5952211890`.
>
> VERDICT
> TASK-0006D = PASS / CLOSED.
>
> The grouped-APPLY integrity blocker is closed. TASK-0007 remains UNOPENED because #420/#421/#422 are still legitimately stale and require a separately authorized supersession/re-review lifecycle before any real APPLY.
>
> NEWLY VERIFIED
>
> 1. DIFF / DEPLOYMENT SHAPE
> - `f9caf15` → `af4abec`: 2 commits ahead, 0 behind.
> - Runtime correction is in the first descendant commit (`4eee136...`); the final HEAD adds audit/docs continuity.
> - No migration/schema change occurred in this round.
>
> 2. EVERY PENDING GROUP MEMBER NOW REVALIDATES ITS OWN IMMUTABLE PAYLOAD
> `evaluateBilingualGroup()` now calls `payloadFingerprintIsValid($member)` as the first gate for every non-APPLIED group member, before consuming that member's decision payload or source row.
> - sibling tamper produces `TAMPER_DETECTED`;
> - diagnostic detail includes `tampered_proposal_id`;
> - entering through either sibling yields the same safety classification;
> - the entry row's own payload validity is no longer falsely inferred from a sibling's blocker.
>
> This closes the specific defect from comment `5952211890`: `apply(#629)` can no longer write #630 without revalidating #630's immutable payload under the grouped execution path.
>
> 3. TERMINAL GROUP FAILURE IS NOW ATOMIC AT GROUP LIFECYCLE LEVEL
> `applyOutcomeForBlocker()` keeps `HUMAN_CONFIRMATION_REQUIRED` non-terminal.
> For all other terminal blockers on a grouped proposal it routes to `abortWholeGroup()`, which:
> - serializes on the existing group advisory lock;
> - locks all still-PENDING_APPLY members;
> - transitions every still-pending member to the same terminal ABORTED outcome in the same transaction;
> - emits an execution-audit row per member;
> - leaves no silent PENDING_APPLY sibling stranded after group failure.
>
> The directed fixture tests cover:
> - tampered sibling entered through healthy sibling;
> - same damaged group entered from either sibling;
> - zero taxonomy publication on tamper;
> - all pending group members terminal together;
> - HUMAN_CONFIRMATION_REQUIRED remains non-terminal;
> - inherited group source-drift / idempotency / one-concept / advisory-lock behavior remains green.
>
> 4. PREFLIGHT GROUP EVIDENCE
> The regenerated real preflight artifact is read-only (`write_statements_observed = 0`) and now exposes group payload integrity per member.
>
> For the real bilingual group:
> - #629 → READY_TO_APPLY snapshot
> - #630 → READY_TO_APPLY snapshot
> - member ids = [629, 630]
> - member_payload_fingerprint_valid = {629:true, 630:true}
> - tampered_member_ids = []
> - all_member_payloads_valid = true
> - group remains internally consistent.
>
> 5. REAL 12-PROPOSAL CLASSIFICATION — UNCHANGED
> Read-only artifact generated 2026-10-02 13:55:32:
> - NEEDS_REVALIDATION = 3: #420, #421, #422 — STALE_TAXONOMY_STATE.
> - READY_TO_APPLY snapshot = 9: #491, #492–#495, #629/#630, #631/#632.
> - BLOCKED_FOR_OTHER_REASON = 0.
> - all three stale rows still have valid payload fingerprints.
>
> 6. LIVE DATABASE RECOUNT — DIRECT READ-ONLY VERIFICATION
> During this audit I re-read the shared Supabase state:
> - taxonomy_candidate_concept_links = 10; all 10 pending
> - taxonomy_concept_relations = 2; both candidate
> - taxonomy_term_concepts = 142
> - taxonomy_canonical_concepts = 81
> - taxonomy_term_cpv_relations = 9749
> - taxonomy_reviewed_proposals = 12
> - PENDING_APPLY = 12
> - APPLIED = 0
> - ABORTED = 0
>
> No real APPLY, supersession, abort, publication or candidate/relation resolution occurred.
>
> 7. STALE-SUPERSESSION DESIGN CONSISTENCY
> The design now includes durable `supersession_state_delta JSONB` (or explicitly equivalent durable storage) and states the required integrity rule: a successor carrying inherited decision content must not exist without a persisted state delta. This closes the design inconsistency from comment `5952211890`.
>
> INHERITED APPROVED EVIDENCE
> - TASK-0001/C1 APPROVED.
> - TASK-0002 APPROVED.
> - TASK-0003 APPROVED.
> - TASK-0004/C2 APPROVED.
> - TASK-0005 APPROVED.
> - TASK-0006A/B/C APPROVED.
> - TASK-0006D round-1 read-only preflight, candidate UX hardening, and stale design remain accepted.
> - frozen 32-query search regression remains inherited; this work did not change published search/ranking semantics.
> - no production mutation and no main merge.
>
> NON-BLOCKING AUDIT-ATTRIBUTION CLEANUP
> `abortWholeGroup()` records `detected_on_proposal_id` exactly for tamper because that blocker carries `tampered_proposal_id`. Some other sibling blockers identify the offending source/member in their diagnostic note/detail but do not consistently provide a normalized offending-proposal-id field, so that convenience field may fall back to the entry proposal. This does NOT weaken the atomic safety behavior or the current real queue, and is not a TASK-0006D blocker. Normalize this diagnostic field in the next lifecycle-hardening code round before general rollout.
>
> NEXT GATE — DO NOT OPEN TASK-0007 YET
> #420/#421/#422 remain stale. The accepted treatment for these real rows is:
> 1. implement the governed non-destructive SUPERSEDED lifecycle (Option A architecture);
> 2. for these three specifically, use supersession WITHOUT successor;
> 3. preserve old rows/payload/fingerprints/audit history;
> 4. after supersession, the owner re-reviews `petroleum`, `crude oil`, and `oil and gas` against the current taxonomy through the normal Filament candidate review UI;
> 5. run the read-only preflight again;
> 6. only when the live queue has no stale/blocking proposal may TASK-0007 be opened behind a fresh explicit APPLY authorization.
>
> No schema change or real supersession of #420/#421/#422 is authorized by this PASS itself.
>
> Repository continuity only may record TASK-0006D CLOSED/PASS. Do not start the supersession implementation or mutate the three real proposals until a new explicit owner authorization is posted.
