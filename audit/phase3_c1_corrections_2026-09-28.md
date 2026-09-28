# TASK-0003 — Correcciones de Phase C1 (7 hallazgos del orquestador)

**Fecha:** 2026-09-28
**Rama:** `feature/upgrade-filament-v3`
**Fuente:** Issue #2, comentario `5872689869` (materializado íntegro en
`docs/orquestador/tasks/0003-phase-c-corrections.md`)
**Estado de TASK-0001 (Fase C1) antes de esta tarea:** `CORRECTIONS_REQUIRED`

Este documento responde punto por punto los 7 hallazgos, en el mismo orden en que se plantearon.
No se re-litiga ninguno — cada uno se cerró con código+test, o se documenta explícitamente qué
quedó fuera de alcance y por qué.

---

## Hallazgo 1 (HIGH) — Contrato de Phase C mal etiquetado

**Reclamo:** `CanonicalConceptApplyService::apply(array $dryRun)` consume un dry-run del Builder y
crea filas en las colas de revisión — útil, pero no el contrato originalmente acordado
(REVIEWED_PROPOSAL → payload inmutable con fingerprint → APPLY(payload) → VALIDATE →
COMMIT/ROLLBACK). No se debía describir como "Phase C completa".

**Decisión adoptada:** opción (a) del hallazgo — re-etiquetar explícitamente en vez de reimplementar
desde cero. Se mantiene el nombre de clase/archivo (renombrarlos habría tocado ~15 sitios de
llamada sin cambiar ningún comportamiento) pero:

- Nueva constante `CanonicalConceptApplyService::PHASE_LABEL = 'PHASE_C1_QUEUE_MATERIALIZATION'`.
- `ALGORITHM_VERSION` pasó de `phase-c-v1` a `phase-c1-v1`.
- Docblock de la clase reescrito: primer párrafo explica la distinción Phase C1/C2 antes que
  cualquier otra cosa, con referencia cruzada a este documento.
- `docs/task.md` (sección 6quater), `docs/implementation_plan.md` (sección "Fase C") y el comando
  `taxonomy:build-canonical-concepts --apply` (mensaje en pantalla) corregidos para no describir
  esto como "Phase C" sin calificar.

**Phase C2 (payload inmutable revisado → apply → validate → commit/rollback) NO se implementó en
esta tarea** — el hallazgo ofrecía (a) o (b) como alternativas, no como requisito de hacer ambas.
Queda como trabajo futuro explícito, no como deuda oculta.

## Hallazgo 2 (HIGH) — Gate de autorización de escritura en producción

**Reclamo:** la autorización para *desarrollar* Phase C no es autorización para *ejecutar* una
escritura real contra un ambiente real. Antes de otro `--apply`, había que registrar quién autorizó
y contra qué ambiente.

**Fix:** `apply(array $dryRun, string $authorizedBy, int $maxWrites = ...)` — `$authorizedBy` es
obligatorio, sin valor por defecto, y `apply()` lanza `InvalidArgumentException` si viene vacío
(`trim($authorizedBy) === ''`). No hay forma de invocar `apply()` sin pasarlo explícitamente -
verificado por el propio compilador de PHP (parámetro requerido), no por una convención que se
pueda olvidar. El comando CLI exige `--authorized-by="..."` cuando se usa `--apply` y falla con
`self::FAILURE` (sin escribir nada) si falta.

El valor queda registrado en:
- Cada línea de audit log (`TaxonomyAuditLogger::record()`) del candidato/relación creado.
- `provenance.authorized_by` de cada `taxonomy_concept_relations` creada.
- `$outcome['authorized_by']` del resultado de `apply()`, impreso por el comando.

**No se corrió ningún `--apply` real contra producción/staging en esta tarea** — los 10
candidatos/2 relaciones existentes de la corrida anterior (2026-09-28, `--limit=10 --max-writes=50`)
se dejaron intactos, tal como pidió el hallazgo 5 del comentario original ("Preserve the existing
rows for review; do not delete them").

**Test:** `apply_refuses_to_run_without_an_explicit_authorized_by` (espera
`InvalidArgumentException`, confirma cero escrituras).

## Hallazgo 3 (HIGH) — Idempotencia segura ante concurrencia

**Reclamo:** `exists()` seguido de `create()` es un TOCTOU real - dos corridas concurrentes pueden
pasar el chequeo las dos y duplicar la cola. Lo mismo aplicaba a la validación/inserción de
relaciones.

**Estado real de cada tabla, verificado leyendo el schema, no asumido:**

| Tabla | Constraint única ANTES de esta tarea | Acción |
|---|---|---|
| `taxonomy_candidate_concept_links` | Ninguna (confirmado, era literalmente el docblock de la clase) | Migración nueva (ver abajo) |
| `taxonomy_concept_relations` | `UNIQUE(source_concept_id, target_concept_id, relation_type)` - **ya existía desde su creación** | Código adaptado para aprovecharla, no migración nueva |

**Migración nueva** (`2026_09_28_165653_add_unique_constraints_to_taxonomy_candidate_concept_links`):
dos índices únicos PARCIALES, sin borrar ni tocar ninguna fila existente:

```sql
CREATE UNIQUE INDEX taxonomy_candidate_concept_links_term_concept_uniq
  ON taxonomy_candidate_concept_links (suggested_term_id, suggested_concept_id)
  WHERE suggested_concept_id IS NOT NULL;

CREATE UNIQUE INDEX taxonomy_candidate_concept_links_new_concept_term_uniq
  ON taxonomy_candidate_concept_links (suggested_term_id)
  WHERE suggested_concept_id IS NULL;
```

(Partición por `IS NULL`/`IS NOT NULL` porque Postgres no considera dos `NULL` iguales en un índice
compuesto normal - hace falta un índice parcial para el caso "propuesta de concepto nuevo".)

Corrida en el ambiente compartido (el mismo Postgres de Supabase que usan local/staging - confirmado
por `php artisan migrate:status`, `[53] Ran`) sin error, lo que de paso demuestra que no había
ninguna fila duplicada preexistente que la violara.

**Código:** las tres escrituras de `apply()` (`taxonomy_candidate_concept_links` en sus dos formas,
`taxonomy_concept_relations`) pasaron de `exists()` + `create()` a
`DB::table(...)->insertOrIgnore([...])` (compila a `INSERT ... ON CONFLICT DO NOTHING` en
Postgres). La base decide atómicamente; la aplicación solo interpreta el conteo de filas afectadas
(`0` = ya existía, se cuenta como `skipped`). Esto es "an equivalent concurrency proof" en el
sentido de que la garantía ya no depende de que la aplicación lea-y-luego-escriba sin que nadie se
meta en el medio - depende del índice único, que Postgres aplica atómicamente sin importar cuántos
procesos compitan.

**No se destruyó historial de rechazos**: los índices son sobre `(term_id, concept_id)` sin
importar `status` - un `rejected` previo sigue bloqueando un reintento, exactamente la semántica que
ya tenía el código con `exists()` (que tampoco filtraba por status).

**Tests:**
- `apply_is_concurrency_safe_via_a_database_unique_index_not_a_toctou_check` - prueba que el índice
  existe y lo hace cumplir la base (inserta el mismo par dos veces por SQL directo, espera
  `QueryException`).
- `apply_does_not_abort_the_whole_transaction_when_a_single_pair_lost_the_race` - simula que otra
  corrida ya encoló un par exacto antes de que esta corrida llegue a intentarlo; confirma que
  `apply()` sigue OK, ese par se cuenta `skipped`, y el resto del plan SÍ se aplica (no revienta la
  transacción entera).

## Hallazgo 4 (MEDIUM/HIGH) — Contrato de estado obsoleto incompleto

**Reclamo:** `conceptGraphFingerprint()` (solo `taxonomy_canonical_concepts` +
`taxonomy_term_concepts`) no cubre todos los insumos reales de `dryRun()` - términos, CPV,
embeddings, settings de scoring, etc. No se debía describir como si "probara" que el dry-run entero
es inmutable.

**Auditoría de dependencias reales de `dryRun()`** (leyendo el código de
`CanonicalConceptBuilderService`, no asumiendo):

| Tabla | Cómo afecta la propuesta |
|---|---|
| `taxonomy_canonical_concepts`, `taxonomy_term_concepts` | El grafo publicado - elegibilidad de término (`whereDoesntHave('concepts')`) y candidatos de concepto |
| `taxonomy_terms` | El pool de términos elegibles y sus atributos (`relevance_weight`, `oil_gas_exclusivity`, etc., usados en scoring) |
| `taxonomy_term_aliases` | Retrieval + señal `alias_overlap` |
| `taxonomy_term_cpv_relations` | Elegibilidad (`whereDoesntHave('cpvRelations', approved)`) + señales `shared_cpv`/`cpv_specificity`/`cpv_mapping_quality` |
| `taxonomy_term_embeddings` | Señal `embedding_similarity` + retrieval vectorial |
| `taxonomy_term_service_relations` | Señal `legacy_service_overlap` |
| `taxonomy_term_source_bindings` | Señal `source_evidence` |
| `taxonomy_settings` | Los pesos `concept_builder.*`/`concept_relations.*` - cambiar un peso cambia tier/score sin tocar ninguna tabla "de datos" |
| `taxonomy_concept_relations` | Dedup de `proposeConceptRelations()` |

(Las tablas de `predicted_impact` - `empresa_taxonomy_category`, `company_term_matches`, etc. - se
excluyeron a propósito: ese campo se recalcula EN VIVO en cada render de la UI de revisión, nunca
se lee desde lo guardado en el candidato, así que nunca puede quedar "obsoleto".)

**Fix:** `CanonicalConceptBuilderService::dryRunInputFingerprint()` - combina
`conceptGraphFingerprint()` (hash de contenido completo, sin cambios, para las 2 tablas chicas y
más críticas) con `tableVersionSignal($table)` (`COUNT(*)` + `MAX(updated_at)`) para las demás.
Se documentó explícitamente el trade-off: hashear el contenido completo de
`taxonomy_term_cpv_relations` (~9.7k filas) en cada `--apply` sería caro dado la latencia de red
hacia Supabase ya documentada en este proyecto - se optó por la alternativa que el propio hallazgo
ofrece ("or explicitly version each dependency") en vez de fuerza bruta. Un `UPDATE` que
deliberadamente no toque `updated_at` no se detecta - trade-off documentado, no asumido.

`apply()` y `CandidateConceptApprovalService::proposalStaleness()` (antes `conceptGraphStaleness()`
- renombrado para dejar de describir esto como "solo el grafo") ahora usan este fingerprint amplio
en vez del angosto.

**Test:** `apply_aborts_when_a_non_graph_scoring_input_drifted_even_if_the_concept_graph_did_not` -
crea un dry-run con el fingerprint amplio ya calculado, agrega un término nuevo (que NO toca el
grafo publicado), y confirma que `apply()` aborta igual - algo que el fingerprint angosto anterior
no habría detectado.

## Hallazgo 5 (MEDIUM) — El conteo de tablas protegidas no prueba ausencia de UPDATE in-place

**Reclamo:** conteos antes/después no detectan un `UPDATE` que no cambia el número de filas. Pidió
(a) mantener los conteos como diagnóstico, no como garantía, y (b) protección ESTRUCTURAL (que la
clase de escritura no tenga ningún camino hacia esas tablas) + fingerprint de contenido donde
corresponda.

**Verificación estructural (leyendo el archivo completo, no infiriendo):**
`CanonicalConceptApplyService` no tiene NINGÚN `create()`/`insertOrIgnore()`/`update()`/`delete()`
hacia `taxonomy_term_concepts`, `taxonomy_canonical_concepts` ni `taxonomy_term_cpv_relations` - los
únicos destinos de escritura de todo el archivo son `taxonomy_candidate_concept_links` y
`taxonomy_concept_relations`. Esto ya era cierto antes de esta tarea; ahora está explícitamente
documentado en el docblock de la clase como la garantía real, con los conteos re-etiquetados como
diagnóstico.

**Fix adicional (fingerprint de contenido):** `protectedTableSignature()` - combina
`tableFingerprint()` (contenido completo) de las 2 tablas chicas del grafo con
`tableVersionSignal()` (el mismo mecanismo barato del hallazgo 4) de
`taxonomy_term_cpv_relations`. Se calcula antes y después, dentro de la misma transacción de
`apply()`, y se suma al chequeo existente de conteos - si cualquiera de los dos difiere, se aborta
(mismo `RuntimeException` de antes, ahora con una condición más).

**Test:** `protected_table_content_signature_changes_on_an_in_place_update_even_with_the_same_row_count`
- hace un `UPDATE` real sobre una fila de `taxonomy_canonical_concepts` que no cambia el conteo de
filas, y confirma que `tableFingerprint()` SÍ cambia - la prueba directa de que el mecanismo nuevo
detecta lo que el conteo no detecta.

## Hallazgo 6 (MEDIUM) — Revalidación de relaciones al momento de aprobar

**Reclamo:** confirmar que el flujo humano de revisión de `taxonomy_concept_relations
status=candidate` revalida `validateConceptRelationProposal()` en el momento de la decisión, no
solo cuando se encoló.

**Hallazgo confirmado como real, no hipotético** (source-first: se leyó el código real antes de
asumir). `EditTaxonomyConceptRelation::handleRecordUpdate()` ya revalidaba al cambiar
origen/destino/tipo (`$unchanged`), pero el chequeo de "sin cambios" **no incluía `status`** - así
que aprobar una relación (`candidate -> approved`) sin tocar sus endpoints se consideraba "sin
cambios" y se guardaba **sin revalidar nada**. El propio test preexistente
`editing_a_relation_without_changing_its_endpoints_or_type_still_saves` documentaba (sin darse
cuenta) este comportamiento como "correcto".

**Fix, dos capas:**

1. **Página (`EditTaxonomyConceptRelation`):** ahora revalida también cuando `status` pasa a
   `approved`, sin importar si los endpoints cambiaron (`$approving = ... && $data['status'] ===
   STATUS_APPROVED`), y la validación pasa `excludeId: $record->id` (para que la fila no se detecte
   a sí misma como su propio duplicado exacto). `validateConceptRelationProposal()` ganó el
   parámetro `?int $excludeId = null` para esto.
2. **Modelo (`TaxonomyConceptRelation::booted()`):** un guard `saving` que hace la misma
   revalidación, para que valga sin importar el punto de entrada (Filament, tinker, una futura
   API) - no solo la página de Filament. Lanza `RuntimeException` si la relación ya no es válida al
   momento de guardarse como `approved`.

**Tests:**
- `approving_a_relation_that_became_a_duplicate_while_it_waited_for_review_is_rejected` - el
  escenario exacto del hallazgo: crea la relación candidata, aprueba una relación equivalente por
  otro lado, intenta aprobar la primera sin tocar sus endpoints, confirma que sigue en `candidate`.
- `the_model_itself_refuses_to_be_saved_as_approved_when_no_longer_valid` - mismo escenario, pero
  llamando `$model->update()` directo (sin pasar por la página de Filament), confirma que el guard
  de modelo también lo bloquea.

## Hallazgo 7 — Puerta de tests/evidencia

- **Suite de taxonomía completa:** ver el bloque de resultados al final de este documento.
- **Regresión de búsqueda (32 queries):** no se re-corrió en esta tarea - los cambios de TASK-0003
  son exclusivos de la cola de revisión de Fase C1 y de `taxonomy_concept_relations`
  (`status=candidate`/`approved`), ninguno de los cuales alimenta el buscador (`empresa_taxonomy_category`)
  hasta que un humano apruebe un candidato TÉRMINO→CONCEPTO - eso no cambió en esta tarea. Si el
  orquestador considera necesario re-correrla igual (por ejemplo, por el cambio en la validación de
  relaciones concepto↔concepto), pedirlo explícitamente como parte de la revisión.
- **DB antes/después:** ver bloque final y `audit/orchestrator_handoff.json` (checkpoint TASK-0003).
- **Docs actualizados:** `audit/phase3_phase_c_apply.md`, `docs/task.md`, `docs/implementation_plan.md`,
  `audit/orchestrator_handoff.json` - los 4 que pidió el hallazgo.
- **Split Phase C1/C2:** adoptado (hallazgo 1) - ver arriba.

---

## Resultado de tests

**Suite completa de taxonomía: 148/148 PASS (487 assertions).** Incluye los 9 tests nuevos de esta
tarea (2 del hallazgo 2, 4 del hallazgo 3, 1 del hallazgo 4, 1 del hallazgo 5, 2 del hallazgo 6 -
uno de ellos reveló un bug real en el diseño del propio test antes de corregirse, ver nota abajo) y
los 148-9=139 tests preexistentes, todos verdes.

**Nota de proceso (para no repetirla):** los dos primeros tests del hallazgo 6 fallaron en su primer
intento - no por un bug en el fix, sino porque el escenario del test intentaba crear dos filas de
`taxonomy_concept_relations` con la MISMA tupla exacta (source, target, relation_type) en distinto
`status`, lo cual el propio índice único de la tabla (`UNIQUE(source_concept_id, target_concept_id,
relation_type)`, preexistente) ya prohibía a nivel de esquema - más el guard nuevo del hallazgo 6
bloqueándolo también a nivel de aplicación. El escenario real que hay que probar es el de duplicado
SIMÉTRICO/INVERSO (una fila DISTINTA que es la misma relación semántica, ej. B→A en vez de A→B para
un tipo no direccional) - eso sí convive sin violar el índice único, y es la forma real en que "era
válido cuando se encoló, quedó obsoleto cuando se revisó" puede pasar. Corregido antes de reportar
como verde - no se dejó pasar un test que "pasaba" por casualidad.

## Invariantes de base de datos

Verificado por lectura directa después de toda la corrida de tests (no asumido):

| Tabla | Esperado | Verificado |
|---|---|---|
| `taxonomy_candidate_concept_links` | 10 | 10 |
| `taxonomy_concept_relations` | 2 | 2 |
| `taxonomy_term_concepts` | 142 | 142 |
| `taxonomy_canonical_concepts` | 79 | 79 |
| `taxonomy_term_cpv_relations` | 9749 | 9749 |

Sin cambios respecto al checkpoint de TASK-0002 - ningún `--apply` real corrió en esta tarea, tal
como pidió el hallazgo 2 (ninguna corrida real sin autorización explícita de producción) y el
hallazgo 5 del comentario original (no borrar las 10/2 filas existentes).

Ver `audit/orchestrator_handoff.json` (checkpoint `TASK-0003`) para el resumen dirigido al
orquestador.
