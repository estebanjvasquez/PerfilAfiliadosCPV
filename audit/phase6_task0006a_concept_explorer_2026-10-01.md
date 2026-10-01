# TASK-0006A — Explorador de conceptos para revisión humana + diagnóstico de mapeo

**Fuente:** Issue #2 comentario [`5929287629`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5929287629)
(texto verbatim completo en [`docs/orquestador/tasks/0006a-concept-explorer.md`](../docs/orquestador/tasks/0006a-concept-explorer.md)).

**Base:** HEAD `a4d8b2f0b514eafdd7ff56f6d59b832802f14867` (registro de cierre de TASK-0005).
**Rama:** `feature/upgrade-filament-v3`. **Fecha:** 2026-10-01.

**Motivo real:** revisando el término `pipeline`, el revisor humano encontró que el selector de
MAP_TO_EXISTING solo ofrecía los duplicados sugeridos por el Builder más la sugerencia original, y
que la búsqueda remota truncaba a 20 resultados exponiendo únicamente el nombre del concepto. Para un
término amplio/polisémico eso puede crear falsa confianza de que las pocas opciones visibles son las
únicas válidas.

---

## 1. Sección E — estado verificado de la cola real ANTES de implementar

Verificado por lectura directa contra la instancia compartida de Supabase.

**Las 10 filas fuente originales siguen existiendo:** ids `263, 264, 265, 266, 267, 268, 269, 270,
271, 272`. Las 2 relaciones candidatas siguen existiendo: ids `61, 62`.

**Exactamente 3 candidatos tienen una revisión humana congelada** (y ninguna fue aplicada):

| Propuesta | Candidato fuente | Decisión | Estado | `payload_version` | `applied_at` |
|---|---|---|---|---|---|
| #420 | 263 | `CONTEXT_REQUIRED` | `PENDING_APPLY` | `taxonomy-reviewed-proposal/c2-v1` | NULL |
| #421 | 264 | `CONTEXT_REQUIRED` | `PENDING_APPLY` | `taxonomy-reviewed-proposal/c2-v1` | NULL |
| #422 | 265 | `CONTEXT_REQUIRED` | `PENDING_APPLY` | `taxonomy-reviewed-proposal/c2-v1` | NULL |

Fingerprints presentes en las tres (se registran solo los prefijos, y el contenido del
`decision_payload` NO se transcribe acá — son datos de revisión del humano, no evidencia que este
documento necesite):

| Propuesta | `payload_fingerprint` | `taxonomy_state_fingerprint` | Claves del payload |
|---|---|---|---|
| #420 | `b9e1ec92528f0b2e...` | `1d0eb041f6428696...` | `term_id`, `context_reason` |
| #421 | `8f985faeef75a78c...` | `1d0eb041f6428696...` | `term_id`, `context_reason` |
| #422 | `2202c6348e65c4dd...` | `1d0eb041f6428696...` | `term_id`, `context_reason` |

El `taxonomy_state_fingerprint` es idéntico en las tres, consistente con que la taxonomía publicada
no cambió entre los tres congelamientos.

**Ningún APPLY ocurrió:** `applied_at`, `authorization_reference` y `target_environment` están en NULL
en las tres. `apply()` es el único escritor de esos tres campos.

**Las filas fuente no fueron tocadas por el freeze**, exactamente como manda el contrato C2: los 10
candidatos siguen en `status=pending` y con `reviewed_at=NULL`.

**Cola restante sin revisar:** candidatos `266, 267, 268, 269, 270, 271, 272` (7) y las 2 relaciones
candidatas. **Esta tarea no procesó ninguno de ellos.**

**Invariantes de taxonomía publicada (estado vivo, sección H):**

| Tabla | Valor | Nota |
|---|---|---|
| `taxonomy_term_concepts` | 142 | invariante de baseline, sin cambios |
| `taxonomy_canonical_concepts` | 79 | invariante de baseline, sin cambios |
| `taxonomy_term_cpv_relations` | 9749 | invariante de baseline, sin cambios |
| `taxonomy_candidate_concept_links` | 10 | sin cambios |
| `taxonomy_concept_relations` | 2 | sin cambios |
| `taxonomy_reviewed_proposals` | **3** | ya NO es 0 — las 3 decisiones que el humano congeló legítimamente durante TASK-0006 |

---

## 2. Sección D — hallazgo de arquitectura: ¿un término puede tener varios conceptos?

Esta pregunta se auditó **antes** de implementar, porque condiciona el diseño de la UI.

### Lo que permite el esquema

`taxonomy_term_concepts` (migración `2026_09_18_092000`) es:

```sql
CREATE TABLE taxonomy_term_concepts (
    id BIGSERIAL PRIMARY KEY,
    term_id BIGINT NOT NULL REFERENCES taxonomy_terms(id) ON DELETE CASCADE,
    concept_id BIGINT NOT NULL REFERENCES taxonomy_canonical_concepts(id) ON DELETE CASCADE,
    created_at TIMESTAMP NULL,
    UNIQUE (term_id, concept_id)
)
```

La restricción única es sobre **el par**, no sobre `term_id` solo, y `TaxonomyTerm::concepts()` es un
`belongsToMany`. O sea: **físicamente el esquema SÍ admite que un término tenga N conceptos
aprobados.**

### Lo que NO existe: representación de contexto

El pivote tiene exactamente cuatro columnas: `id`, `term_id`, `concept_id`, `created_at`. **No hay
ninguna columna de contexto, condición, prioridad ni desambiguación.** No hay dónde expresar "este
concepto aplica en tal contexto y este otro en tal otro".

Existe maquinaria de contexto, pero a nivel de TÉRMINO (`taxonomy_terms.context_required`,
`positive_context`, `negative_context`, `context_window_words`, `minimum_supporting_terms`,
`ambiguity_penalty`) — no por concepto. Esa maquinaria no puede decir cuál de los N conceptos de un
término aplica en un texto dado.

### Lo que hace el consumidor de búsqueda

`app/Console/Commands/BuildEmpresaSearchDocuments.php` arma el índice con tres ramas UNION. La
tercera es **expansión por hermanos de concepto**:

```sql
select r.category_id, t.term as matched_term
from taxonomy_terms t
join taxonomy_term_concepts link on link.term_id = t.id
join taxonomy_term_concepts sib_link on sib_link.concept_id = link.concept_id and sib_link.term_id != t.id
join taxonomy_term_cpv_relations r on r.term_id = sib_link.term_id and r.status = 'approved'
where not exists (
    select 1 from taxonomy_term_cpv_relations r2
    where r2.term_id = t.id and r2.status = 'approved'
)
```

Un término sin relaciones CPV aprobadas propias **hereda las categorías CPV de los términos hermanos
que comparten su concepto**. El join sobre `link` **no está restringido a un concepto** y **no hay
ningún filtro de contexto** en la consulta.

### Conclusión

**La arquitectura NO soporta de forma segura múltiples mapeos TÉRMINO→CONCEPTO aprobados.** Si un
término polisémico se mapeara a N conceptos, heredaría incondicionalmente la **unión** de las
categorías CPV de los hermanos de *todos* esos conceptos, sin ninguna compuerta de contexto — que es
exactamente el resultado que el comentario prohíbe de forma explícita ("it must not automatically fan
the term out to every superficially related CPV category").

Por lo tanto, siguiendo la rama que el propio comentario previó:

- **TASK-0006A mantiene la UI en un solo concepto:** MAP_TO_EXISTING (exactamente un
  `target_concept_id`) + CONTEXT_REQUIRED.
- **No se agregó ningún multi-select.** La cardinalidad TÉRMINO→CONCEPTO no se tocó.
- **El mapeo contextual multi-concepto queda registrado como tarea de diseño futura**, que requiere
  una compuerta nueva del orquestador porque implicaría cambiar semántica de búsqueda e invalidaría
  la evidencia de regresión heredada. Lo mínimo que necesitaría: una representación de contexto por
  mapeo (columna/tabla nueva en el pivote) **y** un consumidor de búsqueda capaz de desambiguar, que
  hoy no existe.

---

## 3. Archivos cambiados

### Nuevos

| Archivo | Propósito |
|---|---|
| `app/Services/Taxonomy/ConceptExplorerService.php` | Servicio de SOLO LECTURA: búsqueda sobre todo el catálogo activo + diagnóstico de concepto. Cero escritura |
| `tests/Feature/Filament/TaxonomyConceptExplorerTest.php` | Cobertura de las secciones A/B/C |
| `docs/orquestador/tasks/0006a-concept-explorer.md` | Definición verbatim de la tarea |
| `audit/phase6_task0006a_concept_explorer_2026-10-01.md` | Este documento |

### Modificados

| Archivo | Cambio |
|---|---|
| `app/Filament/Resources/TaxonomyCandidateConceptLinkResource.php` | Selector de concepto rehecho sobre el explorador; nuevo panel de diagnóstico `concept_diagnostics`; guía de polisemia `polysemy_guidance`; selector de inspección `inspect_concept_id` solo para CONTEXT_REQUIRED; nuevo `formatConceptDiagnostics()` |

**Sin migraciones.** Ningún cambio de esquema: todo sale de tablas y columnas que ya existían.

---

## 4. Sección A — descubrimiento de conceptos

El selector `target_concept_id` se rehizo sobre `ConceptExplorerService`:

| Antes | Ahora |
|---|---|
| `options()` = duplicados del Builder + sugerencia | `options()` = el mismo conjunto, pero **explícitamente etiquetado como recomendaciones/evidencia** |
| Búsqueda sobre `canonical_name_en`/`es` con `limit(20)`, sin informar nada | Búsqueda sobre **todo el catálogo ACTIVO**, con tope de 50 y **el total real siempre informado** |
| Sin filtro de `status` | `status = active` — corrige un defecto real: antes ofrecía conceptos `merged`, que no son destino válido |
| Solo nombre canónico | Nombre ES, nombre EN, **término miembro** y **alias de término miembro** |
| Etiqueta = solo nombre | Etiqueta = nombre(s) + `#id` + tipo/dominio, para distinguir homónimos |

**Sobre la truncación (sección A.3):** no se reemplazó un tope arbitrario por otro silencioso.
`searchActiveConcepts()` devuelve siempre `total`, `shown` y `truncated`, así que la UI puede decirle
al revisor cuántas coincidencias existen de verdad y pedirle que refine, en vez de hacerle creer que
vio todo. Con búsqueda vacía devuelve el catálogo activo ordenado por nombre, así que además se puede
**navegar** el conjunto, no solo buscar a ciegas. Verificado en vivo: el catálogo tiene **79
conceptos activos**, `browse` informa `total=79`.

**Por qué no un widget paginado aparte:** el `Select` de Filament no tiene UI de paginación para los
resultados de `getSearchResultsUsing()`. En vez de inventar una, el descubrimiento se vuelve completo
por tres vías combinadas: (a) la búsqueda matchea nombres, términos y alias, así que cualquier
concepto activo es alcanzable; (b) la búsqueda vacía permite navegar el catálogo; (c) el total real y
el overflow se informan siempre, así que nada puede perderse en silencio. La restricción del
comentario era "no arbitrary top-20 truncation that can hide valid concepts" y "without loading an
unbounded dataset into the browser" — ambas se cumplen.

**Sin hardcoding** de `pipeline` ni de ningún término de referencia: el servicio no contiene ninguna
constante de dominio. Verificado en vivo que `search('pipe')` encuentra 3 conceptos activos
(`Pipeline Pig / Diablo/Cochino de Limpieza de Ductos`, `drill pipe`, `stuck pipe`) por el mecanismo
general, no por un caso especial.

---

## 5. Sección B — panel de diagnóstico

Nuevo `Placeholder` `concept_diagnostics`, de solo lectura, que reacciona al concepto elegido
(MAP_TO_EXISTING) o inspeccionado (CONTEXT_REQUIRED). Muestra:

| Dato | Origen gobernado |
|---|---|
| Identidad: nombres ES/EN, `#id`, status, tipo, dominio | `taxonomy_canonical_concepts` |
| Términos con identidad aprobada (y su conteo) | `taxonomy_term_concepts` — su existencia **es** la señal de publicación (la tabla no tiene columna de status) |
| Alias de los términos miembro | `taxonomy_term_aliases` |
| Categorías CPV alcanzables | términos miembro → `taxonomy_term_cpv_relations` con `status='approved'` → `taxonomy_categories`. Mismo criterio que `CanonicalConceptBuilderService::conceptApprovedCategoryIds()` y que la expansión por hermanos del índice de búsqueda |
| Etiquetas Grupo/Familia/Categoría + código | `TaxonomyCategory::$code`, `$level` (0/1/2) y `breadcrumb('es')` |
| Impacto predicho de empresas | `predictAffectedCompanies()` del Builder, formateado con el `formatImpactSummary()` que ya existía |
| Procedencia | `origin_type`/`display_source`/`language`/`term_type` de los términos miembro |
| Advertencias de huecos de datos | concepto no activo, sin términos miembro, sin CPV aprobadas, identidad bilingüe incompleta, y los `data_gap_flags` del propio impacto |

**No se inventa ninguna relación.** No se expone ningún camino TÉRMINO→CPV directo: la capa de
concepto canónico sigue siendo la autoritativa, y las categorías se muestran como *consecuencia* del
concepto, con ese encuadre explícito en el texto de la UI.

**Sobre muchas categorías (sección B):** se listan en detalle las primeras 12 (código, nivel,
nombre, breadcrumb) y el resto se declara explícitamente — "se listan 12 de N; quedan M sin listar" —
con el total real siempre visible. Nunca se truncan en silencio.

---

## 6. Sección C — guía de polisemia

Nuevo `Placeholder` `polysemy_guidance` arriba del radio de decisión, que contrasta las dos
decisiones en los términos del propio comentario, y aclara que mapear vincula TÉRMINO → CONCEPTO
CANÓNICO y **no** es "elegir todas las categorías CPV que contengan una palabra parecida".

Para CONTEXT_REQUIRED:

- el `context_reason` obligatorio no cambió;
- se agregó `inspect_concept_id`, un selector **puramente diagnóstico** que alimenta el panel para
  que el revisor pueda comprobar si algún concepto existente sería suficientemente específico. **No
  crea ningún mapeo** y **nunca entra al payload congelado**: el `match` de la acción solo toma
  `context_reason` para esta decisión. Hay un test dedicado que lo prueba;
- no se afirma en ningún texto que CONTEXT_REQUIRED sea consumido hoy por el runtime de búsqueda
  (ningún consumidor lo lee).

**No se introdujo ninguna regla automática** que mande términos amplios a CONTEXT_REQUIRED. La
decisión final sigue siendo del humano.

---

## 7. Sección F — tests

Entorno local seguro (PHP 8.2.34 contra la instancia compartida de Supabase), fixtures desechables
con prefijo `zzz_task0006a_` dentro de `DatabaseTransactions` sobre `pgsql`. **No** se reusó el
procedimiento inseguro de contenedor efímero sobre los bind mounts de staging.

**Nota de seguridad de las fixtures:** las categorías CPV se referencian **de solo lectura** desde
filas reales existentes en vez de crearse, así que no se agrega ninguna fila a `taxonomy_categories`.
Solo se escriben las relaciones `taxonomy_term_cpv_relations` aprobadas de fixture, que se revierten
con la transacción — el mismo patrón que ya usa el resto de la suite.

### Suite nueva

```
php artisan test --filter="TaxonomyConceptExplorerTest"
→ 16 passed (89 assertions), 433.35s
```

| Requisito de la sección F | Test |
|---|---|
| Catálogo activo completo descubrible, más allá de los duplicados sugeridos | `the_search_covers_the_whole_active_catalogue_not_only_builder_suggestions` + `browsing_with_an_empty_search_reports_the_real_total_of_active_concepts` |
| Un concepto fuera del set de sugerencias puede encontrarse y seleccionarse | `a_concept_outside_the_suggestion_set_can_be_found_and_frozen_as_map_to_existing` |
| >20 coincidencias no pueden perderse en silencio | `more_than_twenty_matching_concepts_are_not_silently_lost` (25 coincidencias, 25 devueltas) + `when_matches_exceed_the_cap_the_overflow_is_reported_instead_of_hidden` |
| Búsqueda por nombre canónico ES/EN | `search_matches_both_spanish_and_english_canonical_names` |
| Panel de diagnóstico muestra asociaciones CPV gobernadas | `diagnostics_expose_the_governed_cpv_associations_of_the_selected_concept` + `a_cpv_relation_that_is_not_approved_is_not_presented_as_reachable` |
| Set grande de asociaciones sin truncar en silencio | `a_large_cpv_association_set_is_not_silently_truncated` (total=18, listadas=12, overflow=6, y ambos números en el HTML) |
| MAP_TO_EXISTING sigue congelando exactamente un concepto explícito | `a_concept_outside_the_suggestion_set_can_be_found_and_frozen_as_map_to_existing` |
| CONTEXT_REQUIRED sigue congelando cero mapeos/conceptos | `context_required_still_freezes_zero_mappings_even_after_inspecting_a_concept` |
| CREATE_NEW y REJECT sin cambios | cubiertos por los tests preexistentes de `TaxonomyCandidateConceptLinkReviewTest` (en verde) |
| Ninguna UI de APPLY/Publish introducida | `the_review_form_exposes_no_apply_or_publish_action` |
| Regresión de señales anidadas de TASK-0002 protegida | test original preservado sin cambios (es el único fallo, por `ext-intl` local) |
| Propuestas congeladas siguen protegidas de forma idempotente | `an_already_frozen_candidate_is_still_protected_from_a_second_freeze` |
| Límites de autorización de TASK-0005 intactos | `TaxonomyReviewedProposalResourceTest` completo en verde (ver abajo) |

Extras no exigidos pero relevantes: descubrimiento por término miembro y alias, conceptos `merged`
nunca ofrecidos como destino, etiquetas que distinguen homónimos, y advertencias de huecos de datos.

### Regresión de archivos relacionados

```
php artisan test --filter="TaxonomyCandidateConceptLinkReviewTest|TaxonomyReviewedProposalResourceTest|ReviewedProposalServiceTest|CandidateConceptApprovalServiceTest"
→ 1 failed, 92 passed (336 assertions), 2234.46s
```

| Archivo | Resultado |
|---|---|
| `ReviewedProposalServiceTest` | **PASS** — contrato C2 de TASK-0004 intacto |
| `CandidateConceptApprovalServiceTest` | **PASS** — el camino legacy sigue sin poder publicar rodeando C2 |
| `TaxonomyReviewedProposalResourceTest` | **PASS** — autorización por tipo de origen de TASK-0005 intacta |
| `TaxonomyCandidateConceptLinkReviewTest` | 13/14 — las 13 sustantivas en verde, incluidas todas las de freeze review que tocan el formulario modificado |

**Total TASK-0006A: 108 passed, 1 failed.** El único fallo es
`viewing_a_propose_new_concept_candidate_with_duplicate_signals_does_not_500`, el gap preexistente de
`ext-intl` en este Windows documentado en todas las rondas de esta sesión y verde en el runtime real
de staging (evidencia de TASK-0004 ronda 6). Se reporta tal cual, sin alterar ni saltar ninguna
aserción.

**Invariantes después de los tests:** `10 / 2 / 142 / 79 / 9749 / 3` — idénticos, incluidas las 3
revisiones humanas protegidas. Cero residuo de fixtures.

### Re-corrida tras el re-audit `5930560603`

```
php artisan test --filter="TaxonomyConceptExplorerTest"
→ 23 passed (135 assertions)
```

De 16 a 23 tests: los 4 nuevos de nivel UI (ver sección 9) más los de paginación reemplazando a los
que probaban el tope silencioso. **Cero fallos.**

Verificado explícitamente que las fixtures no dejan residuo: cero conceptos/términos/alias con
prefijo `zzz_`, cero relaciones CPV con `source='task0006a_test_fixture'`, y `taxonomy_term_concepts`
/ `taxonomy_term_cpv_relations` en 142 / 9749. Ver sección 10 para el estado vivo, que cambió por
trabajo concurrente del humano y no por los tests.

---

## 8. Sección G — despliegue y validación en staging

| Dato | Valor |
|---|---|
| Commit de código | `df32a069c49d7ec4680070db107220dbc1fd536f` |
| Base | `a4d8b2f0b514eafdd7ff56f6d59b832802f14867` |
| Run de GitHub Actions | `conclusion: success`, 2026-10-01T11:22:07Z → 11:22:48Z |
| HEAD verificado en el servidor | `git rev-parse HEAD` = `df32a069c49d7ec4680070db107220dbc1fd536f` (coincidencia exacta) |
| Contenedores | `app running`, `nginx running` |

Desplegado por el mecanismo endurecido de siempre: el push incluye `app/**` y `tests/**`, que no
matchean ningún patrón de `paths-ignore`.

### Smoke HTTP

| URL | Resultado |
|---|---|
| `/` | **200** |
| `/admin/login` | **200** |
| `/admin/taxonomy-candidate-concept-links` | **302** → login (esperado sin sesión) |
| `/admin/taxonomy-reviewed-proposals` | **302** → login |

Cero 500, cero 503, verificado antes y después de la validación.

### Páginas de revisión autenticadas (solo GET, como el revisor real)

Ejecutado dentro del contenedor desplegado vía el HTTP kernel real, actuando como el usuario revisor
real (id 3). **Ninguna acción se invocó.**

| Página | Resultado |
|---|---|
| `/admin/taxonomy-candidate-concept-links` (listado) | **HTTP 200** |
| `/admin/taxonomy-candidate-concept-links/266` (detalle) | **HTTP 200** |

### Descubrimiento de conceptos verificado en modo lectura sobre el código desplegado

| Comprobación | Resultado |
|---|---|
| Catálogo activo | **79** conceptos |
| Candidato real 266 (sin revisar) | `status=pending`, `reviewed_at=NULL` — intacto |
| Tamaño del set de sugerencias del Builder para el candidato 266 | **0** |
| Conceptos descubribles FUERA de ese set | **79** |
| Un concepto de ejemplo fuera del set | `abandon [#37]` |
| Ese mismo concepto encontrado por búsqueda real (no solo listando) | **sí** (needle `abando`) |
| `search('pipe')` sobre datos reales | **3** coincidencias activas |

**Este es el hallazgo que mejor justifica la tarea:** el set de sugerencias del Builder para el
candidato 266 está **vacío**. Con el selector anterior, el revisor abría el formulario con **cero
opciones** en el desplegable y solo podía buscar a ciegas con una truncación silenciosa a 20. Ahora
los 79 conceptos activos son descubribles, y se demostró en el runtime desplegado que un concepto
fuera del subconjunto original se encuentra por búsqueda.

### Diagnóstico CPV renderizado sin error

| Comprobación | Resultado |
|---|---|
| Concepto de prueba (con relaciones CPV aprobadas) | #10 |
| Términos con identidad aprobada | 22 |
| Categorías CPV alcanzables | 1 (overflow 0) |
| HTML renderizado | 2 116 bytes, contiene un código `CPV-` |

Sin 500 ni 503 en ningún momento.

### Estado protegido después del despliegue

| Comprobación | Valor |
|---|---|
| `taxonomy_candidate_concept_links` | 10 |
| `taxonomy_concept_relations` | 2 |
| `taxonomy_term_concepts` | 142 |
| `taxonomy_canonical_concepts` | 79 |
| `taxonomy_term_cpv_relations` | 9749 |
| `taxonomy_reviewed_proposals` | 3 |
| IDs de las propuestas | **420, 421, 422** — exactamente las mismas |
| Propuestas aplicadas | **0** |
| Estados de candidatos | `pending:10` |
| Estados de relaciones | `candidate:2` |
| Candidatos con `reviewed_at` no nulo | **0** |

**Las 3 revisiones humanas congeladas quedaron exactamente como estaban:** mismos ids, ninguna
aplicada, ninguna alterada, ninguna re-congelada. **No se tomó ninguna decisión nueva** sobre la cola
real: los 7 candidatos restantes y las 2 relaciones siguen sin procesar.

No se re-corrió la suite completa contra los bind mounts compartidos, no se creó ningún contenedor
efímero y no se modificó la topología de compose.

---

## 9. Re-audit — comentario `5930560603` (bloqueo A.3/B, corregido)

El orquestador revisó HEAD `b0d2d20` y aceptó la disciplina de alcance, el servicio de solo lectura,
el análisis de arquitectura de la sección D, la semántica C2 y la evidencia de despliegue — pero
bloqueó por una razón correcta y concreta.

### El bloqueo

`ConceptExplorerService` devolvía `total`/`truncated`, pero **los dos controles reales de Filament
llamaban solo a `searchOptions($search)`**, que descartaba ambos. Consecuencia: el revisor recibía
como máximo N opciones **sin ninguna señal en la UI de que existieran más**, y sin mecanismo para
alcanzarlas. La afirmación del audit de que "la UI puede decirle al revisor cuántas coincidencias
existen" **no estaba implementada en el control**. Los tests verificaban que el SERVICIO informaba el
overflow, o sea probaban una capacidad que el revisor no podía ver.

Lo mismo en el panel CPV: "primeras 12 + N más" ya no era silencioso, pero el revisor **seguía sin
poder inspeccionar las categorías omitidas** — insuficiente para una decisión de gobernanza sobre si
un término amplio como `pipeline` es seguro de mapear.

### Corrección 1 — explorador paginado de verdad

| Antes | Ahora |
|---|---|
| `->searchable()` + `getSearchResultsUsing()` con tope, que descartaba `total`/`truncated` | Control explícito: campo de búsqueda + paginación + línea de estado **renderizada** |
| Sin señal de overflow en la UI | `concept_explorer_status` muestra "Mostrando X-Y de N coincidencias (página P de T)" y, si hay más, avisa que se pagine |
| Coincidencias más allá del tope inalcanzables | Acciones **Página anterior/siguiente**; el `Select` lista la página actual, así que **toda** coincidencia es alcanzable |
| — | `searchActiveConcepts()` es paginado de punta a punta: `total`, `per_page`, `page`, `last_page`, `has_more`, `has_previous`, rango |

Decisiones de diseño:

- **No se subió el tope al tamaño del catálogo (79).** El re-audit lo prohíbe explícitamente y con
  razón: el diseño tiene que seguir siendo correcto cuando el catálogo crezca. La página es de 25 y
  estable.
- **Se eliminó `->searchable()` del `Select`.** Dejarlo habría mantenido un camino paralelo con tope
  silencioso — exactamente el defecto señalado. El descubrimiento ahora tiene un solo camino, y es
  completo.
- Las recomendaciones del Builder se **suman** a la página actual, así que siguen disponibles como
  evidencia sin competir con el catálogo.
- Se preservan la búsqueda ES/EN/término miembro/alias y el filtro `status=active`.
- Un `page` fuera de rango se **acota** a la última página real en vez de devolver vacío.

**Verificado en vivo:** las 4 páginas del catálogo activo devuelven exactamente los **79** conceptos
distintos; pedir la página 999 acota a la 4.

### Corrección 2 — categorías CPV inspeccionables

| Antes | Ahora |
|---|---|
| Primeras 12 + "quedan N sin listar" | Página de 12 **más filtro de texto y paginación** |
| Las omitidas no se podían ver | Acciones **CPV: página anterior/siguiente** y filtro por código o nombre traducido |
| — | `category_total` (alcanzables reales) y `category_matching` (coinciden con el filtro) se informan **ambos**, así que un filtro no puede hacer parecer que hay menos categorías de las que hay |

La línea de estado de categorías se renderiza dentro del panel, igual que la del explorador.

### Tests de nivel UI (lo que faltaba)

El re-audit señaló que los tests probaban el servicio, no la UI. Se agregaron cuatro que ejercitan el
formulario real de Filament vía `mountTableAction`/`setTableActionData`/`callMountedTableAction`:

| Test | Qué prueba |
|---|---|
| `the_review_ui_surfaces_the_match_total_and_page_position_to_the_reviewer` | Con 30 coincidencias, el formulario **renderiza** "de 30 coincidencias" y "página 1 de 2" |
| `the_reviewer_can_page_to_a_concept_beyond_the_first_page_and_freeze_it` | Un concepto que **solo** existe en la página 2 se elige y se congela correctamente desde la UI |
| `the_review_ui_lets_the_reviewer_inspect_a_cpv_category_beyond_the_first_page` | Una categoría ausente del preview inicial **no se ve** en página 1 y **sí se ve** en página 2 |
| `the_review_ui_lets_the_reviewer_filter_cpv_categories_to_reach_a_hidden_one` | Filtrando por código se alcanza una categoría oculta, y el total real (18) se sigue declarando |

Más los tests de servicio actualizados a la API paginada: alcanzabilidad de las 30 coincidencias
paginando, línea de estado con total y posición, acotado de página fuera de rango, alcanzabilidad de
las 18 categorías paginando, y filtro que no oculta el total real.

### Semántica sin cambios (punto 3 del re-audit)

MAP_TO_EXISTING sigue siendo **un** concepto explícito; CONTEXT_REQUIRED sigue congelando **cero**
mapeos y el concepto inspeccionado sigue sin entrar a su payload; **no** se agregó multi-select
TÉRMINO→CPV; cero cambios de búsqueda/ranking/cardinalidad; ningún APPLY/publicación; y las 3
propuestas congeladas quedaron intactas.

---

## 10. Avance concurrente de TASK-0006 durante esta corrección (estado vivo actualizado)

Mientras se implementaba esta corrección, **el revisor humano siguió trabajando TASK-0006 con la UI
mejorada** y resolvió precisamente el término que originó TASK-0006A. Esta sesión **no tocó nada de
esto**; se registra porque mueve dos de los números que el re-audit pide re-chequear.

**Qué hizo el humano (procedencia verificada):**

| Momento | Acción |
|---|---|
| 2026-10-01 13:07:11 | Creó el concepto canónico #2890 `oleoducto / oil pipeline` |
| 2026-10-01 13:07:30 | Creó el concepto canónico #2891 `gasoducto / gas pipeline` |
| 2026-10-01 13:08:21 | Congeló `MAP_TO_EXISTING` sobre el candidato real **272** (término **`pipeline`**) apuntando al concepto **#2890** |

El audit log #1192 lo confirma: `user_id=3`, `actor_type=user`, razón "Decisión de revisión
congelada: MAP_TO_EXISTING (candidate_link_id=272)". Los dos conceptos nuevos se crearon por el CRUD
administrativo de conceptos canónicos, que es independiente del flujo C2.

**Validación real del objetivo de la tarea.** El término `pipeline` es exactamente el caso que motivó
TASK-0006A: polisémico, y con un set de sugerencias del Builder que no alcanzaba. Con el explorador
nuevo el revisor pudo determinar que necesitaba conceptos específicos, crearlos, y mapear el término
a `oleoducto / oil pipeline` en lugar de forzarlo contra una opción inadecuada o abanicarlo a
categorías CPV superficialmente parecidas. Es la mejor evidencia de que la corrección sirve para lo
que se pidió.

**Nada se publicó ni se aplicó:**

- `applied_at`, `authorization_reference` y `target_environment` siguen en NULL en las 4 propuestas;
  **0 aplicadas**.
- Los conceptos #2890 y #2891 tienen **0 filas** en `taxonomy_term_concepts`: no se publicó ninguna
  identidad término→concepto.
- El candidato 272 sigue en `status=pending` con `reviewed_at=NULL`, como manda el contrato C2.
- `taxonomy_term_concepts` = 142 y `taxonomy_term_cpv_relations` = 9749 **sin cambios**.

**Estado vivo (el que corresponde re-chequear, no el baseline):**

| Tabla | Baseline del re-audit | Estado vivo | Causa de la diferencia |
|---|---|---|---|
| `taxonomy_candidate_concept_links` | 10 | **10** | — |
| `taxonomy_concept_relations` | 2 | **2** | — |
| `taxonomy_term_concepts` | 142 | **142** | — |
| `taxonomy_canonical_concepts` | 79 | **81** | 2 conceptos nuevos creados por el humano (CRUD administrativo, fuera de C2). Ninguno publicado en `taxonomy_term_concepts` |
| `taxonomy_term_cpv_relations` | 9749 | **9749** | — |
| `taxonomy_reviewed_proposals` | 3 | **4** | 1 decisión nueva congelada por el humano sobre el candidato 272 |

Candidatos con revisión congelada: **263, 264, 265, 272**. Sin revisar: **266, 267, 268, 269, 270,
271** (6). Relaciones candidatas: 2, ninguna revisada.

**Esta sesión no tomó ninguna decisión de revisión real**, no aplicó nada y no modificó ninguna de
las 4 propuestas congeladas. Las fixtures de los tests revirtieron correctamente: cero conceptos,
términos, alias o relaciones CPV con prefijo de fixture quedaron en la base (verificado
explícitamente), y los dos totales de taxonomía publicada están intactos.

Se señala para el orquestador que `taxonomy_canonical_concepts` pasó de 79 a 81, o sea uno de los
números que el re-audit listó como invariante de taxonomía publicada cambió — por trabajo legítimo
del humano, no por esta corrección, y sin que se publicara ninguna identidad término→concepto.

---

## 11. Sección H — gates heredados

| Gate | Invalidado | Análisis |
|---|---|---|
| Regresión de 32 queries | **NO** | El cambio es estrictamente UI de admin + diagnóstico de solo lectura. No se tocó `BuildEmpresaSearchDocuments`, ni ningún consumidor de búsqueda, ni la cardinalidad de mapeo, ni taxonomía publicada. Se cumple la condición literal de la sección H para no re-correrla |
| Cardinalidad TÉRMINO→CONCEPTO | **NO** | Sin cambios — ver sección 2. La UI sigue siendo de un solo concepto |
| Semántica de búsqueda/ranking | **NO** | Cero cambios |
| Implementación C2 (TASK-0004) | **NO** | `ReviewedProposalService` no se modificó. `freeze()` sigue siendo el único escritor |
| Autorización de TASK-0005 | **NO** | Policies y límites de `canEdit`/`canDelete`/`getEloquentQuery` sin cambios |
| Invariantes de taxonomía publicada | **NO** | 142 / 79 / 9749 sin cambios |
| Riesgo adyacente de TASK-0005 (sección I) | n/a | Deliberadamente **no** se amplió esta tarea a eso; sigue registrado para una tarea futura de endurecimiento de gobernanza. Este trabajo de UI no depende de él |

---

## 12. Manejo de secretos

Ningún secreto fue leído, impreso ni registrado. El contenido de los `decision_payload` de las 3
propuestas congeladas **no** se transcribe en este documento (son datos de revisión del humano); solo
se registran las claves presentes y los prefijos de los fingerprints, que son hashes.

---

## 13. Condiciones STOP — ninguna alcanzada

Nada de lo siguiente ocurrió ni fue necesario: cambiar la cardinalidad TÉRMINO→CONCEPTO, cambiar
semántica de búsqueda/ranking, aplicar/publicar alguna propuesta revisada, modificar/borrar las 3
revisiones humanas congeladas, procesar decisiones adicionales de la cola real, migración destructiva,
despliegue a producción, merge a `main`, ni rotación de credenciales/tokens.
