# TASK-0006B — Confirmación humana C2 + `CREATE_NEW` bilingüe

**Fuente:** Issue #2, comentario
[`5936206843`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5936206843)
(autor `estebanjvasquez`, 2026-10-01T16:52:04Z). Abierta desde HEAD `42d3c6b`.

**Antecedente:** el re-audit
[`5934324928`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5934324928)
dejó TASK-0006 en `CORRECTIONS_REQUIRED` y pidió reportar el hueco de workflow si C2 no podía
registrar la confirmación humana de una propuesta ya congelada sin borrarla ni re-congelarla. Se
reportó (sección 10.3 de `audit/phase6_task0006_queue_review_2026-10-01.md`), y este comentario trae
las decisiones humanas del dueño de la taxonomía más la elección de la **opción A** (capa aditiva con
`confirm()`).

**Detalle de implementación:**
[`audit/phase6_task0006b_human_confirmation_2026-10-01.md`](../../../audit/phase6_task0006b_human_confirmation_2026-10-01.md).

## Texto verbatim del comentario `5936206843`

> [ORCHESTRATOR — HUMAN GOVERNANCE DECISIONS + OPEN TASK-0006B / C2 HUMAN CONFIRMATION + BILINGUAL CREATE_NEW]
>
> HUMAN GOVERNANCE DECISIONS — AUTHORITATIVE
> The taxonomy owner has now explicitly decided:
>
> 1. Existing agent-prepared frozen proposals for candidates 266–269:
> - 266 `exploration` → CONFIRM `CONTEXT_REQUIRED`
> - 267 `upstream` → CONFIRM `CONTEXT_REQUIRED`
> - 268 `midstream` → CONFIRM `CONTEXT_REQUIRED`
> - 269 `downstream` → CONFIRM `CONTEXT_REQUIRED`
>
> Recording decision: implement an enforceable C2 HUMAN CONFIRMATION mechanism. Do NOT delete/re-freeze #492–#495 and do not rely on documentation-only or audit-log-only confirmation.
>
> 2. Candidates 270/271:
> - one canonical bilingual concept;
> - canonical ES = `refinería`
> - canonical EN = `refinery`
> - approved design direction = OPTION A: extend C2 `CREATE_NEW` so the immutable reviewed proposal can explicitly freeze both canonical names and ultimately converge the bilingual terms on one canonical concept.
>
> 3. Candidate concept relations:
> - relation #61 `production → production casing` → HUMAN VERDICT: REJECT
> - relation #62 `oil → oil-base mud` → HUMAN VERDICT: REJECT
>
> IMPORTANT
> These are human governance decisions. They authorize implementation of the workflow needed to record them safely. They do NOT authorize APPLY/publication.
>
> CURRENT LIVE STATE TO PRESERVE
> Before any implementation/write, verify and record the actual state. Expected from accepted audit:
> - candidate links: 10
> - candidate relations: 2
> - taxonomy_term_concepts: 142
> - canonical concepts: 81
> - TERM→CPV: 9749
> - reviewed proposals: 8
> - applied proposals: 0
> - #420/#421/#422/#491 = protected human-reviewed proposals
> - #492/#493/#494/#495 = frozen agent-prepared proposals awaiting human confirmation
> - 270/271 = no frozen proposal
> - relations 61/62 = no frozen proposal.
>
> TASK-0006B OBJECTIVE
> Implement the minimum governed C2 extensions required to represent the human decisions above without weakening immutability, attribution, idempotency, stale-state protection, or the separation between REVIEW/FREEZE/CONFIRM and APPLY.
>
> A. ENFORCEABLE HUMAN CONFIRMATION
> Implement an additive confirmation layer for an already-frozen proposal.
>
> Minimum requirements:
> - additive schema only; no destructive migration;
> - structurally record confirmation separately from original reviewer/preparer identity, e.g. `confirmed_by_id`, `confirmed_at`, and any narrowly necessary confirmation provenance/reference;
> - confirmation MUST NOT rewrite `reviewer_id`, `reviewed_at`, `decision_payload`, source snapshot, payload fingerprint, taxonomy-state fingerprint, or original preparation provenance;
> - implement a dedicated service operation such as `confirm()`; do not hand-edit rows;
> - confirmation must be idempotent for the same human confirmation and concurrency-safe;
> - only an authenticated/authorized HUMAN reviewer may confirm;
> - do not allow an agent/service actor to masquerade as a human confirmer;
> - append an auditable event clearly distinguishing PREPARED/FROZEN from HUMAN_CONFIRMED;
> - `apply()` must enforce human confirmation for proposals that require it. Do not make every historical legitimate human-frozen proposal suddenly invalid if its original review provenance already satisfies the human-review requirement; define/document the exact compatibility rule.
> - proposals #492–#495 must be marked/recognized as requiring confirmation without mutating their immutable decision payload/fingerprint.
>
> Because the current rows did not structurally encode “agent prepared” except contradictory attribution/free text, design the smallest explicit migration/backfill strategy needed for #492–#495. Any targeted data backfill MUST be deterministic, auditable and limited to identifying confirmation requirement; do not alter the decision itself. If the safest implementation requires a new explicit boolean/status such as `requires_human_confirmation`, document why and protect it from casual mutation.
>
> B. FILAMENT CONFIRMATION UX
> Expose a clear human-only action for proposals awaiting confirmation:
> - show original decision and evidence;
> - show original preparer/reviewer provenance without pretending the account holder made the agent-prepared decision;
> - action text must communicate “Confirmar decisión preparada” (or equivalent), not APPLY/Publish;
> - require deliberate confirmation; optional/required confirmation note only if useful for governance;
> - after confirmation show confirmer + timestamp;
> - no APPLY button;
> - source candidate remains unpublished.
>
> For #492–#495, the human decision supplied above authorizes recording confirmation through this new mechanism once implementation/tests are accepted enough to execute the controlled real confirmation step in this task. Use the real human reviewer identity/account through the normal governed path. Do not attribute confirmation to Claude Code.
>
> C. BILINGUAL CREATE_NEW — IMMUTABLE PAYLOAD
> Extend CREATE_NEW so the reviewed immutable proposal can explicitly carry:
> - `canonical_name_es`
> - `canonical_name_en`
>
> Requirements:
> - both fields explicit for this bilingual case; no implicit translation/fallback;
> - preserve source suggested name separately as evidence;
> - payload fingerprint covers both names;
> - tamper/stale protections cover the new payload;
> - validation prevents empty/invalid bilingual identity;
> - do not overload `canonical_name_es` with English or vice versa;
> - backward compatibility for already-frozen historical CREATE_NEW payloads must be explicit and tested.
>
> D. ONE CONCEPT FOR 270 + 271 — DESIGN THE GOVERNED CONVERGENCE
> Do NOT freeze two independent CREATE_NEW proposals that could create duplicate concepts.
>
> Implement the minimum C2 design that guarantees:
> - one new canonical concept with ES=`refinería`, EN=`refinery`;
> - both source terms 270 and 271 can ultimately map to that SAME concept;
> - no APPLY is required merely to make the second review expressible;
> - the immutable review state identifies all governed source candidates/terms participating in the bilingual creation, or provides an equivalent deterministic linkage;
> - APPLY, when separately authorized in a future task, can transactionally create one concept and attach both approved term identities without a duplicate race;
> - idempotency/concurrency protection prevents two concepts being created from the pair;
> - stale/source drift is checked for BOTH source candidates;
> - no direct CPV mapping is invented by this operation.
>
> Prefer a grouped/linked reviewed proposal design if it cleanly fits C2. Do not introduce a general many-to-many redesign beyond what is required for governed bilingual identity. If implementation would require changing search semantics/cardinality, STOP; that is not authorized.
>
> E. RELATIONS 61/62 — RECORD HUMAN REJECTION, FREEZE ONLY
> The owner has explicitly rejected both relations.
>
> After the review workflow is ready:
> - freeze relation #61 as REJECT;
> - freeze relation #62 as REJECT;
> - use the normal C2 relation review path and human reviewer attribution;
> - include a concise structured reason consistent with the evidence: lexical/compositional relationship is insufficient for a governed `RELATED_TO` edge;
> - do not delete relation source rows;
> - do not publish anything;
> - no APPLY.
>
> F. CONTROLLED REAL WRITES AUTHORIZED IN TASK-0006B
> After implementation and safe tests, this task authorizes ONLY these real review/governance writes:
> 1. record HUMAN CONFIRMATION of #492–#495 using the new confirmation mechanism;
> 2. freeze the governed bilingual 270/271 review for one ES/EN concept, using the new safe design;
> 3. freeze REJECT decisions for relation #61 and #62.
>
> No other real queue decisions/writes are authorized.
> NO APPLY.
> NO publication.
>
> Before each real write:
> - verify source identity/state;
> - verify no conflicting proposal/confirmation;
> - verify current taxonomy/input fingerprint;
> - use normal service/UI governed path, never direct SQL for the decision itself;
> - on stale/tamper/guard failure STOP rather than weakening the guard.
>
> G. TEST GATES
> Use disposable fixtures for implementation tests.
>
> Minimum:
> - confirmation fields/provenance additive and immutable payload unchanged;
> - unauthorized/non-human confirmation rejected;
> - confirmation idempotency + concurrency;
> - apply rejects agent-prepared/unconfirmed proposal;
> - apply accepts confirmation gate structurally when all other guards pass, WITHOUT executing a real APPLY;
> - existing legitimate human-reviewed historical proposals remain compatible;
> - Filament confirmation action authorization/visibility/no APPLY;
> - bilingual CREATE_NEW freezes exact ES+EN;
> - source suggested name remains evidence only;
> - payload tamper detection includes both names;
> - grouped/linked 270+271 source drift on either source aborts;
> - one-concept idempotency/concurrency design;
> - no duplicate bilingual concept creation;
> - relation REJECT freeze path;
> - existing C2/TASK-0005/TASK-0006A authorization and nested-signals regressions remain protected.
>
> No unsafe full-suite execution using live staging bind mounts.
>
> H. SEARCH REGRESSION / SEMANTICS
> This task must not change search/ranking consumers or published taxonomy semantics. Therefore the accepted 32-query regression remains inherited unless the diff actually touches those semantics.
>
> If implementation requires a search/cardinality semantic change, STOP and request a new gate.
>
> I. STAGING / DEPLOYMENT
> Runtime/schema changes may deploy to STAGING only through the hardened workflow.
> - additive migrations only;
> - verify exact deployed runtime HEAD;
> - smoke `/`, `/admin/login`, candidate/relation/reviewed-proposal pages;
> - no 500/503;
> - execute the authorized real review writes only after deployment/guards are healthy;
> - re-read all affected rows after writes.
>
> J. EXPECTED POST-STATE
> Exact reviewed-proposal count may depend on the approved grouped bilingual implementation, so do not invent it in advance.
>
> Required semantic post-state:
> - 266–269: #492–#495 retain original immutable decisions and are structurally HUMAN_CONFIRMED by the human;
> - 270/271: one frozen governed bilingual CREATE_NEW review targeting ONE future concept ES `refinería` / EN `refinery`; still unapplied;
> - relations 61/62: frozen REJECT reviews; still unpublished;
> - published `taxonomy_term_concepts` remains 142;
> - canonical concepts remains 81 because CREATE_NEW is only frozen, not applied;
> - TERM→CPV remains 9749;
> - applied proposals remains 0;
> - no source relation/candidate is falsely treated as published.
>
> K. AUDIT / HANDOFF
> Update Issue #2 supporting docs, `docs/orquestador/current_task.md`, `audit/orchestrator_handoff.json`, and focused TASK-0006B audit.
>
> Record:
> - migration/schema changes;
> - exact compatibility rule for old human-frozen proposals;
> - confirmation model and attribution;
> - bilingual grouped design;
> - tests;
> - staging deployed HEAD/smoke;
> - every authorized real write and resulting proposal/confirmation IDs;
> - before/after counts;
> - inherited gates/invalidation;
> - explicit NO APPLY / NO publication.
>
> STOP CONDITIONS
> STOP before any unauthorized action requiring:
> - APPLY/publication;
> - production deploy;
> - merge main;
> - destructive migration;
> - credential rotation;
> - search/ranking semantic changes;
> - deletion/re-freeze of #492–#495;
> - unrelated real queue processing.
>
> COMPLETION CONTRACT
> After implementation, staging validation, and ONLY the controlled review writes authorized above, STOP and return:
>
> READY_FOR_REVIEW
> Issue #2
> HEAD <exact-sha>
>
> TASK-0007 remains unopened until this task is separately audited and approved.
