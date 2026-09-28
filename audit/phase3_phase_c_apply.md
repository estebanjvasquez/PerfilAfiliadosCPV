# Phase C — `--apply` del Canonical Concept Builder

**Fecha:** 2026-09-28
**Rama:** `feature/upgrade-filament-v3`
**Autorización:** explícita del usuario para desarrollar Phase C completa.
**Estado previo:** `--apply` era un `return self::FAILURE` sin ninguna rama de escritura detrás
(`docs/task.md` secciones 6-7: "NOT STARTED para escritura real").

---

## 1 — La decisión de diseño central: qué escribe y qué NO

`--apply` materializa las propuestas del dry-run en las **colas de revisión**, nunca en el grafo
publicado:

| Destino | Estado en que se crea | ¿Publicado? |
|---|---|---|
| `taxonomy_candidate_concept_links` (término→concepto) | `pending` | No — espera revisión humana |
| `taxonomy_candidate_concept_links` (concepto nuevo, `suggested_concept_id=NULL`) | `pending` | No |
| `taxonomy_concept_relations` | `candidate` | No — nunca `approved` |
| `taxonomy_term_concepts` | **NUNCA SE TOCA** | — |

**`--apply` no publica.** Crear un vínculo término→concepto real sigue siendo exclusivamente
responsabilidad de `CandidateConceptApprovalService::approve()`, disparado por un humano desde
Filament. Esto implementa literalmente la salvaguarda de `docs/task.md` sección 15, punto 5:
*"Población controlada (dry-run revisado por humano → aprobación manual, nunca bulk-apply)"*.

Consecuencia importante y deliberada: **un candidato con tier `AUTO_ACCEPT` tampoco se publica solo.**
El tier no significa "publicar automáticamente", significa "encolar con confianza alta para que el
revisor lo despache rápido". Hay un test dedicado a esta propiedad
(`auto_accept_tier_is_still_only_enqueued_never_published`).

## 2 — Las 6 propiedades de seguridad

Ninguna es un estándar nuevo: son las mismas que ya tenía el camino manual (`approve()`), aplicadas
al camino automatizado.

1. **Transacción única** — `DB::connection('pgsql')->transaction()` envuelve todo el plan. O entra
   todo o no entra nada.
2. **Idempotencia explícita** — `taxonomy_candidate_concept_links` **no tiene índice único** sobre
   `(suggested_term_id, suggested_concept_id)`, así que re-correr `--apply` duplicaría la cola si no
   se chequeara a mano. Se chequea antes de cada insert. Además, **un par que un humano ya rechazó no
   se vuelve a encolar** — re-proponerlo sería pedirle al revisor que repita una decisión que ya tomó.
3. **Guarda de obsolescencia (stale fingerprint)** — el `concept_graph_fingerprint` se recalcula
   *dentro* de la transacción y se compara con el que traía el dry-run. Si alguien tocó conceptos o
   vínculos entre el scoring y la escritura, se aborta sin escribir: las propuestas se calcularon
   contra un estado que ya no existe.
4. **Tope de escrituras** — un plan mayor a `--max-writes` (default 500) aborta *antes* de escribir
   nada. Conservador a propósito.
5. **Audit log por fila** — cada candidato y cada relación creada deja una fila en
   `taxonomy_audit_log` con `actor_type=system` y `algorithm_version`, nunca una escritura silenciosa.
6. **Provenance** — cada candidato queda estampado con `taxonomy_state_fingerprint`. Phase C es el
   **productor que faltaba** del contrato que Phase B.1 dejó definido (sección 16 de
   `audit/phase3_phase_b1_review_workflow.md`): hasta hoy la columna existía y la UI sabía leerla,
   pero nada la escribía (`tracked=false` en todos los casos). Ahora sí.

Además, **verificación de tablas protegidas**: antes y después del plan se cuentan
`taxonomy_term_cpv_relations`, `taxonomy_term_concepts` y `taxonomy_canonical_concepts` dentro de la
misma transacción. Si alguna cambió, se lanza una excepción y **se revierte todo** — implementa la
salvaguarda global 1 ("no migración destructiva de taxonomía") como código, no como promesa.

## 3 — Los dos campos que Phase B.1 dejó pendientes

`audit/phase3_phase_b1_review_workflow.md` sección 16 formalizó el contrato de Phase C nombrando dos
campos faltantes. Ambos quedan resueltos:

- **`taxonomy_state_fingerprint`** → lo estampa `CanonicalConceptApplyService::apply()` en cada
  candidato creado, tomándolo del **mismo** resultado de `dryRun()` que produjo el scoring (no de una
  llamada nueva), para que el valor corresponda al estado contra el que se calcularon las propuestas.
- **`resolver_version`** → `CanonicalConceptApplyService::ALGORITHM_VERSION`
  (`canonical-concept-builder/phase-c-v1`), que viaja al `taxonomy_audit_log.algorithm_version` de
  cada escritura y al `provenance` de cada relación creada.

## 4 — Archivos

| Archivo | Qué es |
|---|---|
| `app/Services/Taxonomy/CanonicalConceptApplyService.php` | Nuevo. Toda la lógica de Phase C. |
| `app/Console/Commands/BuildTaxonomyCanonicalConcepts.php` | Modificado. `--apply` desbloqueado + `--max-writes`. |
| `tests/Unit/Taxonomy/CanonicalConceptApplyServiceTest.php` | Nuevo. 14 tests, uno por propiedad. |

`planFrom()` es una función **pura** (no consulta ni escribe la base), separada a propósito de
`apply()`: el comando la usa para mostrar exactamente qué se va a crear *antes* de abrir ninguna
transacción, y los tests la ejercitan sin tocar datos.

## 5 — Uso

```bash
# Solo ver el plan (sigue sin escribir nada - el dry-run de siempre)
php artisan taxonomy:build-canonical-concepts --dry-run --limit=25

# Materializar en las colas de revisión (Phase C)
php artisan taxonomy:build-canonical-concepts --apply --limit=25 --max-writes=100 --skip-audit
```

`--apply` implica `--dry-run` (necesita las propuestas, y usa **esa** corrida para que el
fingerprint estampado corresponda). Después de `--apply`, el trabajo humano continúa igual que antes
en el panel de Filament (`TaxonomyCandidateConceptLinkResource`), con las 3 decisiones de Phase B.1
ya existentes.

## 6 — Verificación

**Tests: 14/14 PASS** (`CanonicalConceptApplyServiceTest`, 41 assertions), todos dentro de
`DatabaseTransactions` sobre `pgsql` — nada queda persistido.

Cobertura por propiedad:

| Test | Propiedad verificada |
|---|---|
| `plan_excludes_reject_tier_candidates` | El tier REJECT no se encola |
| `plan_dedupes_repeated_term_concept_pairs` | Deduplicación dentro del plan |
| `plan_maps_new_concept_proposals_and_relations` | Mapeo de las 3 colas |
| `apply_enqueues_candidates_as_pending_with_the_fingerprint_stamped` | Propiedad 6 (provenance) |
| `auto_accept_tier_is_still_only_enqueued_never_published` | **La propiedad que define Phase C** |
| `apply_creates_relations_as_candidate_never_approved` | Relaciones nunca `approved` |
| `apply_is_idempotent_a_second_run_creates_nothing` | Propiedad 2 |
| `apply_does_not_requeue_a_pair_a_human_already_rejected` | Propiedad 2 (decisión humana respetada) |
| `apply_aborts_without_writing_when_the_graph_changed_since_the_dry_run` | Propiedad 3 |
| `apply_aborts_without_writing_when_the_plan_exceeds_the_write_cap` | Propiedad 4 |
| `apply_on_an_empty_plan_reports_nothing_to_apply` | Caso borde |
| `apply_writes_an_audit_row_per_created_candidate_as_system_actor` | Propiedad 5 |
| `apply_never_touches_the_protected_tables` | Salvaguarda global 1 |
| `apply_skips_a_relation_that_became_invalid_after_the_dry_run` | Re-validación TOCTOU |

### Línea base de datos (antes de cualquier escritura de Phase C)

```
taxonomy_term_cpv_relations              9749
taxonomy_canonical_concepts                79
taxonomy_term_concepts                    142
taxonomy_concept_relations                  0
taxonomy_candidate_concept_links            0
taxonomy_concept_relation_types             5
taxonomy_intent_types                       9
concept_graph_fingerprint: c80a040c46d632bcfb1ba9cc488716b30f3247d12ec278e8bfc8c065b8951acc
```

Idéntica a la documentada en `docs/task.md` sección 0 — sin drift desde el 2026-09-23.

### Suite de regresión de taxonomía

**140/140 PASS.** La corrida inicial dio 139 pass / 1 fail, pero el fallo **no lo causó Phase C**:
`TaxonomyCategoriesRelationManagerTest > selecting_a_family_directly_never_creates_a_row_for_it`
sorteaba el código de su fixture con `'CPV-'.random_int(10, 98)`, y la taxonomía CPV real ya ocupa
`CPV-10`..`CPV-48` (39 de los 89 valores) — **43.8% de probabilidad de colisión en cada corrida**,
con un `UniqueConstraintViolationException` ajeno a lo que el test verifica. Confirmado flaky
re-ejecutándolo: falló con `CPV-31` la primera vez y con `CPV-20` la segunda. Corregido moviendo el
fixture al espacio de 3 dígitos (`CPV-900`..`CPV-999`), donde no hay ningún código real (verificado:
0 filas con ese patrón). Test ahora 4/4 PASS.

## 7 — Primera corrida real de población controlada (2026-09-28)

`php artisan taxonomy:build-canonical-concepts --apply --limit=10 --max-writes=50 --skip-audit`

| Cola | Creados | Omitidos |
|---|---:|---:|
| `taxonomy_candidate_concept_links` (término→concepto) | 0 | 0 |
| `taxonomy_candidate_concept_links` (concepto nuevo) | 10 | 0 |
| `taxonomy_concept_relations` (`status=candidate`) | 2 | 0 |

**BEFORE / AFTER (verificado con consulta directa, no con el reporte del propio comando):**

```
taxonomy_candidate_concept_links     0 →  10   (cola de revisión - lo que Phase C SÍ puebla)
taxonomy_concept_relations           0 →   2   (todas en status=candidate, ninguna approved)
taxonomy_term_concepts             142 → 142   PROTEGIDA - sin cambios
taxonomy_canonical_concepts         79 →  79   PROTEGIDA - sin cambios
taxonomy_term_cpv_relations       9749 → 9749  PROTEGIDA - sin cambios
```

Verificación fila por fila:
- Los 10 candidatos están en `status=pending`, `tier=REVIEW`, con
  `taxonomy_state_fingerprint=c80a040c46d6...` estampado — **el productor del fingerprint ya
  funciona en datos reales**, no solo en tests.
- Las 2 relaciones están en `status=candidate` con `provenance` completa (evidencia, timestamp,
  `algorithm_version`).
- `taxonomy_audit_log`: **12 filas** (10 candidatos + 2 relaciones), **todas con
  `actor_type=system`** y `algorithm_version=canonical-concept-builder/phase-c-v1`. `user_id` NULL,
  correcto para una corrida de CLI.

**Idempotencia verificada en producción:** una segunda corrida idéntica creó **0 filas** y omitió las
10 ya encoladas. Conteos sin cambios (10 / 2). No es solo una propiedad testeada en aislamiento —
funciona contra la base real.

### Cómo revertir esta corrida, si hiciera falta

Las 10 filas nacieron en `pending` (nadie las revisó) y las 2 relaciones en `candidate`. Nada se
publicó, así que revertir es borrar esas filas — no hay que deshacer ningún efecto en el grafo ni en
el buscador. Se identifican sin ambigüedad por
`taxonomy_state_fingerprint='c80a040c46d632bcfb1ba9cc488716b30f3247d12ec278e8bfc8c065b8951acc'` y por
las 12 filas de `taxonomy_audit_log` con
`algorithm_version='canonical-concept-builder/phase-c-v1'`.

## 8 — Qué NO cambió (expectativa importante)

**Poblar la cola no cambia lo que devuelve el buscador.** El buscador consume el grafo publicado
(`taxonomy_term_concepts`, `empresa_taxonomy_category`) y Phase C deliberadamente no escribe ahí:
`taxonomy_term_concepts` sigue en 142. Para que esta población afecte resultados de búsqueda, un
humano tiene que aprobar candidatos en Filament — que es exactamente la salvaguarda que el proyecto
eligió tener, no una limitación accidental de esta entrega.
