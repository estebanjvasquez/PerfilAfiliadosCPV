# Auditoría Pre-Phase-3 — Motor de Taxonomía CPV / CIRA

**Fecha:** 2026-09-22
**Realizada por:** sesión de trabajo con Claude Sonnet 5 (retomando el proyecto tras la pérdida del
equipo donde se trabajaba antes), a pedido de Esteban Vásquez.
**Rama de referencia:** `feature/upgrade-filament-v3` (Laravel, HEAD `75d1cd7`) +
`perfilafiliados-mcp` (Cloudflare Worker, HEAD `cb6d2e8` en adelante).
**Resultado:** ✅ **Todas las correcciones que pedía el spec "PRE-PHASE-3 CORRECTIONS" ya estaban
aplicadas en el código antes de esta sesión.** Este documento es una auditoría de verificación
(código + base de datos real), no una nueva pasada de correcciones — no se modificó ninguna tabla
de taxonomía ni se corrió ninguna migración como parte de este trabajo.

---

## 0. Contexto — por qué este documento se escribe ahora

El spec original (14 secciones, ver abajo el mapeo punto por punto) fue redactado como instrucciones
para una sesión de corrección quirúrgica previa a Phase 3. Al recibirlo y auditar el código real
antes de tocar nada, se encontró que:

- El motor real de búsqueda/evidencia/ranking **no vive en este repo Laravel** — vive en el repo
  hermano `perfilafiliados-mcp` (Cloudflare Worker, TypeScript). Ambos repos están coordinados
  (varios commits de Laravel referencian explícitamente su commit correspondiente en
  `perfilafiliados-mcp`).
- Un commit de `perfilafiliados-mcp` — `cb6d2e8` ("fix: Fase 24 - corrige 6 causas raiz generales
  del canonical resolver", 2026-09-19, **anterior** a los commits "Phase 3"/"Phase 3.1" de Laravel)
  ya corrige, casi punto por punto, las secciones 3 a 6 de este spec.
- El fixture de regresión `perfilafiliados-mcp/scripts/fixtures/regression-cases.json` y el test
  `tests/Unit/Taxonomy/IntentContaminationDetectorTest.php` (Laravel) citan textualmente
  **"PRE-PHASE-3 sección 9"** — es decir, este mismo spec ya fue consultado al escribir esas fases.
  No es un documento nuevo sin ejecutar; es, al menos en parte, la base de trabajo ya hecho.

Por eso este documento no aplica correcciones nuevas: verifica y deja registro de que las
correcciones ya existentes son reales, tanto en el código como contra la base de datos en vivo
(Supabase), y cierra formalmente el punto 13 del spec original.

---

## 1. Archivos / clases / esquema inspeccionados

**Laravel (`PerfilAfiliadosCPV`, rama `feature/upgrade-filament-v3`):**

- `app/Services/Taxonomy/TaxonomyAutoMapper.php` (236 líneas, completo)
- `app/Console/Commands/AutoMapTaxonomyTerms.php`
- `app/Services/Taxonomy/TaxonomyCategorySearch.php`
- `app/Services/Taxonomy/CanonicalConceptBuilderService.php`
- `app/Services/Taxonomy/CandidateConceptApprovalService.php`
- `app/Models/TaxonomyTerm.php`, `app/Models/TaxonomyCanonicalConcept.php`
- `app/Filament/Resources/TaxonomyConceptRelationResource.php`
- `app/Filament/Pages/TaxonomyExceptionsPage.php`, `TaxonomyExceptionsWidget.php`
- `tests/Unit/Taxonomy/IntentContaminationDetectorTest.php`,
  `ValidationCorpusTest.php`, `CandidateConceptApprovalServiceTest.php`,
  `CanonicalConceptBuilderServiceTest.php`
- Migraciones: `2026_09_18_090100_create_taxonomy_terms_table.php`,
  `2026_09_18_090300_create_taxonomy_term_cpv_relations_table.php`,
  `2026_09_18_091000_create_taxonomy_candidate_terms_table.php`,
  `2026_09_18_091500_add_provenance_columns_to_taxonomy_terms_table.php`,
  `2026_09_18_091900_create_taxonomy_canonical_concepts_table.php`,
  `2026_09_18_092000_create_taxonomy_term_concepts_table.php`,
  `2026_09_18_092100_add_evidence_to_taxonomy_term_cpv_relations_table.php`,
  `2026_09_19_100300_create_taxonomy_concept_relations_table.php`

**`perfilafiliados-mcp` (Cloudflare Worker):**

- `src/canonical-expansion.ts` (resolución canónica L0-L5)
- `src/hybrid-search.ts` (evidence aggregation, conteos, RRF)
- `src/taxonomy-tools.ts` (tool MCP `search_taxonomy`)
- `src/empresa-tools.ts`
- `scripts/regression-suite.mjs` + `scripts/fixtures/regression-cases.json`
- `wrangler.toml` (binding Hyperdrive `f16a1ab0a9514504b80bd14138699d4c`)

---

## 2. Mapeo del spec, sección por sección

| # | Sección del spec | Hallazgo | Evidencia |
|---|---|---|---|
| 3 | Eliminar short-circuits TERM→CPV | ✅ Ya corregido | `cb6d2e8` eliminó un `NOT EXISTS` en `canonical-expansion.ts` que impedía resolver el concepto canónico cuando el término ya tenía relación CPV propia. Hoy la relación propia solo tiene *prioridad* (`own_relation DESC` en el `ORDER BY`, línea 298), no corta la evaluación de lo demás. |
| 4 | Propagar metadata de términos (`region`, `term_type`, `language`, `provenance`) | ✅ Ya se lee y propaga | Columnas reales en `taxonomy_terms` (`region TEXT[]`, `term_type VARCHAR(50)`, `language VARCHAR(5)`) + tabla `taxonomy_term_source_bindings` para provenance. `canonical-expansion.ts` las expone en `CanonicalTermMatch.regions`/`.termType` (líneas 47-49) y usa `REGIONAL_TERM_TYPES` por `term_type`, nunca hardcodeado por país/término. |
| 5 | Propagar `mapping_relation_type` sin perderlo en el pipeline | ✅ Ya se preserva end-to-end | La columna real es `relation_type` (no `mapping_relation_type` — ese es el nombre camelCase que usa el código TS para exponerlo). Antes de `cb6d2e8` se perdía entre candidate generation y evidence; ahora `hybrid-search.ts` lo adjunta a cada `Evidence.relationType` con el comentario explícito de que nunca se reinterpreta como relación ontológica. |
| 6 | Unificar conteos de evidencia (deduplicados por empresa) | ✅ Ya unificado | `DIRECT_EVIDENCE_LISTS` (una sola definición de "evidencia directa", antes había 2 divergentes) + `countDirectCompanies()` (dedup real por `empresa_id` vía `Set`) reemplazó a `countDirectMatches()`, que subcontaba. `debugCanonicalSearch()` expone `candidates_before_dedup` vs `deduplicated_candidate_count` y una bandera `POSSIBLE_DOUBLE_COUNTING` explícita. |
| 7 | Separar `source_status` de `cpv_mapping_status` | ✅ Ya separados | Desde TAXV3-1 (`f5f0d82`): calidad de fuente vive en `taxonomy_terms.origin_type`; estado de mapeo CPV vive en `taxonomy_terms.mapping_review_status`. Dos ejes independientes, no un campo sobrecargado. |
| 8 | Proteger las relaciones CPV existentes (no destructivo) | ✅ Cumplido | Ver sección 4 de este documento — conteo verificado en vivo, sin pérdida. `TaxonomyAutoMapper` solo decide relaciones para términos **sin ninguna relación CPV todavía**; nunca toca las existentes. |
| 11 | `concept_inherited` — no activar automáticamente ni backfillear | ✅ Cumplido | La lógica existe en `TaxonomyAutoMapper::conceptInheritedMatch()` (línea 97) pero **0 filas viven hoy con `relation_type = 'concept_inherited'`** (verificado en código y contra la BD real, sección 4). No se activó ni se backfilleó como parte de esta auditoría. |
| 12 | Reutilizar `taxonomy_candidate_terms`, no duplicar cola de revisión | ✅ Cumplido | `taxonomy_candidate_terms` sigue siendo la cola de staging del crawler (TAXV2-12), sin cambios. La "Cola de Excepciones unificada" (TAXV3-4) y `taxonomy_candidate_concept_links` (Phase 3) son mecanismos **distintos y complementarios**, no una reimplementación paralela — cada uno cubre un tipo de revisión diferente (crawler / mappings CPV de baja confianza / vínculos término↔concepto). |

**Nota de vocabulario:** "Phase 3" en el código real no es una fase genérica de corrección — es el
"Canonical Concept Builder" (grafo tipado de conceptos canónicos: `taxonomy_concept_relations`,
`taxonomy_candidate_concept_links`, `CanonicalConceptBuilderService`), ya construido (`b14401c`,
`75d1cd7`) pero **desplegado con cero población de datos** (confirmado en vivo, sección 4). Si algún
plan futuro asume que "Phase 3" significa otra cosa, hay que resolver ese desajuste de vocabulario
antes de planificarla.

---

## 3. Correcciones aplicadas como parte de esta sesión

**Ninguna a nivel de esquema, datos o lógica de negocio.** Todo lo que el spec pedía corregir ya
estaba corregido en commits previos (`cb6d2e8`, `f5f0d82`, `dfda1ad`, `b14401c`, `75d1cd7`). Aplicar
"correcciones" ciegas sobre un sistema que ya las tiene habría arriesgado revertir o duplicar
trabajo — exactamente lo que el spec pide evitar (sección 10, "no hardcoding"; sección 8,
"protección de relaciones existentes").

Lo que sí se hizo en esta sesión, fuera del alcance del spec pero necesario para poder auditar:

- Se instaló el entorno local (PHP 8.2 + extensiones `intl`/`pdo_pgsql`/`pgsql`, Composer, Node.js,
  Wrangler) en la máquina donde se retomó el proyecto — no existía ninguna herramienta instalada.
- Se reseteó la contraseña de la base de datos Supabase (a pedido del usuario, para poder verificar
  conteos reales) y se propagó el cambio al `.env` local **y** al binding Hyperdrive del Worker
  `perfilafiliados-mcp` (`f16a1ab0a9514504b80bd14138699d4c`, config `perfilafiliados-taxonomy`) vía
  la API de Cloudflare — sin este segundo paso, el Worker en producción hubiera quedado con la
  contraseña vieja y roto.

---

## 4. Conteos antes/después — verificados contra Supabase en vivo (2026-09-22)

No hubo "antes" real que reportar (no se aplicó ninguna migración ni corrección de datos en esta
sesión), pero sí se verificó que los conteos que el código documenta en sus docblocks coinciden con
la base de datos real, algo que ninguna sesión anterior había podido confirmar en vivo:

| Tabla / condición | Código documentaba | Verificado en vivo (Supabase) |
|---|---|---|
| `taxonomy_term_cpv_relations` (total) | ~9.727 | **9.749** |
| `taxonomy_term_cpv_relations` con `status='needs_review'` | ~9.288 | **9.282** |
| `taxonomy_term_cpv_relations` con `status='approved'` | — | **212** |
| `taxonomy_term_cpv_relations` con `relation_type='concept_inherited'` | 0 (ninguna fila viva) | **0** ✅ |
| `taxonomy_canonical_concepts` | 79 | **79** ✅ exacto |
| `taxonomy_term_concepts` | 142 enlaces | **142** ✅ exacto |
| `taxonomy_concept_relations` (Phase 3, tipado) | 0 (cero población) | **0** ✅ |
| `taxonomy_candidate_concept_links` (Phase 3) | 0 | **0** ✅ |
| `taxonomy_candidate_terms` (cola del crawler) | vacía | **0** ✅ |
| `taxonomy_terms` (total) | — | **1.893** |

La diferencia de +22 en `taxonomy_term_cpv_relations` (9.749 vs 9.727) es consistente con el propio
diseño de `TaxonomyAutoMapper`: procesa automáticamente los términos que todavía no tienen ninguna
relación CPV (documentado como 277 al momento de escribirse: 225 heredados de V2 + 52 nuevos de V3)
y solo crea relaciones nuevas cuando alguna de las 4 señales de confianza supera el piso configurado
— es esperable que ese número haya subido un poco desde que se escribió el docblock, nunca que baje
(la guarda de la sección 8 lo impide).

**No se encontró ninguna pérdida de relaciones ni discrepancia que amerite investigación.**

---

## 5. Resultados de regresión

No se corrió `scripts/regression-suite.mjs` como parte de esta sesión (requiere desplegar/invocar el
Worker con `/debug-search`, fuera del alcance de esta auditoría de solo lectura). Se confirmó en
cambio que el fixture (`scripts/fixtures/regression-cases.json`, grupo `B_regional`) contiene los
casos reales que el spec cita como ejemplos (`cabria`/`cabrias`, `mechurrio`, `macolla`,
`guaya fina`, entre otros) y que el comentario del archivo es explícito en que son **datos de
prueba, nunca lógica del motor** — coherente con la prohibición de hardcoding de la sección 10 del
spec.

**Pendiente / recomendado para una próxima sesión:** correr `scripts/regression-suite.mjs` contra
el Worker desplegado para confirmar que sigue sin regresiones después del reset de contraseña de
Supabase (el cambio de credencial no debería afectar el comportamiento del resolver, pero conviene
confirmarlo con una corrida real antes de dar el tema por cerrado del todo).

---

## 6. Bloqueos / pendientes reales

1. **`taxonomy_concept_relations` y `taxonomy_candidate_concept_links` siguen en cero.** Phase 3
   (Canonical Concept Builder) está construida y con safeguards (Phase 3.1), pero nunca se corrió
   con `--apply` contra datos reales — es infraestructura desplegada sin usar todavía. Decidir si/
   cuándo poblarla es una decisión de producto, no un bug a corregir.
2. **No se corrió la suite de regresión contra el Worker en vivo** tras el reset de contraseña (ver
   sección 5) — recomendado antes de considerar cerrado el ciclo actual.
3. **`docs/taxonomia/`** (con la documentación de planificación original: `plan_mcp_cira.md`,
   `MIGRACION_TAXONOMIA_CPV_V2_A_V3.md`, etc., referenciada decenas de veces en docblocks del
   código) **no está versionada en ningún repo ni rama** — vive fuera de git (posiblemente Notion).
   No fue auditable desde este checkout.
4. **`docs/task.md`** no existía en este equipo (se perdió junto con la máquina donde se trabajaba
   antes) — se reconstruyó en esta sesión a partir del historial de git y esta auditoría; ver
   `docs/task.md` para el estado consolidado.

---

## 7. Quality gate (sección 14 del spec)

- [x] No hubo pérdida de relaciones CPV (verificado: 9.749 ≥ 9.727 documentado, guarda de código intacta)
- [x] No se corrió ninguna migración destructiva (esta sesión fue de solo lectura sobre datos/esquema)
- [x] No se introdujo hardcoding específico de query — no se tocó código de resolución
- [x] TERM → CPV ya no corta la resolución (verificado en `cb6d2e8`)
- [x] `region` se propaga (verificado en código y vía columnas reales)
- [x] `term_type` se propaga (ídem)
- [x] `relation_type` (el campo real; el spec lo llama `mapping_relation_type`) sobrevive el pipeline completo
- [x] Conteos de evidencia/empresas deduplicados y documentados (`countDirectCompanies`, `debugCanonicalSearch`)
- [x] `origin_type`/`mapping_review_status` (los campos reales; el spec los llama `source_status`/`cpv_mapping_status`) siguen separados
- [x] `concept_inherited` documentado, 0 filas vivas, no se activó ni backfilleó
- [x] `taxonomy_candidate_terms` confirmado como la cola compartida de revisión del crawler
- [ ] Tests de regresión — no se corrieron en esta sesión (ver sección 5, pendiente recomendado)
- [x] Los hallazgos de esta sección clasifican como verificación de DATA GAP inexistente, no ENGINE FAILURE — no se encontró ninguna falla real que clasificar

**No se avanza a Phase 3 (población del Canonical Concept Builder) como parte de este documento**,
tal como indica la sección 13 del spec original.
