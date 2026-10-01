# Tarea activa

**TASK-0006B** — Confirmación humana C2 + `CREATE_NEW` bilingüe + convergencia gobernada
(Issue #2 comentario `5936206843`). Abierta desde HEAD `42d3c6b`.

**Estado: READY_FOR_REVIEW (ronda 2 — corrección del re-audit `5938949812`).** Esta ronda
**implementó y probó el endurecimiento de concurrencia** del APPLY agrupado y **diseñó** (sin
ejecutar) la corrección de procedencia de confirmación de #492–#495. **Cero escrituras reales de
datos**, cero APPLY, cero publicación. Detalle completo en
[`audit/phase6_task0006b_human_confirmation_2026-10-01.md`](../../audit/phase6_task0006b_human_confirmation_2026-10-01.md)
(§10bis para esta ronda); texto verbatim en
[`tasks/0006b-human-confirmation.md`](tasks/0006b-human-confirmation.md); diseño correctivo en
[`designs/0006c-confirmation-provenance-correction.md`](designs/0006c-confirmation-provenance-correction.md).

### Bloqueo aceptado: la confirmación de #492–#495 no es procedencia humana válida

`confirm()` exige `Auth::id() === $confirmer->id` precisamente para que nadie pueda «confirmar en
nombre de» otra cuenta. En la ronda 1 **el agente ejecutó las confirmaciones desde consola**,
autenticando la cuenta #3 con `Auth::login()` y satisfaciendo así ese chequeo. El razonamiento con el
que se ejecutó —que la `confirmation_reference` al comentario del dueño bastaba— **era incorrecto**:
una referencia de gobernanza prueba **qué** decidió el dueño, no que el usuario #3 **ejecutó
personalmente** la confirmación. Eso derrota el invariante anti-suplantación que el mecanismo existe
para sostener.

| | Estado |
|---|---|
| **Contenido** de las decisiones 266–269 | **válido** (autorizado en `5936206843`) |
| **Mecanismo/código** de confirmación | **aceptado** por el re-audit |
| **Procedencia almacenada** en #492–#495 | **INVÁLIDA / `CORRECTION_REQUIRED`** |

**Qué hizo esta ronda, y qué no:** la metadata de confirmación de #492–#495 **no se tocó** (el trigger
la hace inmutable y la reparación exige una autorización humana nueva); **no** se confirmó nada por
consola; **no** se confirmaron #629/#630 ni #631/#632. Solo cambiaron código, tests y documentación.

### Endurecimiento de concurrencia (implementado y probado)

`apply()` bloqueaba primero la fila de entrada y después todas las del grupo, así que dos `apply()`
concurrentes entrando por hermanos distintos podían tomar locks opuestos y quedar en **deadlock** de
PostgreSQL. Postgres lo detecta y revierte una —nunca se publicaba de más—, pero «una peticion muere
con deadlock» es más débil que el contrato pedido.

Corregido: el **primer** lock de la transacción es ahora un **advisory lock de transacción** con clave
derivada del `proposal_group_id`, **idéntica para todos los hermanos**, tomada **antes de cualquier
lock de fila**. La espera circular desaparece por construcción; el segundo hermano espera, ve el grupo
aplicado y devuelve `ALREADY_APPLIED`. Las propuestas **sin** grupo no toman ningún advisory lock.

**Tests: 7/7** (`ReviewedProposalGroupLockingTest`, 26 assertions), **sin ningún APPLY real**: clave
idéntica entre hermanos, estable y distinta entre grupos; **exclusión mutua real medida con dos
conexiones** a Postgres (con control negativo); que `apply()` **efectivamente** toma el lock,
consultado en `pg_locks`; que el camino sin grupo no cambió; y que el par sigue convergiendo en **un
solo** concepto. Se declara el límite: no se simula una carrera con dos procesos PHP (exigiría
commitear fixtures reales, no autorizado); la ausencia de deadlock se demuestra por construcción más
la exclusión mutua medida.

### Diseño correctivo (solo diseño, pendiente de autorización)

Asimetría deliberada: **anular** una confirmación (los cuatro campos a NULL) será posible **solo** bajo
una autorización de corrección declarada y con rastro obligatorio; **reasignar** una confirmación
seguirá **prohibido por el trigger, sin excepción**. Así el único desenlace de una corrección es
«vuelve a estar sin confirmar», y la única forma de volver a confirmar es la acción autenticada de
Filament: **ninguna ruta permite inventar un confirmador**. La confirmación mala no se borra, se mueve
a un rastro de anulación con snapshot + fila de auditoría propia. El diseño incluye además la
recomendación de que `confirm()` **rechace** todo canal que no sea `http`, cerrando el camino que
produjo la atribución inválida.

| Decisión del dueño | Representación |
|---|---|
| 266–269 → **CONFIRMAR** `CONTEXT_REQUIRED` | #492–#495 **`HUMAN_CONFIRMED`** por el revisor #3, decisiones y fingerprints **intactos** |
| 270/271 → un concepto, ES `refinería` / EN `refinery` (opción A) | grupo `043fce22…`: propuestas **#629** (cand. 270) + **#630** (cand. 271), una sola identidad bilingüe |
| Relaciones #61 y #62 → **REJECT** | propuestas **#631** y **#632**, `PENDING_APPLY`, relaciones intactas en `candidate` |

**Lo que se construyó (A–D):**

- **A — confirmación humana exigible.** Migración **estrictamente aditiva** (columnas nulables + un
  booleano `DEFAULT FALSE` + CHECK + 2 índices + trigger; cero `DROP`, cero `ALTER TYPE`). Nueva
  operación `confirm()` que escribe **solo** las columnas de confirmación: `reviewer_id`,
  `reviewed_at`, la decisión, el snapshot de fuente y los **dos** fingerprints quedan intactos — el
  `payload_fingerprint` se computa sobre una lista fija de 9 campos de decisión que no incluye ninguna
  columna nueva, así que confirmar **no puede** invalidar la tamper-detection (verificado en vivo: los
  8 fingerprints reales idénticos). Idempotente, concurrency-safe, solo el usuario **autenticado** y
  con el permiso `update` del tipo de origen, sin poder confirmar «en nombre de» otra cuenta, con el
  canal **auto-capturado** (no falseable) y un evento de auditoría propio (`field=confirmed_at`) que
  separa PREPARED/FROZEN → HUMAN_CONFIRMED → APPLIED. Un trigger de base de datos impide apagar el
  marcador o sobrescribir una confirmación.
- **Regla de compatibilidad (exacta):** `apply()` consulta **solo** `requires_human_confirmation`,
  creado con `DEFAULT FALSE`, así que **toda** propuesta congelada antes de TASK-0006B sigue siendo
  aplicable igual que antes — su procedencia de revisión original ya satisface el requisito de
  revisión humana (#420/#421/#422/#491 sin tocar). Que `ReviewedProposalServiceTest` pase **41/41 sin
  editar una línea** es la prueba. La compuerta **rechaza** en vez de abortar: abortar es terminal y
  re-congelar está bloqueado por el índice único parcial, así que un `apply()` prematuro dejaría la
  decisión humana irrecuperable.
- **Backfill de #492–#495** determinístico y auto-validante (ids + marcador de atribución presente en
  `context_reason` + `PENDING_APPLY`), auditable (4 filas de bitácora) y limitado a identificar el
  requisito: **nunca** tocó una decisión.
- **B — UX de confirmación** en Filament: muestra la decisión original, su payload y la procedencia
  **real** (dice que la preparó un agente, sin presentar al titular de la cuenta como autor), exige
  referencia de gobernanza + casilla deliberada, muestra confirmador y fecha, y **sigue sin ningún
  botón de APPLY/Publicar**.
- **C — `CREATE_NEW` bilingüe:** `canonical_name_es` **y** `canonical_name_en` explícitas, sin
  traducción ni fallback (una identidad parcial se **rechaza**), sugerencia del Builder solo como
  evidencia, fingerprint cubriendo **las dos** nombres, y compatibilidad hacia atrás **probada** para
  los payloads monolingües históricos.
- **D — convergencia:** el grupo es **una fila por candidato** unidas por `proposal_group_id`, no una
  fila con una lista. Razón dura: con una sola fila el segundo candidato quedaría **fuera** del índice
  único parcial y nada impediría congelarle otra propuesta en paralelo — la carrera de concepto
  duplicado. `apply()` bloquea el grupo entero, crea **UN** concepto y adjunta los dos términos en una
  transacción, reutilizando el concepto si un hermano ya se aplicó; el drift en **cualquiera** de los
  dos orígenes aborta todo. **No** es un rediseño N:M: la cardinalidad TÉRMINO→CONCEPTO y la semántica
  de búsqueda no cambian.
- Las capacidades son **alcanzables desde la UI real** (lección del re-audit `5930560603`): selector
  monolingüe/bilingüe, los dos campos de nombre, y un multi-select de convergencia que lista los demás
  candidatos `pending` con su idioma y marca con `↔` los de igual `canonical_term` como **sugerencia,
  no filtro**.

**Decisión de diseño declarada:** las propuestas nuevas **#629–#632** quedaron
`requires_human_confirmation = true` (preparadas por el agente). El **contenido** lo fijó el dueño en
el comentario, pero la **ejecución** del freeze la hizo el agente, y marcarlas solo **agrega** una
compuerta: no publica nada, no puede perder datos, y TASK-0007 no está abierta. Si el orquestador
prefiere tratarlas como decididas directamente por el humano, la resolución es **aditiva**: un clic en
«Confirmar decisión preparada». **No hace falta borrar ni re-congelar nada** — justo el hueco que esta
tarea cerró.

**Tests:** 34/34 nuevos de servicio (167 assertions) + 7/7 nuevos de UI, y las regresiones protegidas
**sin editar**: `ReviewedProposalServiceTest` 41/41, `TaxonomyReviewedProposalResourceTest` 11/11,
`TaxonomyConceptRelationValidationTest` 11/11, `TaxonomyConceptExplorerTest` 23/23,
`TaxonomyConceptRelationReviewTest` 14/14, `TaxonomyCandidateConceptLinkResourceTest` 4/4,
`TaxonomyCandidateConceptLinkReviewTest` 13/14. **El único fallo** es el gap local preexistente de
`ext-intl` en la regresión de nested-signals de TASK-0002, verde en staging y ajeno a este diff.

**Staging:** HEAD desplegado `1617e72` (run «Deploy a Contabo» `success` para ese sha exacto); smoke
`/` 200, `/admin/login` 200, y las tres pantallas de taxonomía 302 → login 200. **Sin 500/503.** Esta
sesión **no** tiene clave SSH al host, así que el HEAD se verificó por el run del workflow y no por
inspección directa; el riesgo de 500 en la pantalla autenticada queda cubierto por los tests que
abren la página de detalle con `assertOk()` (11/11 **después** del cambio de infolist).

**Estado protegido:** candidatos 10, relaciones 2, `term_concepts` **142**, `canonical_concepts`
**81**, TERM→CPV **9749**, propuestas revisadas **8 → 12**, **aplicadas 0**. Cero residuo de tests.

**Sigue NO autorizado:** APPLY/publicación (TASK-0007 sin abrir), producción, merge a `main`, y
cualquier borrado/re-freeze de #492–#495.

## TASK-0006 (ronda previa, histórico)

**TASK-0006** — Revisión humana de la cola real (Issue #2 comentarios `5933152293` → re-audit
`5934324928`). HEAD revisado `bd70ac7`.

**Estado: `CORRECTIONS_REQUIRED` / COMPUERTA DE GOBERNANZA HUMANA — ESPERANDO DECISIONES HUMANAS.**
Cero APPLY, cero publicación, cero cambios de código, y **cero escrituras reales en esta ronda de
corrección** (el comentario lo prohíbe expresamente). Detalle completo —tres bloqueos y el hueco de
workflow reportado— en la **sección 10** de
[`audit/phase6_task0006_queue_review_2026-10-01.md`](../../audit/phase6_task0006_queue_review_2026-10-01.md);
texto verbatim en [`tasks/0006-queue-human-review.md`](tasks/0006-queue-human-review.md).

| Ítem | Estado gobernado actual |
|---|---|
| 266 `exploration` | propuesta #492 — **`AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED`** |
| 267 `upstream` | propuesta #493 — **`AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED`** |
| 268 `midstream` | propuesta #494 — **`AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED`** |
| 269 `downstream` | propuesta #495 — **`AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED`** |
| 270 `refinery` + 271 `refinería` | **SIN DECIDIR** — requiere decisión humana de diseño (bloqueo 2) |
| Relaciones 61 y 62 | **SIN DECIDIR** — requiere política humana de curación (bloqueo 3) |
| #420 / #421 / #422 / #491 | revisadas por humano — **protegidas** |

**BLOQUEO 1 — las decisiones 266–269 no son decisiones humanas.** El orquestador no las acepta como
tales: `reviewer_id=3` / `actor_type=user` dice estructuralmente que el usuario #3 revisó, mientras la
nota durable en `context_reason` dice que la preparó el agente, y **una auditoría de gobernanza no
puede apoyarse en una atribución contradictoria**. El análisis de los cuatro términos se conserva
**como recomendación/evidencia**, aceptado explícitamente en ese carácter. Las 4 propuestas se
preservan intactas y marcadas; el revisor humano debe inspeccionar y confirmar, rechazar o revisar
cada una antes de cerrar TASK-0006.

**Hueco de workflow REPORTADO (condicional del bloqueo 1), sin bypass.** Auditado leyendo esquema y
código: **hoy C2 no puede registrar la confirmación humana de una propuesta ya congelada sin borrado o
re-freeze.** No hay columna de segundo actor (`confirmed_by`/`confirmed_at`); los estados son solo
`PENDING_APPLY`/`APPLIED`/`ABORTED`; `ReviewedProposalService` solo expone `freeze()` y `apply()` y
`abort()` es privado y solo alcanzable desde la ruta de APPLY; y el índice único parcial
`one_pending_per_candidate` **impide por base de datos** un segundo `PENDING_APPLY` para el mismo
candidato. Editar `decision_payload` a mano rompería `payload_fingerprint`. Se reportaron cuatro
opciones (extensión aditiva con `confirm()` —la única no destructiva y exigible—; limpieza autorizada +
re-freeze; entrada en `taxonomy_audit_log` sin compuerta; solo documental) y **ninguna se implementó ni
se eligió**: ninguna está autorizada y es decisión de diseño. Ver sección 10.3 del audit.

**BLOQUEO 2 — 270/271.** El orquestador confirma el análisis y prohíbe crear conceptos duplicados y
usar un APPLY como atajo de secuenciación. Decisión humana requerida: (A) extender `CREATE_NEW` para
congelar nombres canónicos ES + EN explícitos con flujo de identidad bilingüe revisado, o (B) definir
otra secuencia gobernada para crear el concepto y luego mapear el alias de traducción.

**BLOQUEO 3 — relaciones 61/62.** Dejarlas sin decidir fue correcto. Requieren decisión humana
explícita o una regla de curación declarada; hasta entonces siguen `candidate` y sin congelar.

**Estado protegido (sin cambios en esta ronda):** candidatos 10, relaciones 2, `term_concepts` 142,
`canonical_concepts` 81, `TERM→CPV` 9749, propuestas revisadas **8**, **aplicadas 0**. No se tocaron
#492–#495, 270, 271 ni las relaciones 61/62.

**Sigue NO autorizado:** APPLY/publicación (TASK-0007 sin abrir), producción, merge a `main`, y
cualquier limpieza/borrado/re-freeze de #492–#495 sin autorización separada y explícita.

## TASK-0006A (cerrada, histórico)

**Explorador de conceptos para revisión humana + diagnóstico de mapeo**
(Issue #2 comentario `5929287629`). Abierta desde HEAD `a4d8b2f`.

**Estado: CLOSED / APPROVED** (comentario `5933152293`, HEAD revisado `d532a0b`). Ambas correcciones
del re-audit `5930560603` quedaron `PASS`.

**Contexto:** TASK-0006 (revisión humana de la cola real) está EN CURSO y quedó **pausada** para los
candidatos sin resolver, porque el revisor encontró una limitación real de UX/diagnóstico revisando
el término `pipeline`: el selector de MAP_TO_EXISTING solo ofrecía los duplicados sugeridos por el
Builder y truncaba la búsqueda a 20 sin avisar, lo que podía crear falsa confianza de que las pocas
opciones visibles eran las únicas válidas.

**Estado:** READY_FOR_REVIEW (ronda 2). El re-audit del comentario `5930560603` bloqueó por una razón
correcta: el servicio informaba `total`/`truncated` pero **los controles reales de Filament llamaban
solo a `searchOptions()`**, que los descartaba — el revisor veía como máximo N opciones sin señal de
que hubiera más y sin forma de alcanzarlas, y lo mismo con las categorías CPV omitidas. Corregido:

- **Explorador paginado real:** campo de búsqueda + acciones Página anterior/siguiente + línea de
  estado **renderizada** ("Mostrando X-Y de N coincidencias, página P de T"). Se quitó
  `->searchable()` del `Select` para no dejar un camino paralelo con tope silencioso; el `Select`
  lista la página actual, así que toda coincidencia es alcanzable. El tamaño de página es 25 y
  estable: **no** se subió al tamaño del catálogo, para que siga siendo correcto cuando crezca.
- **Categorías CPV inspeccionables:** filtro por código/nombre + paginación propia. Se informan a la
  vez el total alcanzable real y el que coincide con el filtro, así que filtrar no puede hacer
  parecer que hay menos.
- **4 tests de nivel UI** sobre el formulario real (`mountTableAction`/`setTableActionData`/
  `callMountedTableAction`) que prueban lo que faltaba: que el total y la página se **renderizan**,
  que un concepto solo presente en la página 2 se elige y se congela, y que una categoría fuera del
  preview inicial se inspecciona paginando o filtrando. Suite: **23/23**.

Semántica sin cambios: MAP_TO_EXISTING sigue siendo un concepto, CONTEXT_REQUIRED sigue en cero
mapeos, sin multi-select TÉRMINO→CPV, sin cambios de búsqueda/ranking/cardinalidad, sin APPLY.

Detalle completo en
[`audit/phase6_task0006a_concept_explorer_2026-10-01.md`](../../audit/phase6_task0006a_concept_explorer_2026-10-01.md);
texto verbatim en [`tasks/0006a-concept-explorer.md`](tasks/0006a-concept-explorer.md).

Lo entregado:

- **A — descubrimiento:** nuevo `ConceptExplorerService` (solo lectura). La búsqueda recorre los
  **79 conceptos activos** completos por nombre ES, nombre EN, término miembro y alias; filtra
  `status=active` (corrige un defecto real: antes se ofrecían conceptos `merged`); el tope pasó de 20
  silencioso a 50 **informando siempre el total real y el overflow**; etiquetas con `#id` + tipo/
  dominio para distinguir homónimos. Las recomendaciones del Builder siguen visibles, etiquetadas
  como evidencia y distintas del catálogo buscable. Sin hardcoding de `pipeline`.
- **B — diagnóstico:** panel de solo lectura con identidad del concepto, términos con identidad
  aprobada, alias, categorías CPV alcanzables (código + Grupo/Familia/Categoría + breadcrumb),
  impacto predicho de empresas, procedencia y advertencias de huecos de datos. Todo de datos ya
  gobernados; nada inventado; nada truncado en silencio.
- **C — polisemia:** guía explícita MAP_TO_EXISTING vs CONTEXT_REQUIRED, más un selector de
  inspección solo para CONTEXT_REQUIRED que es **pura evidencia** y nunca entra al payload congelado.
  Sin ninguna regla automática: la decisión sigue siendo humana.
- **D — cardinalidad:** auditada ANTES de implementar. El esquema admite N conceptos por término,
  pero el pivote no tiene dónde representar contexto y el índice de búsqueda expande por hermanos de
  concepto sin compuerta de contexto, así que mapear a N conceptos haría un fan-out incondicional de
  categorías CPV. **La arquitectura NO lo soporta de forma segura** → la UI queda de un solo
  concepto y el mapeo contextual multi-concepto se registra como tarea futura con compuerta nueva.
- **Sin migraciones ni cambios de esquema.** Cero cambios en semántica de búsqueda o taxonomía
  publicada.

**Lo que esta tarea NO hizo, por instrucción explícita:** no modificó las 3 revisiones ya congeladas,
no procesó los 7 candidatos restantes ni las 2 relaciones, y no ejecutó ningún APPLY.

## Estado de la cola real (estado vivo)

Las 10 filas fuente originales siguen existiendo (`263`–`272`), igual que las 2 relaciones candidatas
(`61`, `62`). Tras TASK-0006B hay **12 propuestas congeladas**, ninguna aplicada, y **la cola real ya
no tiene ítems sin decidir**:

| Propuesta | Origen | Decisión | Confirmación humana | `applied_at` |
|---|---|---|---|---|
| #420 | cand. 263 | `CONTEXT_REQUIRED` | no requiere (revisión humana original) | NULL |
| #421 | cand. 264 | `CONTEXT_REQUIRED` | no requiere | NULL |
| #422 | cand. 265 | `CONTEXT_REQUIRED` | no requiere | NULL |
| #491 | cand. 272 (`pipeline`) | `MAP_TO_EXISTING` → #2890 `oleoducto / oil pipeline` | no requiere | NULL |
| #492 | cand. 266 (`exploration`) | `CONTEXT_REQUIRED` | **procedencia INVÁLIDA — `CORRECTION_REQUIRED`** | NULL |
| #493 | cand. 267 (`upstream`) | `CONTEXT_REQUIRED` | **procedencia INVÁLIDA — `CORRECTION_REQUIRED`** | NULL |
| #494 | cand. 268 (`midstream`) | `CONTEXT_REQUIRED` | **procedencia INVÁLIDA — `CORRECTION_REQUIRED`** | NULL |
| #495 | cand. 269 (`downstream`) | `CONTEXT_REQUIRED` | **procedencia INVÁLIDA — `CORRECTION_REQUIRED`** | NULL |
| #629 | cand. 270 (`refinery`) | `CREATE_NEW` ES `refinería` / EN `refinery` — grupo `043fce22…` | pendiente | NULL |
| #630 | cand. 271 (`refinería`) | `CREATE_NEW` ES `refinería` / EN `refinery` — grupo `043fce22…` | pendiente | NULL |
| #631 | relación 61 | `REJECT` | pendiente | NULL |
| #632 | relación 62 | `REJECT` | pendiente | NULL |

Las 12 son **registros congelados protegidos**: ningún paso de código, test o despliegue puede
mutarlos, borrarlos ni re-congelarlos sin autorización de limpieza separada y explícita.

**#629 y #630 son UN grupo bilingüe**: `apply()` (en una tarea futura separadamente autorizada) creará
**UN** solo concepto canónico y adjuntará los dos términos en una transacción — nunca un concepto por
candidato.

**La #491 la congeló el humano con la UI mejorada mientras se implementaba esta corrección**, y es la
validación real del objetivo de TASK-0006A: `pipeline` era justamente el término polisémico que
originó la tarea. El revisor creó dos conceptos específicos (`oleoducto / oil pipeline` #2890 y
`gasoducto / gas pipeline` #2891, vía el CRUD administrativo de conceptos, fuera de C2) y mapeó el
término a uno de ellos, en lugar de forzarlo contra una opción inadecuada. Esta sesión no tocó nada
de eso. Detalle en la sección 10 del audit.

**Invariantes del estado vivo:**

| Tabla | Valor | Nota |
|---|---|---|
| `taxonomy_candidate_concept_links` | 10 | sin cambios |
| `taxonomy_concept_relations` | 2 | sin cambios |
| `taxonomy_term_concepts` | 142 | sin cambios — **nada publicado** |
| `taxonomy_canonical_concepts` | **81** | era 79; +2 conceptos creados por el humano, ninguno publicado en `taxonomy_term_concepts` (0 filas cada uno) |
| `taxonomy_term_cpv_relations` | 9749 | sin cambios |
| `taxonomy_reviewed_proposals` | **12** | 4 sin requisito + 4 confirmadas + 4 nuevas pendientes de confirmar |
| Propuestas aplicadas | **0** | ningún APPLY ocurrió nunca |

**Siguen NO autorizados:** APPLY/publicación, producción, merge a `main`. TASK-0007 (APPLY) sigue sin
abrir. Las tres decisiones humanas de gobernanza que bloqueaban TASK-0006 llegaron en el comentario
`5936206843` y quedaron representadas en TASK-0006B (ver arriba).

## TASK-0005 (cerrada, histórico)

**UI de revisión humana C2 en Filament + hardening del trigger de despliegue**
(Issue #2 comentario `5914793857`). Abierta desde HEAD `63cf811`.

**Estado: CLOSED / APPROVED** (comentario `5928773263`, HEAD revisado
`dbd410a3b5603bba8acc48091ebb0601f411f3bd`). Ambas correcciones del re-audit `5917275454` quedaron
`PASS`; evidencia de tests y despliegue aceptada; invariantes y gates heredados confirmados sin
invalidar.

## Riesgo adyacente registrado para una tarea futura (bloqueante antes de producción)

El comentario de cierre confirmó la observación que esta sesión registró por cuenta propia y le puso
un gate explícito: editar endpoints/tipo de una relación ya `approved` puede mutar taxonomía
publicada, porque el guard de `TaxonomyConceptRelation::booted()` solo revalida cuando el status
**pasa a** `approved` (`isDirty('status')`). Es **preexistente** y quedó fuera de las dos correcciones
pedidas, así que no bloqueó el cierre de TASK-0005 — pero el orquestador indicó que **DEBE**
resolverse antes de habilitar edición administrativa general de relaciones publicadas o antes de
cualquier rollout a producción de esta UI de gobernanza de taxonomía. No implementado en esta ronda:
no fue pedido y haría falta una tarea propia.

## Historial del re-audit `5917275454` (ambas correcciones, ahora PASS)

Los dos hallazgos corregidos fueron:

1. **Fuga de autorización entre tipos de origen** en las propuestas revisadas: la policy usaba OR,
   así que quien veía candidatos podía abrir propuestas de relación y viceversa, y el listado no
   estaba filtrado. Ahora `view()` resuelve por `proposal_type` y `getEloquentQuery()` filtra las
   filas por los tipos que el usuario puede ver (lo que también cierra la URL directa, porque
   `ViewRecord` resuelve contra ese mismo query). Super-admin sin bypass, por el modelo de permisos
   normal.
2. **Edit/Delete legacy sobre relaciones en revisión C2**: un revisor podía mutar o borrar una
   relación candidata al margen del ciclo inmutable, destruyendo evidencia en lugar de producir un
   REJECT congelado. Nuevo predicado `isUnderC2Review()` usado por `canEdit()`/`canDelete()` (cierra
   acción **y** ruta `/edit` con 403) más un guard `deleting` a nivel de modelo. El CRUD
   administrativo se conserva acotado a filas cuyo ciclo ya terminó.

**Tests del re-audit: 138 passed, 1 failed** (el fallo es el gap local preexistente de `ext-intl`,
verde en staging). Incluye 4 tests preexistentes de `TaxonomyConceptRelationValidationTest`
actualizados porque codificaban el comportamiento que la corrección 2 pidió cerrar — cada uno
preservando la propiedad que protege, documentado en el archivo. Invariantes 10/2/142/79/9749/0 sin
cambios.

Detalle completo de ambas correcciones en la sección 10 del audit. Estado de la ronda 1
(implementación, hardening del workflow, despliegue y smoke iniciales) en
[`audit/phase5_task0005_c2_review_ui_2026-09-30.md`](../../audit/phase5_task0005_c2_review_ui_2026-09-30.md);
texto verbatim de la tarea en
[`tasks/0005-c2-human-review-ui.md`](tasks/0005-c2-human-review-ui.md).

Resumen de lo entregado:

- **A/B — UI de freeze:** una acción única `freezeReview` por resource (candidatos: `MAP_TO_EXISTING`
  / `CREATE_NEW` / `CONTEXT_REQUIRED` / `REJECT`; relaciones: `PUBLISH_RELATION` / `REJECT`), cableada
  exclusivamente a `ReviewedProposalService::freeze()`. Cada decisión exige input humano explícito;
  `CREATE_NEW` no tiene fallback implícito al nombre sugerido por el Builder (la sugerencia se muestra
  solo como evidencia). Las acciones legacy `approve`/`reject`/`resolveNewConcept` fueron removidas.
- **C — visibilidad:** nuevo `TaxonomyReviewedProposalResource` de solo lectura (List + View, sin
  páginas de create/edit/delete) con origen, decisión, revisor, timestamps, referencia de
  autorización, entorno objetivo, fingerprints y estado de ejecución.
- **D — frontera de autorización:** cero botón/ruta de Apply/Publish alcanzable por un revisor.
  `apply()` sigue siendo un paso separado, solo por servicio/artisan.
- **E — guardas legacy intactas:** ninguna guarda de TASK-0004 fue debilitada;
  `CandidateConceptApprovalService` sigue sin poder publicar rodeando C2.
- **F/H — tests:** 24/25 en la UI nueva (el único fallo es el gap local preexistente de `ext-intl`,
  verde en staging) + 99/99 sin regresiones en los archivos relacionados no editados. Todo con
  fixtures desechables dentro de `DatabaseTransactions`, en entorno local seguro — **no** se reusó el
  procedimiento de contenedor efímero sobre los bind mounts de staging en vivo.
- **G — hardening del deploy:** `paths-ignore` (`docs/**`, `audit/**`, `**.md`) en
  `deploy-contabo.yml`, con la semántica todo-o-nada y los casos representativos documentados en el
  propio workflow. `tests/**` NO se ignora, por decisión deliberada y conservadora.
- **Invariantes:** 10/2/142/79/9749/0 verificados antes de empezar, después de los tests y después del
  despliegue. **Los 10 candidatos y 2 relaciones reales quedaron intactos** — ningún
  freeze/apply/reject/context-resolve corrió contra ellos.

Ver `PROTOCOL.md` antes de tocar esta tarea. Precondiciones verificadas: Phase C1 (TASK-0001 +
TASK-0003) sigue `APPROVED` (comentario `5886125405`, HEAD revisado `ce11d36`); TASK-0004 / Phase C2
sigue `CLOSED/APPROVED` con despliegue de staging `PASS` (comentarios `5914592664` / `5914676402`).

## TASK-0004 (cerrada, histórico)

**Phase C2: Reviewed Immutable Payload Application** (Issue #2 comentario `5886148283`).
**Implementación C2: CLOSED/APPROVED. Despliegue a staging + validación: PASS** (comentario
`5914592664`, confirmando la ronda 6; HEAD `63cf811` aceptado como checkpoint documental en
`5914676402`).

Comentarios del hilo completo (todos con texto verbatim en
[`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md)):
`5890113782`, `5890195271`, `5892711739`, `5909267134`, `5913324183`, `5913574545`, `5914592664`.

## Ronda 7 (comentario `5914592664`) — confirmación, sin cambios de código

El comentario confirma `PASS` para el despliegue/validación de staging de la ronda 6 (ningún cambio
adicional de código ni de evidencia requerido) y dos instrucciones para más adelante, ninguna abierta
todavía:

1. **Hardening operacional del procedimiento de test-en-staging** (no reabre TASK-0004): antes de
   volver a correr la suite completa contra el host compartido de staging, hace falta un camino de
   ejecución de tests que no pueda escribir en los bind mounts de la app en vivo (`bootstrap/cache`,
   `storage`, etc.), forzar `APP_ENV=testing` a nivel de proceso/contenedor, no correr scripts de
   `package:discover` contra el filesystem del contenedor que sirve tráfico, y agregar guardas de
   smoke-test antes/después + limpieza automática de contenedores efímeros. **No implementado en esta
   ronda** - es trabajo operacional para cuando se vuelva a necesitar correr tests en staging, no algo
   pedido para ejecutar ahora.
2. **Próxima fase funcional (wiring de la UI de revisión humana C2 en Filament):** el propio
   comentario dice explícitamente que solo se abre "awaiting/opened only by explicit orchestrator
   instruction" - **todavía no está abierta**. El usuario, en su mensaje de esta ronda, instruyó
   exactamente lo mismo: detenerse y esperar esa apertura formal, sin actuar sobre los 10
   candidatos/2 relaciones reales.

**Ninguna acción de código, despliegue, ni de servidor se tomó en esta ronda** - es puramente de
reconocimiento/registro del comentario y actualización de este documento.

## Resumen de la ronda 6 (comentario `5913574545`) — despliegue a staging + validación

Detalle completo (checkpoint pre-despliegue, despliegue, validación 1-9, incidente y corrección,
análisis de los 3 fallos): `audit/phase5_staging_deployment_2026-09-30.md`.

- **Despliegue:** ya había ocurrido automáticamente vía el workflow existente de GitHub Actions
  (dispara en cada push a `feature/upgrade-filament-v3`) - verificado que el run para HEAD `4f1b02e`
  completó con éxito antes de iniciar cualquier validación.
- **Checkpoint pre-despliegue:** todos los invariantes de DB coincidieron exactamente (10/2/142/79/
  9749/0), sin migraciones pendientes, target de DB confirmado como la misma instancia compartida de
  Supabase, `ext-intl` confirmado cargado en el servidor.
- **Incidente real (causado y corregido en esa ronda):** un contenedor efímero de pruebas (necesario
  para tener PHPUnit disponible, ausente en la imagen `--no-dev` de staging) sobrescribió, vía un
  bind mount compartido (`bootstrap/cache/`), la caché de auto-discovery de paquetes del contenedor
  REAL que sirve tráfico - staging quedó caído (HTTP 500) unos minutos. Diagnosticado y corregido de
  inmediato (regenerar la caché desde el propio `vendor/` del contenedor real) - el usuario fue
  informado de forma transparente e inmediata y autorizó explícitamente la corrección antes de que se
  ejecutara. Staging restaurado y verificado en HTTP 200. Ningún código, dato, ni fila real fue
  tocado por el incidente ni su corrección. El comentario `5914592664` confirmó que esto NO invalida
  la implementación C2, pero exige el hardening operacional listado arriba antes de reutilizar el
  procedimiento.
- **Suite completa de taxonomía en el servidor real (`ext-intl`, HEAD `4f1b02e`): 188/191 PASS (605
  assertions), 275.45s.** El test bloqueado en TODAS las rondas anteriores por el gap de `ext-intl`
  ahora pasa limpio. `ReviewedProposalServiceTest` (evidencia C2 directa): **41/41 PASS**, sin
  ninguna excepción.
- **Los 3 fallos restantes** tienen causa raíz precisa e identificada (precedencia de `APP_ENV` entre
  la variable de entorno real del contenedor y el `<env>` no forzado de `phpunit.xml`) - ninguno es
  una regresión de C2/taxonomía, los 3 están en archivos de TASK-0003 no tocados por ninguna ronda de
  TASK-0004. El comentario `5914592664` confirmó explícitamente esta lectura.
- **Invariantes de DB después de toda la suite: sin cambios** (10/2/142/79/9749/0) - los 10
  candidatos/2 relaciones reales permanecieron intactos durante todo el despliegue y validación.
- **Búsqueda:** re-verificado sobre el código REALMENTE DESPLEGADO que `BuildEmpresaSearchDocuments`
  no lee ninguna tabla de candidatos/propuestas. Worker verificado accesible (health check público,
  sin tokens). No se re-corrió la regresión de 32 queries (heredada, no invalidada).

## Tareas anteriores (histórico, no activas)

| Tarea | Estado | Archivo |
|---|---|---|
| TASK-0001 | APPROVED | (Fase C1, sin archivo de tarea propio - ver `audit/phase3_phase_c_apply.md`) |
| TASK-0002 | APPROVED | [`tasks/0002-review-503.md`](tasks/0002-review-503.md) |
| TASK-0003 | APPROVED | [`tasks/0003-phase-c-corrections.md`](tasks/0003-phase-c-corrections.md) |
| TASK-0004 (ronda 1) | CORRECTIONS_REQUIRED | [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md) |
| TASK-0004 (ronda 2, correcciones) | CORRECTIONS_REQUIRED (narrow, ronda 3) | mismo archivo, sección "Re-audit" |
| TASK-0004 (ronda 3, correcciones A/B/C) | CORRECTIONS_REQUIRED (final semantic defects, ronda 4) | mismo archivo, sección "Re-audit — comentario `5892711739`" |
| TASK-0004 (ronda 4, defectos 1/2) | IMPLEMENTATION PASS / ENVIRONMENT_GATE_PENDING (ronda 5) | mismo archivo, sección "Re-audit — comentario `5909267134`" |
| TASK-0004 (ronda 5, evidencia de entorno) | DEPLOYMENT AUTHORIZED (ronda 6) | mismo archivo, sección "Re-audit — comentario `5913324183`" |
| TASK-0004 (ronda 6, despliegue a staging + validación) | PASS WITH FOLLOW-UP HARDENING (ronda 7) | mismo archivo, sección "Autorización de despliegue — comentario `5913574545`"; detalle completo en `audit/phase5_staging_deployment_2026-09-30.md` |
| TASK-0004 (ronda 7, confirmación PASS) | STANDING_BY | mismo archivo, sección "Confirmación de despliegue + hardening pendiente — comentario `5914592664`" |
| TASK-0004 (ronda 8, checkpoint documental `63cf811`) | CLOSED | mismo archivo; sin acción de código (el comentario `5914676402` instruyó esperar la apertura formal de TASK-0005) |
| TASK-0005 (ronda 1, implementación + hardening + staging) | CORRECTIONS_REQUIRED (ronda 2) | [`tasks/0005-c2-human-review-ui.md`](tasks/0005-c2-human-review-ui.md); detalle en `audit/phase5_task0005_c2_review_ui_2026-09-30.md` |
| TASK-0005 (ronda 2, correcciones 1 y 2) | **CLOSED / APPROVED** (comentario `5928773263`) | mismo archivo, sección "Re-audit — comentario `5917275454`"; detalle en la sección 10 del audit |
| TASK-0006A (rondas 1–2, explorador de conceptos) | **CLOSED / APPROVED** (comentario `5933152293`) | [`tasks/0006a-concept-explorer.md`](tasks/0006a-concept-explorer.md); detalle en `audit/phase6_task0006a_concept_explorer_2026-10-01.md` |
| TASK-0006 (ronda 1, revisión de la cola) | CORRECTIONS_REQUIRED / compuerta de gobernanza humana (comentario `5934324928`) → resuelta por las decisiones humanas del comentario `5936206843` | [`tasks/0006-queue-human-review.md`](tasks/0006-queue-human-review.md); detalle en la sección 10 de `audit/phase6_task0006_queue_review_2026-10-01.md` |
| TASK-0006B (confirmación humana + bilingüe) | **READY_FOR_REVIEW** (tarea activa) | [`tasks/0006b-human-confirmation.md`](tasks/0006b-human-confirmation.md); detalle en `audit/phase6_task0006b_human_confirmation_2026-10-01.md` |
