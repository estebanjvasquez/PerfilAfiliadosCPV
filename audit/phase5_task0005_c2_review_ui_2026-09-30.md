# TASK-0005 — UI de revisión humana C2 en Filament + hardening del trigger de despliegue

**Fuente:** Issue #2 comentario [`5914793857`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5914793857)
(texto verbatim completo en [`docs/orquestador/tasks/0005-c2-human-review-ui.md`](../docs/orquestador/tasks/0005-c2-human-review-ui.md)).

**Base:** HEAD `63cf811` (checkpoint documental aceptado de TASK-0004, comentario `5914676402`).
**Rama:** `feature/upgrade-filament-v3`. **Fecha:** 2026-09-30.

**Modo de ejecución:** implementar la UI de revisión que CONGELA (`freeze`) una decisión humana
explícita, sin publicar/aplicar nada. `apply()` sigue siendo un paso de ejecución separado y
autorizado aparte — esta tarea NO agrega ninguna ruta/acción que pueda ejecutarlo.

---

## 1. Invariantes protegidos — antes y después

Verificados por lectura directa contra la instancia compartida de Supabase (script standalone que
bootstrapea el contenedor de Laravel, NO la salida de los propios tests):

| Tabla | Esperado | Antes | Después de tests | Después de deploy |
|---|---|---|---|---|
| `taxonomy_candidate_concept_links` | 10 | 10 | 10 | 10 |
| `taxonomy_concept_relations` | 2 | 2 | 2 | 2 |
| `taxonomy_term_concepts` | 142 | 142 | 142 | 142 |
| `taxonomy_canonical_concepts` | 79 | 79 | 79 | 79 |
| `taxonomy_term_cpv_relations` | 9749 | 9749 | 9749 | 9749 |
| `taxonomy_reviewed_proposals` | 0 | 0 | 0 | 0 |

**Los 10 candidatos y 2 relaciones reales quedaron intactos.** Ninguna llamada a
`freeze()`/`apply()`/`approve`/`reject`/`context-resolve` se ejecutó contra ninguna de esas filas en
ningún momento de esta tarea — toda la ejercitación de C2 usó fixtures desechables creadas y
revertidas dentro de `DatabaseTransactions` (conexión `pgsql`). `taxonomy_reviewed_proposals` sigue
en 0 filas: cero residuo de tests.

---

## 2. Archivos cambiados

### Modificados

| Archivo | Cambio |
|---|---|
| `app/Filament/Resources/TaxonomyCandidateConceptLinkResource.php` | Acciones legacy `approve`/`reject`/`resolveNewConcept` REMOVIDAS; nueva acción única `freezeReview` cableada a `ReviewedProposalService::freeze()`; nueva `BadgeColumn` de estado de propuesta C2; helper muerto `impactAndStalenessSummary()` eliminado |
| `app/Filament/Resources/TaxonomyConceptRelationResource.php` | Nueva acción `freezeReview` (`PUBLISH_RELATION`/`REJECT`) + `BadgeColumn` de estado C2 (aditivo, este resource no tenía acciones custom previas) |
| `app/Models/TaxonomyCandidateConceptLink.php` | Nueva relación `reviewedProposals(): HasMany` (por `candidate_link_id`) |
| `app/Models/TaxonomyConceptRelation.php` | Nueva relación `reviewedProposals(): HasMany` (por `concept_relation_id`) |
| `.github/workflows/deploy-contabo.yml` | `paths-ignore` + comentario explicativo de semántica y casos (sección 5 de este documento) |
| `tests/Feature/Filament/TaxonomyCandidateConceptLinkReviewTest.php` | Reescrito para la nueva UI (14 tests) |

### Nuevos

| Archivo | Propósito |
|---|---|
| `app/Filament/Resources/TaxonomyReviewedProposalResource.php` | Resource de SOLO LECTURA (List + View) para inspeccionar propuestas congeladas |
| `app/Filament/Resources/TaxonomyReviewedProposalResource/Pages/ListTaxonomyReviewedProposals.php` | Página índice |
| `app/Filament/Resources/TaxonomyReviewedProposalResource/Pages/ViewTaxonomyReviewedProposal.php` | Página de detalle |
| `app/Policies/TaxonomyReviewedProposalPolicy.php` | Policy de solo lectura (`viewAny`/`view`); sin `create`/`update`/`delete` |
| `tests/Feature/Filament/TaxonomyConceptRelationReviewTest.php` | 7 tests de la UI de relaciones |
| `tests/Feature/Filament/TaxonomyReviewedProposalResourceTest.php` | 4 tests del resource de solo lectura |
| `docs/orquestador/tasks/0005-c2-human-review-ui.md` | Definición verbatim de la tarea |
| `audit/phase5_task0005_c2_review_ui_2026-09-30.md` | Este documento |

**Sin migraciones.** Ningún cambio de esquema fue necesario: toda la información que la UI muestra
o escribe vive en columnas/claves JSONB que ya existían desde TASK-0004.

---

## 3. Sección A — UI de revisión de candidatos

Implementada sobre el resource Filament existente, reusando exclusivamente
`ReviewedProposalService::freeze()` como ÚNICO camino de escritura. No se creó ninguna
implementación paralela de lógica de negocio.

Una sola acción de tabla, `freezeReview` ("Revisar y congelar decisión"), visible cuando
`status === STATUS_PENDING` **y** el usuario pasa `can('update', $record)`. El formulario usa un
`Radio` `->live()` que muestra/exige condicionalmente los campos de cada decisión:

| Decisión | Input humano explícito requerido | Comportamiento |
|---|---|---|
| `MAP_TO_EXISTING` | `target_concept_id` (Select sobre conceptos canónicos activos) | Validación de existencia/compatibilidad server-side dentro de `freeze()`; congela, no publica |
| `CREATE_NEW` | `new_concept_name` (TextInput **sin valor por defecto**) | Solo ofrecida si `$record->isProposingNewConcept()`. Cero fallback implícito a `suggested_new_concept_name`: si el revisor no escribe un nombre, `freeze()` devuelve `RESULT_VALIDATION_FAILED` |
| `CONTEXT_REQUIRED` | `context_reason` (Textarea, no vacío) | Cero mapeo TERM→CPV y cero creación de concepto. La UI no afirma que este estado sea consumido por búsqueda hoy |
| `REJECT` | `reject_reason_category` (Select estructurado) + `notes` | Semánticamente distinto de `CONTEXT_REQUIRED`; el texto de la UI lo explicita |

**Sugerencia del Builder como evidencia, no como default:** cuando el candidato propone un concepto
nuevo, un `Placeholder` separado y claramente etiquetado muestra el
`suggested_new_concept_name` del Builder como referencia. El campo editable
`new_concept_name` arranca vacío — el revisor tiene que escribir el nombre de publicación
explícitamente, incluso si coincide con la sugerencia. Esto preserva la separación
reviewed-vs-source que TASK-0004 ronda 4 estableció (`source_suggested_new_concept_name` se
snapshotea aparte dentro de `freeze()` y es lo único contra lo que `apply()` compara drift).

**Evidencia de solo lectura reusada, nunca para escribir:** los helpers ya aprobados de
`CandidateConceptApprovalService` (`findPossibleDuplicateConcepts()`, `proposalStaleness()`,
`composeReviewReason()`, `REJECT_REASON_LABELS`) se usan únicamente para RENDERIZAR evidencia en el
formulario (posibles duplicados, staleness, impacto previsto). Ninguna escritura pasa por ese
servicio.

**Acciones legacy removidas:** `approve`, `reject` y `resolveNewConcept` fueron eliminadas del
resource. Las dos primeras ya eran callejones sin salida desde TASK-0004 (HIGH-2 cerró la
publicación vía `CandidateConceptApprovalService`); dejarlas visibles habría dado al revisor botones
que fallan o que sugieren un camino de publicación que ya no existe. Verificado con `grep` sobre
todo `tests/` que ningún otro test referenciaba esas acciones antes de removerlas.

---

## 4. Secciones B/C/D — relaciones, visibilidad y frontera de autorización

### B. UI de revisión de relaciones

Acción `freezeReview` nueva en `TaxonomyConceptRelationResource`, con el mismo patrón freeze-first.
El formulario incluye un `Placeholder` de solo lectura (`relation_summary`) que muestra
explícitamente **origen, tipo y destino** propuestos, para que el humano revise los tres antes de
decidir. Decisiones: `PUBLISH_RELATION` / `REJECT`.

- La acción **nunca aprueba ni publica** la relación: solo congela una propuesta inmutable.
- La revalidación server-side de C2 se preserva intacta: `freeze()` snapshotea
  `source_concept_id`/`target_concept_id`/`relation_type`, y `apply()` (fuera del alcance de esta UI)
  re-corre `validateConceptRelationProposal()` con `excludeId`.
- `EditTaxonomyConceptRelation.php` NO fue modificado — su lógica que bloquea aprobar-vía-edit sigue
  siendo correcta y necesaria.
- Las relaciones candidatas siguen invisibles para los consumidores de taxonomía publicada: el índice
  de búsqueda (`BuildEmpresaSearchDocuments`) no lee `taxonomy_concept_relations` en absoluto.

### C. Visibilidad de propuestas congeladas

Nuevo `TaxonomyReviewedProposalResource`, solo lectura (List + View; `getPages()` devuelve
exclusivamente `index` y `view` — deliberadamente sin `create`/`edit`/`delete`). Muestra:

- candidato/relación de origen (columna computada `source`);
- decisión y `proposal_type`;
- revisor (`reviewedBy.name`) y `reviewed_at`;
- `authorization_reference` y `target_environment` (columnas toggleables);
- `payload_fingerprint` y `taxonomy_state_fingerprint` como `TextEntry` monoespaciados —
  representación diagnóstica segura, son hashes, no contenido sensible;
- `decision_payload` como `KeyValueEntry`;
- `status` con color (`PENDING_APPLY` / `APPLIED` / `ABORTED`), usando **los estados reales del modelo
  de TASK-0004** — no se inventó ninguna máquina de estados paralela;
- metadata de ejecución (`applied_at`, resultado/razón de abort) cuando existe.

El `infolist` se organiza en 3 secciones: identidad de la propuesta, revisión (congelamiento), y
"Ejecución (apply) — fuera del alcance de esta UI", esta última con una descripción explícita de que
aplicar requiere autorización separada.

**Autorización:** un `canAccess()` override no alcanza — Filament resuelve `canViewAny()`/`canView()`
vía Gate/Policy para las páginas List/View. Se agregó por eso una Policy real
(`TaxonomyReviewedProposalPolicy`, auto-descubierta por convención `App\Models\X` →
`App\Policies\XPolicy`, como todo el resto del proyecto). La Policy **reusa strings de permiso ya
sembrados** (`view_any_taxonomy::candidate::concept::link`, `view_any_taxonomy::concept::relation` y
sus variantes `view_*`) en lugar de introducir permisos Shield nuevos sin seed — que habrían lanzado
`PermissionDoesNotExist` y tumbado la página con un 500.

### D. Frontera de autorización en la UI

- **Cero** botón/acción/ruta de Apply o Publish alcanzable por interacción normal de un revisor.
- El resource de propuestas es estrictamente de lectura; su sección de ejecución es informativa.
- Todas las etiquetas, descripciones y notificaciones de éxito de ambas acciones `freezeReview` dicen
  explícitamente que congelar **no publica ni aplica** nada.
- `apply()` sigue invocable solo por llamada a servicio, tinker o el comando artisan existente
  (`taxonomy:apply-reviewed-proposal`), todos fuera de la UI.

### Doble-freeze / idempotencia

No se agregó ningún mecanismo nuevo: se apoya en los dos índices únicos parciales de TASK-0004
(`..._one_pending_per_candidate` / `..._one_pending_per_relation`, `WHERE status='PENDING_APPLY'`).
Un segundo intento de congelar el mismo origen devuelve el resultado de duplicado existente, la UI lo
muestra como advertencia y no crea una segunda propuesta activa. La `BadgeColumn` de estado C2 existe
precisamente para esto: como `freeze()` (por diseño) NO toca el `status` del candidato, un candidato
ya congelado seguiría viéndose "pendiente" sin esa columna, invitando a un doble-freeze accidental.

---

## 5. Sección G — hardening del trigger de despliegue

**Problema real observado:** `deploy-contabo.yml` disparaba en *cada* push a
`feature/upgrade-filament-v3`, incluidos los checkpoints solo-documentación. En TASK-0004 ronda 7
(comentario `5914676402`) esto se manifestó en vivo: commits de solo markdown provocaban
rebuild+redeploy completos e innecesarios de staging.

**Corrección aplicada** (`on.push.paths-ignore`):

```yaml
paths-ignore:
  - 'docs/**'
  - 'audit/**'
  - '**.md'
```

**Semántica exacta (documentada en el propio workflow):** `paths-ignore` es **todo-o-nada sobre el
push completo**, no archivo por archivo. El workflow se salta solo si **TODOS** los archivos
cambiados matchean algún patrón. Un solo archivo que no matchee (p.ej. un commit mixto docs+código)
hace que el deploy dispare igual. El sesgo del error es conservador por construcción: falla hacia
desplegar, nunca hacia no desplegar.

**Casos representativos razonados:**

| Caso | Resultado | Razón |
|---|---|---|
| Push que toca solo `docs/**`, `audit/**` o `*.md` | **NO** despliega | Ningún archivo de esos se lee por el runtime de Laravel/PHP. El `git reset --hard` del script de deploy los trae igual en el próximo deploy real; no hay nada que rebuildear ni reiniciar por ellos |
| Push que toca `app/**`, `resources/**`, `routes/**`, `database/migrations/**`, `composer.json`/`.lock`, `Dockerfile`, `docker-compose.yml`, `docker/**`, `nginx`, `.github/workflows/**`, config | **SÍ** despliega | No matchea ningún patrón de ignore. Sin cambios respecto al comportamiento previo |
| Push que toca **solo** `tests/**` | **SÍ** despliega | **Decisión deliberada:** `tests/**` NO se agregó a `paths-ignore`. Los tests no corren en el contenedor de staging como parte de servir tráfico, así que técnicamente sería seguro ignorarlos — pero el pedido explícito de la tarea es "be conservative... do not create a filter broad enough to suppress a legitimate deployment". Un deploy de más es inofensivo (es exactamente lo que ya pasaba antes de esta tarea); un deploy de menos no. Se prefiere errar redesplegando |
| Este push de TASK-0005 | **SÍ** despliega | Incluye `app/**`, `tests/**` y `.github/workflows/**` — ninguno matchea los patrones de ignore |

**No se modificó la topología de compose de staging.** El comentario `5914793857` lo pide
explícitamente ("Do NOT modify the staging compose topology merely to solve the previously observed
test-isolation incident in this task unless needed"), y esta tarea de UI no lo necesita. El
requerimiento de `5914592664` sigue en pie y se honró: **no se volvió a correr la suite completa
usando los bind mounts compartidos del contenedor en vivo.**

---

## 6. Sección F/H — tests

Entorno: **local** (PHP 8.2.34, `C:\Users\esteb\php82`) contra la instancia compartida de Supabase.
Deliberadamente NO se usó el procedimiento inseguro de contenedor efímero sobre los bind mounts de
staging que causó el incidente de TASK-0004 ronda 6.

Todos los tests nuevos usan `DatabaseTransactions` sobre la conexión `pgsql` y fixtures desechables
con nombres prefijados (`zzz_task0005_*`) más `uniqid()` — cero referencia, hardcodeada o por
búsqueda, a ninguno de los 10 candidatos o 2 relaciones reales.

### Corrida 1 — UI nueva (3 archivos)

```
php artisan test --filter="TaxonomyCandidateConceptLinkReviewTest|TaxonomyConceptRelationReviewTest|TaxonomyReviewedProposalResourceTest"
→ 1 failed, 24 passed (180 assertions), 898.02s
```

- `TaxonomyConceptRelationReviewTest`: **7/7 PASS**
- `TaxonomyReviewedProposalResourceTest`: **4/4 PASS**
- `TaxonomyCandidateConceptLinkReviewTest`: **13/14 PASS**

El único fallo es
`viewing_a_propose_new_concept_candidate_with_duplicate_signals_does_not_500` — el **mismo gap
preexistente de `ext-intl` en Windows** documentado en todas las rondas previas de esta sesión
(`Number::format()` requiere `intl`, cuya DLL está bloqueada por una política de Application
Control de esta máquina). No es una regresión de TASK-0005: es un test de TASK-0002 que esta tarea no
modificó funcionalmente, y **pasa limpio en el runtime real de staging** (evidencia de TASK-0004
ronda 6: 188/191 en el servidor, con ese test en verde). Se reporta tal cual —
1 failed / 24 passed, no 25/25 — en lugar de alterar o saltar la aserción.

### Corrida 2 — regresión de archivos relacionados no editados

```
php artisan test --filter="ReviewedProposalServiceTest|TaxonomyConceptRelationValidationTest|CandidateConceptApprovalServiceTest|CanonicalConceptApplyServiceTest"
→ 99 passed (253 assertions), 1877.03s
```

**Cero regresiones.** Cubre los 4 archivos que las relaciones `reviewedProposals()` nuevas o la
remoción de acciones podrían haber afectado: `ReviewedProposalServiceTest` (evidencia C2 directa),
`TaxonomyConceptRelationValidationTest`, `CandidateConceptApprovalServiceTest` (sigue probando que el
camino legacy NO puede publicar rodeando C2) y `CanonicalConceptApplyServiceTest` (Phase C1).

### Cobertura exigida por la sección F

| Requisito | Test |
|---|---|
| Cada una de las 4 decisiones de candidato congela correctamente | 4 tests, uno por decisión |
| Errores de validación por concepto/nombre/contexto/razón faltante | 3 tests dedicados de validación de formulario (`target_concept_id`, `new_concept_name`, `context_reason`) + la razón de rechazo cubierta dentro del test de `REJECT` |
| `CREATE_NEW` con valor humano distinto de la sugerencia del Builder queda correcto | test dedicado: el revisor escribe un nombre distinto, se congela el valor humano, la sugerencia queda snapshoteada aparte |
| `CONTEXT_REQUIRED` con cero mapeo/creación de concepto | aserción de conteos de taxonomía sin cambios tras el freeze |
| Camino de freeze de relación | 2 tests (`PUBLISH_RELATION`, `REJECT`) |
| Doble-submit / idempotencia | 1 test por resource (candidato y relación) |
| Drift/tamper del origen sigue vigente después de un freeze hecho por la UI | 1 test por resource: se muta el origen tras congelar vía UI y `apply()` aborta con cero escrituras |
| La UI no expone camino ejecutable de APPLY | 1 test por resource + aserción de páginas inexistentes en el resource de propuestas |
| Candidato/relación siguen sin publicar tras el freeze | aserciones de estado de origen y de tablas publicadas en cada test de freeze |
| La vista admin de ReviewedProposal renderiza sin error | `TaxonomyReviewedProposalResourceTest` (index + detalle + denegación a no autorizado) |
| Regresión de señales anidadas de TASK-0002 sigue protegida | test original preservado sin cambios (es el que falla solo por `ext-intl` local) |
| Provenance de autorización/revisor persistida estructuralmente | aserciones sobre `reviewed_by`/`reviewed_at`/`decision_payload` en los tests de freeze |

### Sección E — guardas legacy preservadas

No se debilitó ni removió ninguna guarda de TASK-0004. Las guardas de modelo/servicio siguen
idénticas; `CandidateConceptApprovalService` sigue sin poder publicar rodeando C2 (probado por sus
27 tests, en verde en la corrida 2); los consumidores de búsqueda siguen ignorando datos
pendientes/context-required/revisados-pero-no-aplicados por **separación de tablas**
(`BuildEmpresaSearchDocuments` no lee ninguna tabla de candidatos/propuestas).

---

## 7. Gates heredados

| Gate | Invalidado por este diff | Clase de evidencia |
|---|---|---|
| Regresión de 32 queries de búsqueda | **NO** — este diff no toca ningún consumidor de búsqueda/ranking ni cambia datos de taxonomía publicada. No se re-corrió, conforme al criterio del propio comentario `5890195271` | A (heredada) |
| Suite de taxonomía | Parcial: los 2 conjuntos relevantes se re-corrieron (24/25 UI + 99/99 relacionados). Único fallo = gap local de `ext-intl`, ya resuelto en el runtime de staging | B (nueva) |
| Invariantes de DB | **NO** — re-verificados antes y después | B (nueva) |
| Estado de mutaciones de producción | **NO** — cero mutaciones de datos/taxonomía, cero migraciones nuevas | A (heredada) |
| Aprobación de Phase C1 | **NO** — `CanonicalConceptApplyService` intacto; sus 21 tests en verde | A (heredada) |
| Aprobación de TASK-0002 | **NO** — el fix de `signals` sigue intacto y su test de regresión preservado | A (heredada) |
| Implementación C2 (TASK-0004) | **NO** — `ReviewedProposalService` no fue modificado en absoluto; esta tarea solo lo consume | A (heredada) |

---

## 8. Sección I — despliegue a staging y smoke

### Mecanismo y HEAD desplegado

El despliegue ocurrió por el mecanismo existente (ya corregido por la sección G): el push del commit
de código disparó el workflow, que no matchea ningún patrón de `paths-ignore` porque incluye
`app/**`, `tests/**` y `.github/workflows/**`.

| Dato | Valor |
|---|---|
| Commit de código | `b92e8739e27bc8ab8fa2d4caa15579e5c55d253d` |
| Base | `63cf811112e8baff87c7382528ecac42b7a07c6e` |
| Run de GitHub Actions | `36749145662` — `conclusion: success`, 2026-09-30T17:07:27Z → 17:08:03Z |
| HEAD verificado en el servidor | `git rev-parse HEAD` = `b92e8739e27bc8ab8fa2d4caa15579e5c55d253d` (coincidencia exacta) |
| Contenedores | `app running`, `nginx running` |
| Migraciones pendientes | ninguna (`migrate:status` sin filas `Pending`) — esta tarea no introdujo ninguna migración |

**Nota sobre el push de documentación (evidencia en vivo de la sección G):** el segundo commit de
esta tarea toca exclusivamente `audit/**` y `docs/**`, así que **no dispara despliegue** — es la
primera comprobación real del hardening. Por eso el HEAD de la rama es ese commit de documentación
mientras staging corre `b92e873`: no es drift, es el comportamiento buscado. Todo el runtime de
TASK-0005 está en `b92e873`, que es exactamente lo que está desplegado y validado.

Como contraste, el checkpoint `63cf811` de TASK-0004 (solo documentación) **sí** había disparado un
rebuild+redeploy completo (run `36738959012`) — el desperdicio que la sección G corrige.

### Smoke HTTP público (sin autenticar)

| URL | Resultado |
|---|---|
| `https://pruebas.camarapetrolera.app/` | **200** |
| `https://pruebas.camarapetrolera.app/admin/login` | **200** |
| `/admin/taxonomy-candidate-concept-links` | **302** → login (esperado sin sesión) |
| `/admin/taxonomy-concept-relations` | **302** → login |
| `/admin/taxonomy-reviewed-proposals` | **302** → login |

Cero 500 y cero 503. El **302** (no 404) en `/admin/taxonomy-reviewed-proposals` ya prueba por sí
solo que la ruta del resource nuevo quedó registrada en el código desplegado.

### Render autenticado de las páginas de revisión

Ejecutado dentro del contenedor desplegado vía el HTTP kernel real de Laravel, actuando como el
usuario revisor real (id 3, rol `super_admin` — el mismo cuya sesión originó el incidente de
TASK-0002). **Solo peticiones GET**: abrir una página de Filament nunca muta un candidato
(establecido en TASK-0002), y no se invocó ninguna acción.

| Página | Resultado |
|---|---|
| `/admin/taxonomy-candidate-concept-links` | **HTTP 200**, 183 741 bytes |
| `/admin/taxonomy-concept-relations` | **HTTP 200**, 171 156 bytes |
| `/admin/taxonomy-reviewed-proposals` | **HTTP 200**, 180 973 bytes |

Los labels de las acciones/columnas **no** aparecen en ese HTML inicial, y eso es correcto, no un
fallo: el HTML contiene `wire:init`, o sea que Filament v3 renderiza la tabla de forma diferida en
una petición Livewire posterior cuya firma/snapshot no es sintetizable a mano desde tinker. Por eso
la visibilidad de la UI se evidenció estructuralmente (abajo) en lugar de por scraping de HTML.

### Verificación estructural sobre el código DESPLEGADO

Introspección de las definiciones reales de tabla/resource en el contenedor de staging:

| Comprobación | Resultado |
|---|---|
| Acciones de tabla en candidatos | `view` \| `freezeReview` — las legacy `approve`/`reject`/`resolveNewConcept` ya no existen |
| Acciones de tabla en relaciones | `freezeReview` \| `edit` \| `delete` (las dos últimas preexistentes; `edit` sigue bloqueando aprobar-vía-edit) |
| Acciones de tabla en propuestas revisadas | `view` únicamente |
| Columna de estado C2 (`reviewed_proposal_state`) | presente en candidatos **y** relaciones |
| `TaxonomyReviewedProposalResource::canViewAny()` | `true` para el revisor real |
| `TaxonomyReviewedProposalResource::canCreate()` | `false` |
| Páginas del resource de propuestas | `index`, `view` — sin `create`/`edit`/`delete` |
| Acción `apply`/`publish` alcanzable | **ninguna, en ningún resource** |
| Policy del revisor sobre un candidato pendiente real (id 266) | `can('update')` = `true`, o sea la acción de freeze sería visible y usable |

Esto satisface "verify the new review UI is visible/usable" **sin ejecutar un freeze real** — la
tarea prohíbe explícitamente hacer un freeze solo para demostrar la UI, y no se hizo ninguno.

### Invariantes y filas reales después del despliegue

| Tabla | Valor |
|---|---|
| `taxonomy_candidate_concept_links` | 10 |
| `taxonomy_concept_relations` | 2 |
| `taxonomy_term_concepts` | 142 |
| `taxonomy_canonical_concepts` | 79 |
| `taxonomy_term_cpv_relations` | 9749 |
| `taxonomy_reviewed_proposals` | 0 |

Además, verificación explícita de que ninguna decisión se tomó:

- estados de candidatos: `pending:10` (los 10, sin excepción);
- estados de relaciones: `candidate:2`;
- candidatos con `reviewed_at` no nulo: **0**.

**No se re-corrió la suite completa contra los bind mounts compartidos del contenedor en vivo** — el
requerimiento de `5914592664`, reiterado en `5914793857`, se honró. Tampoco se modificó la topología
de compose ni se creó ningún contenedor efímero, así que el incidente de TASK-0004 ronda 6 no tuvo
ninguna posibilidad de repetirse.

---

## 9. Manejo de secretos

Ningún secreto fue leído, impreso, copiado a un archivo del repo, ni registrado en este documento.
El diff completo y todos los archivos nuevos se revisaron con `grep` contra los patrones
`cfut_|ghp_|github_pat_|DEBUG_TOKEN=|BEGIN.*PRIVATE KEY|DB_PASSWORD=|APP_KEY=base64` antes de
commitear: cero coincidencias. La verificación de la base de datos usó solo host/database/port, nunca
la contraseña. No se rotó ninguna credencial ni token.

---

## 10. Condiciones STOP — ninguna alcanzada

Nada de lo siguiente ocurrió ni fue necesario: despliegue a producción, merge a `main`, migración
destructiva, rotación de credenciales/tokens, cambio de taxonomía publicada, procesar/congelar/
aplicar/rechazar/resolver-contexto sobre los 10 candidatos o 2 relaciones reales, ni cambio de
semántica de búsqueda/ranking.
