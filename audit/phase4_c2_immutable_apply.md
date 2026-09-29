# Phase C2 — Reviewed Immutable Payload Application (TASK-0004)

**Fecha:** 2026-09-29
**Fuente:** Issue #2, comentario [`5886148283`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5886148283) (orquestador). Texto verbatim en
`docs/orquestador/tasks/0004-phase-c2-immutable-apply.md`.
**Precondición:** Phase C1 (TASK-0001 + TASK-0003) `APPROVED` en el comentario `5886125405`, HEAD revisado `ce11d36`.

## 1. Qué implementa esto

El contrato que `CanonicalConceptApplyService` (Phase C1) dejó explícitamente pendiente en su
propio docblock:

```
REVIEWED_PROPOSAL -> payload inmutable y con fingerprint -> APPLY(payload) -> VALIDATE server-side
-> COMMIT/ROLLBACK transaccional -> AUDIT
```

Archivos nuevos:

- `database/migrations/2026_09_29_193000_create_taxonomy_reviewed_proposals_table.php` — la tabla
  del payload inmutable.
- `app/Models/TaxonomyReviewedProposal.php` — modelo delgado (sin lógica).
- `app/Services/Taxonomy/ReviewedProposalService.php` — toda la lógica (`freeze()` + `apply()`).
- `app/Console/Commands/ApplyTaxonomyReviewedProposal.php` — CLI de `apply()`, equivalente
  operativo al `--apply` de Phase C1.
- `tests/Unit/Taxonomy/ReviewedProposalServiceTest.php` — cobertura completa (ver sección 5).

**No modifica ningún archivo de Phase C1/B3 existente** (`CanonicalConceptApplyService`,
`CandidateConceptApprovalService`, los recursos de Filament) - es aditivo. El camino de aprobación
inmediata que ya usa Filament para los 10 candidatos/2 relaciones de TASK-0001 sigue intacto y sin
tocar.

## 2. Máquina de estados

```
                    freeze()                                    apply()
  [candidato/relación   ────────────▶  taxonomy_reviewed_proposals   ────────────▶  candidato/relación
   status=pending/                        status=PENDING_APPLY                        publicado/rechazado
   candidate, SIN                                  │                                   (taxonomy_term_concepts /
   tocar]                                           │                                   taxonomy_canonical_concepts /
                                                     │                                   taxonomy_concept_relations)
                                          ┌──────────┴──────────┐
                                          │                     │
                                    (validación OK)      (validación falla)
                                          │                     │
                                          ▼                     ▼
                                  status=APPLIED          status=ABORTED
                                  (terminal, replay        (terminal, requiere
                                   idempotente)              un freeze() NUEVO)
```

Dos pasos, deliberadamente separados y ejecutables en momentos/por personas distintas:

### Paso 1 — `freeze(proposalType, targetId, decision, reviewer, decisionPayload)`

- Re-verifica autorización (`$reviewer->can('update', ...)`, misma policy que ya gobierna Filament),
  status del candidato/relación (`pending`/`candidate`), y que la decisión sea aplicable
  (`CREATE_NEW` solo si el candidato propone concepto nuevo; `MAP_TO_EXISTING` requiere
  `target_concept_id` EXPLÍCITO en el payload - nunca se infiere del `suggested_concept_id` del
  candidato, hallazgo 5: "no invent capabilities or mappings beyond the reviewed payload").
- Congela: `taxonomy_state_fingerprint` (= `CanonicalConceptBuilderService::dryRunInputFingerprint()`
  en ese instante) + `payload_fingerprint` (hash determinístico de los campos de decisión).
- Inserta una fila `taxonomy_reviewed_proposals` con `status=PENDING_APPLY` vía `insertOrIgnore()`
  contra un índice único parcial (`WHERE status='PENDING_APPLY'`) - DB-enforced, no TOCTOU, igual
  criterio que TASK-0003 hallazgo 3.
- Deja una entrada de auditoría de REVISIÓN (`actor_type=user`, **sin**
  `authorization_reference`/`target_environment` - esos campos no existen todavía en este punto).
- **Nunca escribe** en `taxonomy_term_concepts`, `taxonomy_canonical_concepts`, ni cambia el
  `status` del candidato/relación original. `freeze()` no es "aprobar", es "dejar constancia
  inmutable de que un humano ya decidió qué hacer".

### Paso 2 — `apply(proposalId, authorizationReference)`

Requiere una referencia de autorización de ESTA EJECUCIÓN, formato idéntico a
`CanonicalConceptApplyService::apply()` (no vacía, al menos un dígito). El ambiente objetivo se
auto-captura (`app()->environment()`), nunca se pasa. Puede correr mucho después de `freeze()`, por
alguien distinto - la distinción "revisión" vs "autorización de ejecución" (hallazgo 7) es
estructural, no solo documental.

Dentro de UNA transacción (`DB::connection('pgsql')->transaction`):

1. `lockForUpdate()` sobre la fila del payload → si `status=APPLIED`, replay idempotente (ningún
   write nuevo, devuelve el `application_result` guardado). Si `status=ABORTED`, terminal, se
   reporta tal cual (requiere un `freeze()` nuevo).
2. **Tamper check**: recomputa `payload_fingerprint` desde los campos guardados TAL CUAL están en
   la fila ahora mismo y lo compara contra el que se guardó al congelar. Un `UPDATE` manual de la
   fila (fuera de este servicio) rompe la igualdad → aborta.
3. **Staleness check**: recomputa `dryRunInputFingerprint()` YA y lo compara contra el
   `taxonomy_state_fingerprint` congelado. Si el estado de la taxonomía cambió desde `freeze()` →
   aborta (no reintenta el mismo payload - exige una revisión nueva).
4. **Re-verificación de entidades** (server-side, NO desde el payload): `lockForUpdate()` sobre el
   candidato/relación real. Si ya no está `pending`/`candidate` (alguien más lo resolvió por otra
   vía mientras tanto), o la entidad referenciada (concepto destino de `MAP_TO_EXISTING`) ya no
   existe → aborta.
5. Para `PUBLISH_RELATION`: re-corre `CanonicalConceptBuilderService::validateConceptRelationProposal()`
   completo (duplicado exacto/simétrico/vía inverso/ciclos) contra el estado REAL, no el congelado
   - mismo mecanismo que TASK-0003 hallazgo 6. El guard de modelo de `TaxonomyConceptRelation::booted()`
   revalida esto MISMO otra vez dentro de `save()` (defensa en profundidad redundante a propósito).
6. Recién ahí escribe: `MAP_TO_EXISTING`/`CREATE_NEW` → `taxonomy_term_concepts` (+ nuevo
   `taxonomy_canonical_concepts` si aplica) y `candidato.status=published`; `REJECT` →
   `candidato.status=rejected`; `PUBLISH_RELATION` → `relación.status=approved`.
7. Marca el payload `APPLIED` (o `ABORTED` con motivo) **en la misma transacción** que el write de
   taxonomía - o entran los dos o no entra ninguno.
8. Auditoría de EJECUCIÓN (`actor_type=system`, **con** `authorization_reference`/
   `target_environment`) - tanto en el camino exitoso como en cada aborto (un intento autorizado que
   se rechaza también queda auditado, hallazgo 7: "reconstruct exactly what was... skipped,
   rejected, or rolled back").

### Motivos de aborto (`abort_reason`, terminal - requiere un `freeze()` nuevo)

| Motivo | Cuándo |
|---|---|
| `TAMPER_DETECTED` | Los campos de decisión guardados no coinciden con su propio fingerprint. |
| `STALE_TAXONOMY_STATE` | El estado de la taxonomía cambió entre `freeze()` y `apply()`. |
| `ENTITY_MISSING` | El candidato/relación/concepto destino referenciado ya no existe. |
| `CANDIDATE_ALREADY_RESOLVED` | El candidato ya no está `pending` (otra vía lo resolvió mientras tanto). |
| `RELATION_ALREADY_RESOLVED` | La relación ya no está `candidate`. |
| `RELATION_INVALID_AT_APPLY_TIME` | Re-validación server-side de la relación falló (duplicado/ciclo/etc. surgido después de congelar). |

## 3. Idempotencia y concurrencia (hallazgo 4)

- **Doble `freeze()` del mismo candidato/relación**: bloqueado por el índice único parcial de la
  migración (`insertOrIgnore` devuelve 0 filas afectadas) - DB-enforced, no una lectura previa de
  la aplicación.
- **Doble `apply()` del mismo payload** (retry/reintento): `lockForUpdate()` + re-chequeo de
  `status` dentro de la transacción - el segundo ve `APPLIED` bajo el lock y devuelve
  `ALREADY_APPLIED` sin escribir nada de nuevo (ni mapeo, ni concepto, ni relación, ni fila de
  auditoría). Verificado en test (`apply_is_idempotent_a_second_call_does_not_duplicate_anything`):
  conteo de `taxonomy_audit_log` idéntico antes/después del replay.
- **El candidato se resolvió por otra vía entre `freeze()` y `apply()`** (ej. el camino inmediato
  existente de `CandidateConceptApprovalService`, u otro payload): re-verificación server-side del
  `status` real de la entidad (paso 4 de `apply()`) lo detecta y aborta
  (`CANDIDATE_ALREADY_RESOLVED`/`RELATION_ALREADY_RESOLVED`) - la garantía NO depende del
  fingerprint (que no cubre esto), sino de re-leer el estado real bajo lock.
- **Rollback transaccional real**: verificado con la misma técnica que
  `CandidateConceptApprovalServiceTest` (forzar una violación de longitud de columna a mitad de una
  transacción que toca las mismas tablas que `apply()`) - el write de `taxonomy_term_concepts` Y la
  transición de `status` del payload se revierten juntos; nunca queda un estado "aplicado a medias".

## 4. Invariante de no-filtración a búsqueda (hallazgo 6)

Auditado (no modificado - ya cumplía) tanto Laravel como el Worker sibling `perfilafiliados-mcp`:

- **Laravel** (`app/Console/Commands/BuildEmpresaSearchDocuments.php`, el comando que construye el
  índice de búsqueda): solo hace `JOIN taxonomy_term_concepts` (sin filtro de status porque esa
  tabla NO TIENE columna status - su sola existencia como fila ES la señal de "publicado", ya que
  el único camino de escritura hacia ella es una aprobación humana explícita, ahora vía DOS
  servicios: `CandidateConceptApprovalService` o `ReviewedProposalService::apply()`). Nunca
  referencia `taxonomy_candidate_concept_links` ni `taxonomy_concept_relations`.
- **`perfilafiliados-mcp`** (`src/canonical-expansion.ts`, `src/taxonomy-tools.ts`,
  `src/debug-search.ts` - repo sibling en `C:\Proyectos\GitHub\perfilafiliados-mcp`): mismo patrón,
  solo `JOIN taxonomy_term_concepts`/`taxonomy_term_cpv_relations status='approved'`. Cero
  referencias a `taxonomy_candidate_concept_links` o `taxonomy_concept_relations` en todo el
  directorio `src/` (`grep` exhaustivo, sin resultados).

Conclusión: las filas candidate/pending viven en tablas que **ninguno de los dos consumidores de
búsqueda lee jamás** - la separación de tablas (no una columna de status compartida) es la barrera
estructural, y ni Phase C1 ni Phase C2 la tocan.

## 5. Tests (hallazgo 9)

`tests/Unit/Taxonomy/ReviewedProposalServiceTest.php` (26 tests) cubre, uno por uno:

- Creación de payload inmutable + fingerprint (`freeze_stamps_a_payload_fingerprint_and_the_current_taxonomy_state_fingerprint`).
- Autorización re-verificada en `freeze()` y en `apply()` (dos tests dedicados).
- `freeze()` rechaza decisiones fuera de alcance (`CREATE_NEW` sobre un candidato que no propone
  concepto nuevo) y payloads sin campos explícitos (`no_invent_a_target_concept`).
- Doble `freeze()` bloqueado DB-enforced.
- Tamper detection (edición directa vía SQL crudo del `decision_payload`).
- Stale taxonomy state (el grafo cambia entre `freeze()` y `apply()`).
- Replay idempotente de `apply()` (sin duplicar nada).
- Concurrencia: candidato resuelto por otra vía entre `freeze()` y `apply()`; concepto destino
  borrado entre medias.
- `MAP_TO_EXISTING`, `CREATE_NEW`, `REJECT` (TERM_CONCEPT_LINK) y `PUBLISH_RELATION`/`REJECT`
  (CONCEPT_RELATION), incluida la revalidación server-side de relación (escenario simétrico de
  TASK-0003 hallazgo 6, ahora contra este pipeline).
- Rollback transaccional real ante un fallo a mitad de transacción.
- Auditoría de revisión (sin `authorization_reference`) vs. auditoría de ejecución (con ella) -
  dos tests dedicados a la distinción del hallazgo 7.
- Fingerprint determinístico independiente del orden de claves del array (función pura, sin DB).

**Resultado real de la corrida (2026-09-29, contra la instancia compartida de Supabase):**

```
Tests:    25 passed (68 assertions)
Duration: 556.42s
```

Corrida completa, sin fallos, tras corregir dos bugs reales encontrados en esta misma ronda de
verificación (documentados con transparencia, no ocultados):

1. **Bug real en `ReviewedProposalService`** (no en los tests): el fingerprint de tamper-detection
   usaba `reviewed_at->format('Y-m-d H:i:s.u')` (con microsegundos) tanto al congelar como al
   recomputar en `apply()` - pero el query builder de Laravel bindea un `DateTime` hacia Postgres
   con la precisión de segundo de la grammar, no microsegundos. El valor releído después del INSERT
   nunca coincidía con el computado antes de insertar, así que **todo** `apply()` (incluso uno
   limpio, sin manipulación) fallaba como `TAMPER_DETECTED` - detectado por el primer test más
   simple (`apply_map_to_existing_publishes_the_link...`) fallando inesperadamente. Corregido
   truncando la precisión usada en el fingerprint a segundos en ambos lados.
2. **Bug real en el test** `apply_publish_relation_aborts_when_a_symmetric_duplicate_was_approved_while_it_waited`:
   creaba la relación "en espera" ANTES que la simétrica aprobada - orden inverso al que
   `TaxonomyConceptRelationValidationTest` (TASK-0003) ya documenta como incorrecto en su propio
   comentario, porque el guard de `TaxonomyConceptRelation::booted()` no filtra por status al
   buscar duplicados simétricos - crear la simétrica-aprobada DESPUÉS de que la pendiente ya existe
   dispara el guard en la propia creación de la fixture, no en el `apply()` que el test quería
   aislar. Corregido invirtiendo el orden (mismo criterio que la suite ya aprobada).

## 6. Qué queda fuera de alcance de esta ronda (documentado, no oculto)

- **Wiring de UI de Filament** para que un humano dispare `freeze()` desde el panel (hoy solo
  invocable por servicio/comando/tinker). El modo de ejecución del comentario `5886148283` es
  "DESIGN + IMPLEMENT + TEST", sin mandato explícito de UI - se prioriza el contrato de servicio
  (igual que Phase C1 empezó como servicio+comando antes de cualquier UI). Queda como trabajo de
  seguimiento natural (Phase C2-UI), no autorizado ni implementado acá.
- **Ninguna aplicación real** contra los 10 candidatos/2 relaciones de TASK-0001 - se preservan sin
  tocar (verificado con conteo antes/después, sección 7 del handoff). `taxonomy:apply-reviewed-proposal`
  existe pero no se corrió contra ninguna fila real de producción/staging - la sección 8 del
  comentario del orquestador es explícita: abrir la fase no autoriza aplicar.
