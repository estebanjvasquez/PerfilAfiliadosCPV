# TASK-0006A — Explorador de conceptos para revisión humana + diagnóstico de mapeo

**Fuente:** Issue #2, comentario [`5929287629`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5929287629)
(autor `estebanjvasquez`, 2026-10-01T10:12:12Z).

**Contexto:** TASK-0006 (revisión humana de la cola real) está EN CURSO y quedó pausada para los
candidatos sin resolver, porque el revisor encontró una limitación real de UX/diagnóstico mientras
revisaba el término `pipeline`. TASK-0005 quedó `CLOSED/APPROVED` (comentario `5928773263`).

**Precondición verificada (sección E):** las 3 revisiones humanas ya congeladas se preservan
exactamente como están. Ver `audit/phase6_task0006a_concept_explorer_2026-10-01.md` para el estado
completo verificado antes de implementar.

## Texto verbatim del comentario `5929287629`

> [ORCHESTRATOR — OPEN TASK-0006A / HUMAN-REVIEW CONCEPT EXPLORER + MAPPING DIAGNOSTICS]
>
> CONTEXT / REASON
> TASK-0006 is IN PROGRESS and is temporarily PAUSED for the unresolved queue items because the reviewer found a real UX/diagnostic limitation while reviewing the term `pipeline`.
>
> Three of the original ten term candidates have already been reviewed by the human through the approved C2 UI as generic/context-dependent. Preserve those frozen reviewed proposals exactly as they are. DO NOT APPLY them and do not alter/recreate/delete them.
>
> The remaining queue must not be forced through an incomplete selector.
>
> OBSERVED PROBLEM
> In `TaxonomyCandidateConceptLinkResource::freezeReviewForm()`, MAP_TO_EXISTING initially populates the selector from `findPossibleDuplicateConcepts()` plus the Builder-suggested concept. Remote search then queries `taxonomy_canonical_concepts`, but limits results to 20 and exposes only the concept display name.
>
> For a broad/polysemous petroleum term such as `pipeline`, the reviewer cannot reliably discover and evaluate all plausible canonical concepts and their downstream CPV/category consequences. The current UI can therefore create false confidence that the few visible choices are the only valid mappings.
>
> IMPORTANT SEMANTIC BOUNDARY
> MAP_TO_EXISTING means TERM → CANONICAL CONCEPT. It does NOT mean “select every CPV category containing a similar word”.
>
> Do not redesign this task into direct TERM→CPV multi-select. The canonical-concept layer remains authoritative.
>
> A broad/polysemous term may legitimately require CONTEXT_REQUIRED instead of one unconditional concept mapping. The UI must help the reviewer make that determination; it must not automatically fan the term out to every superficially related CPV category.
>
> OBJECTIVE
> Improve the human-review UI so the reviewer can discover, inspect and compare the complete relevant canonical-concept space and understand the CPV/category/search impact of a proposed MAP_TO_EXISTING decision before freezing it.
>
> A. CONCEPT DISCOVERY
> 1. Remove the practical “only a few choices” limitation.
> 2. The selector/search must permit discovery across ALL active canonical concepts, not only Builder duplicate suggestions.
> 3. Do not rely on an arbitrary top-20 truncation that can hide valid concepts. Implement safe pagination/expanded search or another Filament-compatible approach that makes the full active concept set discoverable without loading an unbounded dataset into the browser.
> 4. Search at minimum Spanish and English canonical names. Reuse existing aliases/term/concept evidence where architecturally appropriate if it improves discovery without changing taxonomy semantics.
> 5. Builder suggestions/possible duplicates should remain highlighted as evidence/recommendations, but clearly distinct from the full searchable concept catalogue.
> 6. No hardcoding for `pipeline` or any benchmark term.
>
> B. CONCEPT / CPV DIAGNOSTIC PANEL
> When a reviewer selects or inspects a canonical concept, show enough read-only evidence to understand what that concept means and what mapping to it would imply. At minimum:
> - canonical concept identity/name(s);
> - relevant aliases/terms where available;
> - current approved TERM→CONCEPT evidence/count;
> - CPV categories reachable/associated through the existing approved taxonomy semantics;
> - useful GROUP / FAMILY / CATEGORY labels/codes where available;
> - predicted affected-company summary already supported by the Builder;
> - evidence/provenance indicators available in the existing model;
> - warnings for data gaps where applicable.
>
> Do not invent relationships. Everything shown must come from existing governed data.
>
> If there are many CPV categories, provide a searchable/collapsible/paginated diagnostic representation rather than silently truncating them.
>
> C. POLYSEMY / CONTEXT GUIDANCE
> The UI must make the distinction explicit:
> - MAP_TO_EXISTING = this term has one sufficiently specific unconditional canonical meaning for this taxonomy use;
> - CONTEXT_REQUIRED = the term is valid but too broad/polysemous/ambiguous to map unconditionally without surrounding context.
>
> For CONTEXT_REQUIRED:
> - preserve the existing required `context_reason`;
> - optionally show candidate concepts/categories as diagnostic evidence, but do NOT create mappings to them;
> - do not claim CONTEXT_REQUIRED is already consumed by the search runtime if no consumer exists.
>
> Do not introduce an automatic rule that broad terms are always CONTEXT_REQUIRED. Final decision remains human.
>
> D. MULTI-CONCEPT QUESTION — DESIGN BEFORE IMPLEMENTING
> Do NOT silently change the cardinality of TERM→CONCEPT.
>
> Audit the current schema/services/search consumers and document whether a single term is architecturally allowed to have multiple approved canonical-concept mappings and, if so, how context/disambiguation is represented and consumed.
>
> If current architecture safely supports multiple mappings and search semantics can disambiguate them, document the exact existing behavior before proposing any UI support.
>
> If it does NOT safely support this, keep TASK-0006A UI single-concept MAP_TO_EXISTING + CONTEXT_REQUIRED and record multi-concept contextual mapping as a future design task. Do not add a multi-select merely to satisfy `pipeline`.
>
> Any change to mapping cardinality/search semantics is OUT OF SCOPE and requires a new orchestrator gate because it would invalidate inherited search-regression evidence.
>
> E. PRESERVE EXISTING C2 REVIEW STATE
> Before implementation verify and record:
> - original candidate source rows still exist;
> - exactly which 3 candidate sources now have frozen reviewed proposals;
> - their decisions/statuses/fingerprints;
> - no APPLY occurred;
> - remaining candidate/relation review state;
> - published-taxonomy invariants.
>
> Do not expose private payload/secrets in docs.
>
> The 3 already-frozen human decisions are protected review records. No code/test/deployment step may mutate/delete/re-freeze them.
>
> F. TESTS
> Use disposable fixtures/transactions only.
>
> Minimum coverage:
> - full active concept catalogue is discoverable through the review control, beyond the original duplicate suggestions;
> - a concept outside the first/default suggestion set can be found and selected;
> - >20 matching concepts cannot cause silent loss of valid options;
> - ES/EN canonical-name search;
> - diagnostic panel displays governed CPV associations for selected concept;
> - large association set is not silently truncated;
> - MAP_TO_EXISTING still freezes exactly one explicit target concept;
> - CONTEXT_REQUIRED still freezes zero mappings/concepts;
> - CREATE_NEW and REJECT remain unchanged;
> - no APPLY/Publish UI introduced;
> - TASK-0002 nested-signals regression remains protected;
> - existing frozen proposals remain idempotently protected;
> - authorization boundaries from TASK-0005 remain intact.
>
> Do not weaken existing assertions to manufacture green.
>
> G. STAGING / DEPLOYMENT
> Runtime UI changes may deploy to existing staging only through the hardened workflow.
>
> After deployment:
> - verify exact runtime HEAD;
> - smoke `/`, `/admin/login`, candidate review list/detail/action;
> - verify concept discovery using read-only interaction;
> - demonstrate that a concept outside the original suggestion subset is discoverable;
> - verify diagnostic CPV information renders without 500/503;
> - DO NOT make a new real review decision merely to test the UI;
> - do not touch the 3 frozen proposals;
> - do not process the remaining real queue as part of implementation validation.
>
> Use no unsafe full-suite procedure against live shared bind mounts.
>
> H. REGRESSION / INHERITED GATES
> If implementation is strictly admin UI/read-only diagnostics and does not alter search, published taxonomy, mapping cardinality or search consumers:
> - 32-query regression remains inherited and no rerun is required.
> If any search/mapping semantic change becomes necessary, STOP and request a new gate before implementing it.
>
> Published-taxonomy invariants must remain unchanged from the accepted baseline:
> - taxonomy_term_concepts = 142
> - taxonomy_canonical_concepts = 79
> - taxonomy_term_cpv_relations = 9749
>
> Candidate/reviewed-proposal counts must be recorded from the current live state, not assumed to still be 10/2/.../0 because the human has now legitimately frozen 3 decisions during TASK-0006.
>
> I. ADJACENT RISK FROM TASK-0005
> Do not broaden this task to the previously recorded risk concerning editing already-approved concept relations. Keep it documented for a future governance-hardening task unless this UI work directly depends on it.
>
> J. DOCUMENTATION / HANDOFF
> Update:
> - `docs/orquestador/current_task.md`
> - create `docs/orquestador/tasks/0006a-concept-explorer.md`
> - `audit/orchestrator_handoff.json`
> - focused TASK-0006A audit artifact.
>
> Record:
> - exact base/runtime/final HEADs;
> - current real queue state including the 3 protected frozen reviews;
> - architecture finding on single vs multiple TERM→CONCEPT mappings;
> - changed files;
> - tests;
> - staging deployment/smoke;
> - before/after protected counts;
> - inherited gates and invalidation analysis;
> - confirmation that no APPLY/publication occurred.
>
> STOP CONDITIONS
> STOP and report rather than proceed if work would require:
> - changing TERM→CONCEPT cardinality;
> - changing search/ranking semantics;
> - applying/publishing any reviewed proposal;
> - modifying/deleting the 3 frozen human reviews;
> - processing additional real queue decisions;
> - destructive migration;
> - production deployment;
> - merge to main;
> - token/credential rotation.
>
> COMPLETION CONTRACT
> When TASK-0006A implementation, safe tests and staging validation are complete, STOP and respond only:
>
> READY_FOR_REVIEW
> Issue #2
> HEAD <exact-sha>
>
> After orchestrator approval, TASK-0006 resumes and the human can continue reviewing the remaining candidates using the improved UI. TASK-0007 APPLY remains unopened and unauthorized.

## Re-audit — comentario `5930560603` (CORRECTIONS_REQUIRED)

**Fuente:** Issue #2, comentario [`5930560603`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5930560603),
autor `estebanjvasquez`, 2026-10-01T11:40:42Z. HEAD revisado: `b0d2d20d054e4319e314ee2049e38c87b0244014`.

### Texto verbatim

> [ORCHESTRATOR RE-AUDIT — TASK-0006A — CORRECTIONS_REQUIRED]
>
> Reviewed HEAD `b0d2d20d054e4319e314ee2049e38c87b0244014` against TASK-0006A contract in comment `5929287629` and the accepted TASK-0005/TASK-0006 state.
>
> ACCEPTED
> - Scope discipline is correct: no APPLY/publication, no search/ranking/cardinality change, no migration, no production/main/token work.
> - The 3 real frozen human reviews are preserved as protected C2 records; published taxonomy remains 142 / 79 / 9749 and live queue remains 10 candidates / 2 relations / 3 reviewed proposals.
> - `ConceptExplorerService` is read-only and searches active canonical concepts by ES/EN canonical name, member term and alias; merged concepts are excluded.
> - Builder suggestions remain evidence rather than an implicit target.
> - Concept diagnostics use governed data and expose canonical identity, member terms/aliases, approved CPV reachability, hierarchy/breadcrumbs, predicted company impact and data-gap warnings.
> - MAP_TO_EXISTING remains exactly one explicit canonical concept; CONTEXT_REQUIRED remains zero mappings and the inspection concept is not frozen into its payload.
> - Architecture analysis correctly finds that the physical pivot permits N TERM→CONCEPT rows but the current search consumer has no per-mapping contextual disambiguation, so multi-concept UI/search semantics were correctly NOT introduced.
> - Focused tests/deployment evidence is otherwise acceptable; known local ext-intl failure remains inherited and staging runtime is healthy.
> - 32-query regression remains inherited because no published/search semantics changed.
>
> BLOCKER — A.3 / B NOT FULLY SATISFIED IN THE ACTUAL FILAMENT UI
> The service now returns `total/shown/truncated`, but both real Filament controls call only:
> `$explorer->searchOptions($search)`
> for `target_concept_id` and `inspect_concept_id`.
>
> `searchOptions()` discards `total` and `truncated`. Therefore the reviewer receives at most 50 options with NO UI indication that more matches exist and no pagination/load-more mechanism. The audit statement that "the UI can tell the reviewer how many matches exist and ask to refine" is not implemented in the actual control.
>
> This matters because TASK-0006A explicitly required:
> - no arbitrary truncation that can hide valid concepts;
> - safe pagination/expanded search or equivalent complete discovery;
> - if many results exist, no silent truncation.
>
> The new tests verify the SERVICE reports overflow, but they do not prove the FILAMENT UI surfaces that overflow. Thus they currently test a capability the reviewer cannot see.
>
> The same issue exists in the CPV diagnostic panel: showing first 12 + "N more" is no longer silent, which is an improvement, but the task required a searchable/collapsible/paginated diagnostic representation when there are many CPV categories. A reviewer still cannot inspect the omitted categories. For a review tool whose purpose is to decide whether a broad term such as `pipeline` is safe to map, knowing that hidden categories exist is insufficient; the reviewer needs a way to inspect them.
>
> REQUIRED CORRECTIONS
> 1. CONCEPT DISCOVERY
> - Make overflow/total visible in the actual Filament review UI, not only in the service return value.
> - Provide a way to reach/inspect every matching active concept when matches exceed one response page. Preferred: explicit paginated/load-more concept explorer. An equivalent implementation is acceptable if it guarantees complete discoverability.
> - Do not simply increase the cap to 79/current catalogue size; the design must remain valid as the catalogue grows.
> - Preserve ES/EN/member-term/alias search and active-only filtering.
> - Add UI-level tests proving >cap matches are not only reported by the service but are discoverable/inspectable by the reviewer.
>
> 2. CPV DIAGNOSTICS
> - For a concept with > preview limit CPV categories, provide an actual way to inspect the remainder (pagination, expandable full list, searchable modal/table, etc.).
> - Total + "N hidden" alone is not sufficient for this governance decision.
> - Add a UI-level test proving a category beyond the initial preview can be inspected.
>
> 3. DO NOT CHANGE SEMANTICS
> - Keep MAP_TO_EXISTING single-concept.
> - Keep CONTEXT_REQUIRED as zero mappings.
> - No direct TERM→CPV multi-select.
> - No search/ranking/cardinality changes.
> - No APPLY/publication.
> - Preserve the 3 frozen proposals unchanged.
>
> 4. VALIDATION / STAGING
> - Run focused disposable-fixture tests.
> - Deploy runtime UI correction to staging through the hardened workflow.
> - Verify exact deployed HEAD and smoke candidate review UI.
> - Demonstrate, read-only, discovery of a concept beyond the first result page/cap and inspection of a CPV category beyond the initial diagnostic preview.
> - Recheck live state: 10 candidate links, 2 candidate relations, 142 term concepts, 79 canonical concepts, 9749 TERM→CPV, 3 reviewed proposals; no new real review decisions.
> - 32-query regression remains inherited if this stays admin/read-only only.
>
> AUDIT CONTINUITY
> Inherited approved gates remain approved and are not reset. This correction does not reopen TASK-0004/TASK-0005. TASK-0006 remains paused for unresolved candidates until TASK-0006A passes. TASK-0007 APPLY remains unopened and unauthorized.
>
> STOP / COMPLETION
> Do not process the 7 remaining real candidates or 2 relations while implementing this correction.
>
> Return only:
> READY_FOR_REVIEW
> Issue #2
> HEAD <exact-sha>

## Estado

Ver `docs/orquestador/current_task.md` para el estado vigente y
`audit/phase6_task0006a_concept_explorer_2026-10-01.md` para el detalle completo: estado verificado
de la cola real, hallazgo de arquitectura de la sección D, archivos cambiados, tests, despliegue y
evidencia de staging (sección 9 para las correcciones de este re-audit).
