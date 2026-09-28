# 002 · Para el orquestador — cuerpo del PR de Fase C (creación bloqueada, ver nota)

**Fecha:** 2026-09-28
**Estado del PR:** **BLOQUEADO** — no se pudo crear vía API. El PAT guardado en el credential
manager de esta máquina no tiene el scope `pull_requests:write`
(`403 Resource not accessible by personal access token` al intentar
`POST /repos/estebanjvasquez/PerfilAfiliadosCPV/pulls`). No se intentó escalar el permiso por cuenta
propia — el token se usó solo para este intento y se borró inmediatamente después, nunca se imprimió.

**Acción pendiente de Esteban:** crear el PR a mano en GitHub (o dar un token con ese scope) con:
- **Base:** `main`
- **Head:** `feature/upgrade-filament-v3`
- **Draft:** sí
- **Título:** `Phase C: --apply for Canonical Concept Builder + CIRA chat_memory audit`
- **Cuerpo:** el contenido completo de este archivo a partir de la línea `# Phase` de abajo.

Ambos repos ya están commiteados y pusheados (ver `ESTADO.md`) — el PR es solo el "sobre" de
revisión, el código ya está en `origin`.

---

# Phase

Phase C — `--apply` for the Canonical Concept Builder, plus a CIRA (`chat_memory`) intent-pipeline audit and one pre-existing flaky-test fix.

## Status

READY_FOR_REVIEW

## Baseline

Branch: `feature/upgrade-filament-v3`
Previous HEAD: `cf100e2b3b2df5011e53f5922ebfff8dcb30fccd`
Current HEAD: `20bf6ebfcb520c30a299b4f0485a69a28742bb11`

## Scope

**1. Phase C (`--apply`) implemented.** `app/Services/Taxonomy/CanonicalConceptApplyService.php` (new) + `app/Console/Commands/BuildTaxonomyCanonicalConcepts.php` (`--apply` unblocked, `--max-writes` option added).

Design decision: `--apply` materializes proposals into the **review queues only**, never publishes. It writes `taxonomy_candidate_concept_links` (`status=pending`) and `taxonomy_concept_relations` (`status=candidate`) — it **never** writes `taxonomy_term_concepts`. Publishing stays exclusively the job of `CandidateConceptApprovalService::approve()`, triggered by a human in Filament. This implements `docs/task.md` §15.5 ("never bulk-apply") literally. A candidate with tier `AUTO_ACCEPT` is still only enqueued, never auto-published — there's a dedicated test for that property.

6 safety properties, one per test: single transaction · explicit idempotency (the table has no unique index on the pair, and a pair a human already rejected is never re-queued) · stale-fingerprint guard (aborts if the concept graph changed between dry-run and write) · write cap (`--max-writes`, default 500) · per-row audit log (`actor_type=system` + `algorithm_version`) · provenance stamping (`taxonomy_state_fingerprint`). Plus: protected-table verification inside the same transaction — throws and rolls back everything if `taxonomy_term_cpv_relations` / `taxonomy_term_concepts` / `taxonomy_canonical_concepts` change.

Closes the two gaps Phase B.1 left documented (`audit/phase3_phase_b1_review_workflow.md` §16): the producer of `taxonomy_state_fingerprint` and `resolver_version`.

**2. CIRA (`chat_memory`) intent-pipeline audit.** `audit/chat_memory_intent_audit_2026-09-28.md` + supporting JSON datasets. Extracted a manageable eval dataset from an 11,952-row `chat_memory` export and traced the actual deployed n8n workflow + Worker source to verify findings against the real system, correcting two initial false-bug conclusions along the way (see "Known risks" / process note below).

**3. Pre-existing flaky test fixed.** `TaxonomyCategoriesRelationManagerTest` sorted its fixture's category code from `CPV-10..CPV-98`, a range real CPV taxonomy data already occupies up to `CPV-48` (39 of 89 values) — ~43.8% collision probability per run, confirmed by re-running it twice (failed with `CPV-31`, then `CPV-20`). Moved the fixture to the verified-empty `CPV-900..CPV-999` range. Unrelated to Phase C, found while running the full taxonomy suite.

## Safety invariants

- `--apply` never writes `taxonomy_term_concepts` under any circumstance.
- `taxonomy_term_cpv_relations` (9,749 rows), `taxonomy_canonical_concepts`, `taxonomy_term_concepts` are verified unchanged inside the same transaction as any `--apply` write; any drift throws and rolls back the whole transaction.
- Re-running `--apply` with the same or overlapping input is a no-op for already-queued pairs (verified against production, not just tests).
- A plan whose write count exceeds `--max-writes` aborts before touching the database.

## Tests

```
php artisan test --filter=CanonicalConceptApplyServiceTest
  14 passed (41 assertions)

php artisan test --filter=Taxonomy   (full taxonomy suite, post de-flake fix)
  140 passed
```

Both runs use `DatabaseTransactions` on the `pgsql` connection — nothing persists from test runs.

## Database before/after

**First real `--apply` run** (`--limit=10 --max-writes=50 --skip-audit`), verified with direct queries, not the command's own report:

```
taxonomy_candidate_concept_links     0 →  10   (10 new-concept proposals, all status=pending)
taxonomy_concept_relations           0 →   2   (status=candidate, none approved)
taxonomy_term_concepts             142 → 142   PROTECTED - unchanged
taxonomy_canonical_concepts         79 →  79   PROTECTED - unchanged
taxonomy_term_cpv_relations       9749 → 9749  PROTECTED - unchanged
```

Fingerprint stamped on all 10 rows: `c80a040c46d632bcfb1ba9cc488716b30f3247d12ec278e8bfc8c065b8951acc`
`taxonomy_audit_log`: 12 rows, all `actor_type=system`, `algorithm_version=canonical-concept-builder/phase-c-v1`.

**Idempotency re-verified against production**: an identical second run created 0 rows, skipped all 10.

## Production mutations

**Created (candidate/review-queue only — nothing published):**
- 10 rows in `taxonomy_candidate_concept_links` (`status=pending`)
- 2 rows in `taxonomy_concept_relations` (`status=candidate`)
- 12 rows in `taxonomy_audit_log`

**Modified:** none.
**Deleted:** none.
**Published taxonomy state:** unchanged (0 mutations to `taxonomy_term_concepts` / `taxonomy_canonical_concepts` / `taxonomy_term_cpv_relations`).

## Cross-repository change

This phase also changes the sibling **perfilafiliados-mcp** repo:

- Repository: `perfilafiliados-mcp`
- Branch: `master`
- Commit SHA: `29de993c12fbdadf0577c3630cc07611af020a46`
- Reason: the CIRA audit found that `matched_via` (shown to end users) omits active structured filters (`sector`/`ciudad`/`categoria_codigo`) in the hybrid search path, making correct results look irrelevant — e.g. a state-filtered search for "zulia" showed only `coincide en texto con "empresas"`, hiding that the filter genuinely narrowed to Zulia. `src/empresa-tools.ts` now propagates the same structured-filter label the non-hybrid path already builds.

## Files changed

```
app/Services/Taxonomy/CanonicalConceptApplyService.php        (new)
app/Console/Commands/BuildTaxonomyCanonicalConcepts.php       (--apply unblocked, --max-writes added)
tests/Unit/Taxonomy/CanonicalConceptApplyServiceTest.php      (new, 14 tests)
tests/Feature/Filament/TaxonomyCategoriesRelationManagerTest.php  (de-flake fixture range)
audit/phase3_phase_c_apply.md                                 (new)
audit/chat_memory_intent_audit_2026-09-28.md (+4 supporting JSON)
database/chat_memory_sample.json, chat_memory_intent_eval_sample.json
docs/task.md                                                   (§6quater, §16 updated)
docs/orquestador/  (README.md, ESTADO.md, 001-para-orquestador-handoff-fase-c.md)
```

## Known risks

- **Process note, not a code risk:** this session's CIRA audit reached two false bug conclusions before checking the real deployed workflow and Worker source (see `audit/chat_memory_intent_audit_2026-09-28.md` §7). Both were corrected in the same session, but flagging it explicitly per the source-first rule — any reviewer relying on the audit doc should read §6bis, which supersedes §6.
- `--apply` has only been run once against production, with a small `--limit=10`. Behavior at larger scale (full unlimited run) is untested against real data, though the write-cap and transaction-rollback properties are unit-tested.
- The `docs/orquestador/` folder predates this collaboration protocol and is explicitly marked in its own README as superseded by GitHub as the primary channel — kept as narrative/local complement only.

## Out of scope

- No review/approval of the 10 queued candidates was performed — that's the explicit next human step.
- No unlimited (`--limit` omitted) `--apply` run was attempted.
- No change to the n8n workflow itself — only to the Worker's response labeling.
- No merge to `main`.

## Reviewer instructions

Please independently verify:
1. `CanonicalConceptApplyService::apply()` truly never writes to `taxonomy_term_concepts` — grep for it in the file, there should be zero write references, only reads via the protected-table count check.
2. The 6 safety properties each have a corresponding test in `CanonicalConceptApplyServiceTest.php`, and that the tests assert on real DB state (not mocked).
3. `docs/task.md` §6quater's before/after counts match what's reported here.
4. The `matched_via` fix in the sibling repo's diff is a pure labeling change (no query/filter logic touched).

`EXTERNAL_VERIFICATION_REQUIRED`: the live production `--apply` run and its idempotency re-check happened against Supabase directly from this session (`php artisan tinker`) — GitHub doesn't show that DB state. The before/after counts above are this session's claim; independent confirmation would require Supabase access this PR alone doesn't provide.
