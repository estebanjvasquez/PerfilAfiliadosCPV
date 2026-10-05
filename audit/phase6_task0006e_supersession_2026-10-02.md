# TASK-0006E — Ciclo de vida SUPERSEDED no destructivo + supersesión autorizada de #420/#421/#422

**Referencia de gobernanza:** Issue #2 comentario `5955148859` (autorización explícita del dueño de la
taxonomía), tras el PASS de TASK-0006D en `5954835892`.
**Referencia de autorización usada en los datos:**
`Issue #2 — explicit owner authorization following orchestrator comment 5954835892`
**Checkpoint de entrada:** `af4abec0db7406381dc29df7807c3def1313ab63`
**Fecha:** 2026-10-02
**APPLY / PUBLISH:** NO EJECUTADO, NO AUTORIZADO. TASK-0007 sigue sin abrir.

---

## 1. El problema, y por qué hacía falta un estado nuevo

Tres propuestas reales —#420 `petroleum`, #421 `crude oil`, #422 `oil and gas`— quedaron **obsoletas**:
el grafo de conceptos cambió después de que se revisaran (se crearon `oleoducto` #2890 y `gasoducto`
#2891 el 2026-10-01 ~13:07, mientras las tres se revisaron ~09:48–09:50), así que su
`taxonomy_state_fingerprint` dejó de coincidir con el estado actual.

Hasta esta tarea eso era un **callejón sin salida**, y conviene ver por qué con precisión:

- `apply()` detecta la obsolescencia y la registra con `abort()`, que deja la propuesta `ABORTED` de
  forma **terminal**;
- re-congelar está bloqueado por el índice único parcial
  `taxonomy_reviewed_proposals_one_pending_per_candidate` (`WHERE status = 'PENDING_APPLY'`);
- editar el fingerprint a mano está explícitamente prohibido (OPCIÓN D del diseño).

Es decir: **no existía ninguna forma de volver a pedirle la decisión a un humano sin destruir la
anterior.** Y lo que hacía falta no era saltear la revalidación —el gate está bien, el cambio de estado
es sustantivo para esos términos— sino **volver a preguntar sin borrar la respuesta vieja**.

Eso es exactamente lo que implementa `SUPERSEDED`.

---

## 2. Qué se entregó

| Requisito del comentario `5955148859` | Estado |
|---|---|
| 1. Esquema aditivo con los campos/constraints del diseño aceptado, incluido el delta durable | **IMPLEMENTADO** (§3) |
| 2. Estado explícito `SUPERSEDED`, sin tocar la semántica de los estados históricos | **IMPLEMENTADO** (§3.2) |
| 3. Transaccional, idempotente, concurrency-safe | **IMPLEMENTADO** (§4.3) |
| 4. Revalidar antes de transicionar (5 compuertas) | **IMPLEMENTADO** (§4.2) |
| 5. Para #420/#421/#422, supersesión **SIN sucesor** | **EJECUTADO** (§7) |
| 6. Delta de estado auditable y durable | **IMPLEMENTADO** (§5) |
| 7. Comportamiento de Filament tras supersedir | **IMPLEMENTADO** (§6) |
| 8. Preservar el endurecimiento de UX de TASK-0006D | **PRESERVADO y probado** (§6.1) |
| 9. Preservar las protecciones de APPLY agrupado de TASK-0006D | **PRESERVADO y probado** (§8) |
| 10. Normalizar la atribución de `detected_on_proposal_id` | **CORREGIDO** (§4.5) |

---

## 3. Esquema (migración estrictamente aditiva)

`database/migrations/2026_10_02_160000_add_supersession_lifecycle_to_taxonomy_reviewed_proposals.php`

### 3.1 Qué agrega

10 columnas, todas **NULL**: `superseded_at`, `superseded_by_proposal_id`, `supersedes_proposal_id`,
`inherited_decision_from_id`, `supersession_reference`, `supersession_reason`,
`supersession_actor_type`, `supersession_by_id`, `supersession_channel` y
`supersession_state_delta JSONB`.

Tres CHECK nuevos:

1. **`supersession_complete`** — todo-o-nada: `superseded_at`, referencia, motivo, actor, canal y delta
   son todos NULL o todos NOT NULL. Mismo criterio que los CHECK de confirmación/anulación de
   TASK-0006B/0006C. `supersession_by_id` queda **fuera** a propósito, y por la misma lección de
   TASK-0006C: cuando la ejecuta el agente bajo autorización del dueño ahí va **NULL**, porque poner la
   cuenta de una persona que no ejecutó la acción repetiría el error de procedencia que TASK-0006C vino
   a reparar.
2. **`successor_needs_delta`** — `superseded_by_proposal_id IS NULL OR supersession_state_delta IS NOT
   NULL`. Es la contraparte en base de datos del requisito del diseño («si hay sucesor, tiene que haber
   diff»): impide, **sin poder sortearse desde la aplicación**, que exista un sucesor con decisión
   heredada y sin la evidencia de qué cambió — o sea, impide la confirmación ceremonial.
3. **`supersession_not_self`** — una propuesta no puede sucederse ni heredarse a sí misma.

Tres FK auto-referenciales (`ON DELETE SET NULL`) para el linaje, y dos índices parciales
(`superseded_idx`, `supersedes_idx`).

### 3.2 Lo que NO hizo falta tocar, y por qué importa

- **El `status` no tiene CHECK de valores.** La tabla original declara
  `status VARCHAR(30) NOT NULL DEFAULT 'PENDING_APPLY'` sin restricción de dominio, así que agregar
  `SUPERSEDED` (10 caracteres) **no exigió modificar ninguna constraint existente**.
- **Los índices únicos parciales no se tocaron.** Verificado después de migrar: siguen siendo
  `WHERE status = 'PENDING_APPLY' AND candidate_link_id IS NOT NULL` (y su par para relaciones). Y eso
  es justamente el mecanismo: salir de `PENDING_APPLY` **libera el slot** del candidato. La
  recuperación no viene de debilitar el índice, viene de respetarlo.

### 3.3 Cero filas tocadas

La migración no tiene backfill. Verificado de solo lectura inmediatamente después de aplicarla:
`superseded_at NOT NULL = 0`, `status = SUPERSEDED → 0`, `PENDING_APPLY = 12`, total de propuestas 12,
y 142 / 81 / 9749 sin cambios. Las 12 propuestas existentes quedaron idénticas, con las 10 columnas
nuevas en NULL.

### 3.4 Dos reglas nuevas en el trigger

`CREATE OR REPLACE` de `taxonomy_reviewed_proposals_guard_confirmation()`, **conservando íntegras** la
excepción de anulación de TASK-0006C y las tres prohibiciones de TASK-0006B (verificado por inspección
de `pg_proc.prosrc` tras migrar: las cuatro siguen presentes). Se suman:

5. **El rastro de una supersesión ya grabada es inmutable** — mismo criterio que ya protege al rastro
   de anulación. El delta existe para explicar por qué la revisión caducó; si se pudiera reescribir
   después, no probaría nada.
6. **`SUPERSEDED` es TERMINAL**: no se «des-supersede». No es ceremonial — devolver una propuesta
   supersedida a `PENDING_APPLY` podría **colisionar con la propuesta nueva que el humano ya congeló**
   para ese mismo candidato, y el índice único parcial recién lo detectaría en el UPDATE. Mejor
   prohibirlo explícitamente. Mismo criterio que `ABORTED`.

Las dos están probadas contra la base real (§8).

---

## 4. La operación `supersedeStaleProposal()`

### 4.1 Lo que escribe y lo que no

**Escribe exclusivamente:** `status -> SUPERSEDED` y el rastro (momento, referencia, motivo, actor,
canal auto-capturado, delta), más una fila de auditoría propia.

**No toca** (verificado **campo por campo** por test, con lectura cruda sin casts): `decision`,
`decision_payload`, `payload_version`, `payload_fingerprint`, `taxonomy_state_fingerprint`,
`reviewer_id`, `reviewed_at`, `applied_at`, `authorization_reference`, `target_environment`,
`application_result`, `requires_human_confirmation`, `prepared_by_actor_type`, `prepared_via`, los
cinco campos de confirmación, el rastro de anulación, `proposal_group_id`, `created_at`, **ni la fila
fuente**.

«No destructiva» es la propiedad entera de este diseño, así que no se confía en que el UPDATE liste
pocas columnas: el test compara la fila entera antes y después.

### 4.2 Las cinco compuertas de revalidación

Todas corren **antes** de escribir una sola fila (y para un grupo, sobre **todos** los miembros antes
de tocar ninguno):

| Compuerta | Resultado si falla | Por qué |
|---|---|---|
| Sigue `PENDING_APPLY` | `ALREADY_PROCESSED` / `ALREADY_SUPERSEDED` | No se retira de la cola algo que ya salió de ella |
| `payload_fingerprint` válido | `TAMPERED_PAYLOAD` | Supersedir una propuesta manipulada **taparía el hallazgo de seguridad** |
| Obsoleta de verdad contra `dryRunInputFingerprint()` actual | `NOT_STALE` | Retirar una revisión vigente es otra decisión de gobernanza, con su propia autorización |
| Ninguna ejecución ocurrió (`applied_at`/`authorization_reference` NULL) | `ALREADY_PROCESSED` | — |
| La fila fuente existe y sigue revisable | `SOURCE_NOT_REVIEWABLE` | Liberar el slot no sirve de nada si el candidato ya lo resolvió otro camino |

La obsolescencia se computa **por código de aplicación** llamando a `dryRunInputFingerprint()`, no
deduciéndola de que dos hashes guardados difieran entre sí — requisito explícito del orquestador desde
que abrió TASK-0006D.

### 4.3 Transaccional, idempotente, concurrency-safe

Una sola transacción; `lockForUpdate()` + re-chequeo dentro de ella, igual que `apply()`/`confirm()`.
Un segundo llamado ve `SUPERSEDED` bajo el lock y devuelve `ALREADY_SUPERSEDED` **sin escribir ni
auditar de nuevo** (probado: la fila queda byte a byte igual y no aparece una segunda fila de
auditoría). Para un grupo bilingüe se toma primero el **advisory lock del grupo**, la misma clave que
usa `apply()`, así que una supersesión y un apply concurrentes del mismo grupo se serializan.

### 4.4 Sin sucesor, por diseño en esta firma

El método **no crea** una propuesta nueva y no arrastra la decisión vieja hacia adelante. Es la
variante de máxima agencia humana, y es la que el dueño autorizó: la pregunta correcta no es
«¿confirmás la decisión vieja?» sino «¿`petroleum` sigue siendo demasiado genérico ahora que existen
`oleoducto` y `gasoducto`?» — eso es una **revisión nueva**, no una confirmación.

El camino con sucesor existe en el diseño y queda **deliberadamente sin implementar** hasta que haga
falta y se autorice, porque además exige el diff en la UI para que la confirmación no sea ceremonial.
El esquema ya lo soporta (y el CHECK `successor_needs_delta` ya lo gobierna).

### 4.5 `SUPERSEDED` como estado terminal en `apply()`/`preflight()`

Esto no es un detalle de reporte: **es una compuerta de seguridad**. `SUPERSEDED` no es `APPLIED` ni
`ABORTED`, así que sin una compuerta propia una propuesta supersedida habría caído por la cadena de
validación y `apply()` la habría **ABORTADO, pisando el rastro que la supersesión acaba de grabar**. Se
agregó en `evaluateApplicability()`, junto a los otros dos estados ya resueltos, con **cero
escrituras**; `apply()` devuelve `RESULT_ALREADY_SUPERSEDED` y el preflight reporta
`ALREADY_SUPERSEDED` (categoría `BLOCKED_FOR_OTHER_REASON`, `would_apply_abort_with = null`,
write-set 0). Probado contra la base real. El trigger lo respalda prohibiendo salir del estado.

### 4.6 Dos correcciones incidentales que el estado nuevo obligó a hacer

- **`unconfirmedMembers()` pasó a mirar sólo miembros `PENDING_APPLY`.** Antes contaba cualquier
  miembro del grupo sin confirmar, sin importar su estado; con `SUPERSEDED` en escena eso habría
  bloqueado un grupo por la confirmación de una fila que **nadie va a ejecutar**. Para un `APPLIED` la
  pregunta no existe (aplicar ya exigió la confirmación), así que acotarlo es además más preciso.
- **Requisito 10 — atribución normalizada.** `abortWholeGroup()` registraba
  `detected_on_proposal_id` leyendo `tampered_proposal_id`, que **sólo** el bloqueo de tamper emitía;
  para cualquier otro bloqueo de hermano (estado, fingerprint de grupo, entidad faltante, fuente ya
  resuelta, drift, identidad incoherente) el campo caía al de ENTRADA y **atribuía el problema a la
  fila equivocada**. Ahora los siete bloqueos del bucle de miembros emiten `offending_proposal_id` (y
  `offending_candidate_link_id` cuando el problema está en la fila fuente), y el fallback a la entrada
  queda sólo para bloqueos de nivel de entrada, donde la entrada **es** la ofensora.

---

## 5. El delta de estado durable

### 5.1 Por qué se persiste y no se calcula al mostrar

El `taxonomy_state_fingerprint` es un **hash**: el estado viejo no se puede reconstruir desde él. Si el
delta no se guarda en el instante de la transición, la explicación de por qué la decisión caducó **se
pierde para siempre** — y con ella la posibilidad de que una confirmación futura sea significativa.
Por eso es una columna, y por eso el CHECK la exige en cuanto haya un sucesor.

### 5.2 Qué contiene

- los **dos hashes** (congelado y actual al supersedir) — exactos;
- la ventana de revisión (`reviewed_at` → `superseded_at`);
- **los conceptos creados desde la revisión**, nombrados (id + ES + EN + status + `created_at`), no
  sólo contados: es la evidencia que de verdad vuelve a abrir la pregunta;
- los ids de conceptos **modificados** desde la revisión;
- el fingerprint del grafo y las señales de versión por tabla **actuales**;
- los conteos protegidos en el momento de la supersesión.

### 5.3 El límite, declarado dentro del propio delta

El delta lleva escrito un campo `limitation` que dice textualmente que **no es una reconstrucción del
estado viejo**: los dos hashes son exactos, pero los cambios listados se derivaron de
`created_at`/`updated_at` acotados por `reviewed_at`, así que una fila modificada sin tocar
`updated_at` no aparecería — el mismo límite ya documentado en `dryRunInputFingerprint()`. Se declara
en el dato, no sólo en esta auditoría, para que nadie lo lea como más de lo que es.

---

## 6. Filament tras la supersesión

### 6.1 El candidato vuelve a ser revisable — y el endurecimiento de TASK-0006D sigue intacto

No hizo falta tocar la regla de visibilidad, y eso es la señal de que estaba bien planteada:
`liveFrozenProposal()` busca una propuesta **`PENDING_APPLY`**, así que una `SUPERSEDED` no bloquea
nada y `freezeReview` reaparece **solo**. Probado en secuencia en un mismo test: con la propuesta viva
el botón está oculto y se ofrece el enlace de solo lectura; tras supersedir el botón reaparece, el
enlace desaparece, y un reviewer autorizado congela una propuesta **nueva** por la UI mientras la vieja
sigue `SUPERSEDED`.

### 6.2 La propuesta vieja queda visible y de solo lectura, con linaje

- Badge `SUPERSEDED` en la lista, en **gris y no en rojo** a propósito: una supersesión no es un fallo
  de ejecución, es una decisión de gobernanza.
- Filtro de estado nuevo.
- Sección de infolist **Supersesión**, visible sólo cuando existe el rastro, con momento, actor (que
  dice con la verdad «el AGENTE, por autorización explícita del dueño; ninguna cuenta de persona
  ejecutó esta transición»), canal, referencia, motivo, el sucesor (o la explicación de por qué **no**
  hay) y el delta aplanado para lectura — con los conceptos nuevos en texto legible, porque son la
  evidencia que le sirve al revisor.
- **Ningún control de APPLY/Publish** se agregó, en ninguna de las dos pantallas.

El aplanado del delta para `KeyValueEntry` es por la misma razón que el de `signals` en el incidente
503 de TASK-0002: ese componente llama `htmlspecialchars()` sobre cada valor y en PHP 8 eso es un
TypeError en cuanto el valor no es escalar, y este delta tiene listas anidadas. Se aplana en
**presentación**; el JSONB en la base conserva la estructura completa para un auditor.

### 6.3 El candidato explica su propia historia

La tabla de candidatos muestra `SUPERSEDIDA_REVISABLE` en vez del valor crudo del status, y la página
de detalle dice explícitamente que hubo una revisión anterior, cuál era, cuándo quedó obsoleta y que
**la decisión anterior es evidencia histórica, no un punto de partida obligado**. Sin eso el candidato
parecería nunca revisado y quien lo revise perdería el contexto de por qué volvió a la cola.

---

## 7. La supersesión real de #420/#421/#422

### 7.1 Orden de ejecución respetado

El comentario exige: «After code/tests/staging validation pass, execute the real supersession ONLY for
#420/#421/#422». Se cumplió en ese orden exacto:

1. migración aditiva aplicada y verificada (cero filas tocadas);
2. tests (§8);
3. commit `451ba11` desplegado a staging, workflow `success`, smoke sin 500/503;
4. **recién entonces** la supersesión real;
5. recuento de solo lectura + preflight + smoke posteriores.

Antes de ejecutar se corrió además el `--dry-run` del comando sobre las tres: confirmó
`STALE_TAXONOMY_STATE`, `obsoleta: SI`, `payload válido: SI` para #420/#421/#422, sin escribir nada.

### 7.2 Salvaguardas de la ejecución

- **Allowlist dura de exactamente `[420, 421, 422]`**; el script aborta si la lista no es esa.
- **Precondición de estado**: exige encontrar 12 propuestas / 12 `PENDING_APPLY` / 0 `SUPERSEDED` /
  0 `APPLIED`, y aborta sin escribir si el estado no es el esperado.
- La referencia de autorización se fijó en un archivo UTF-8 y se verificó por bytes (83 bytes / 81
  caracteres) antes de usarla, porque lleva un guion largo (U+2014) y pasarla por la consola la habría
  expuesto a la codepage. **El camino de ejecución es el mismo comando revisable**
  (`taxonomy:supersede-stale-reviewed-proposal` vía `Artisan::call`), no una ruta paralela.

Referencia usada, textual:
`Issue #2 — explicit owner authorization following orchestrator comment 5954835892`

### 7.3 C) Estado exacto ANTES y DESPUÉS

| | #420 | #421 | #422 |
|---|---|---|---|
| Candidato / término | 263 / `petroleum` | 264 / `crude oil` | 265 / `oil and gas` |
| `status` | `PENDING_APPLY` → **`SUPERSEDED`** | `PENDING_APPLY` → **`SUPERSEDED`** | `PENDING_APPLY` → **`SUPERSEDED`** |
| `superseded_at` | NULL → `2026-10-02 16:44:43` | NULL → `2026-10-02 16:45:02` | NULL → `2026-10-02 16:45:22` |
| `superseded_by_proposal_id` | NULL → **NULL** (sin sucesor) | NULL → **NULL** | NULL → **NULL** |
| `decision` | `CONTEXT_REQUIRED` → sin cambios | ídem | ídem |
| `payload_fingerprint` | `b9e1ec92528f0b2e…` → **idéntico** | **idéntico** | **idéntico** |
| `taxonomy_state_fingerprint` | `1d0eb041f6428696…` → **idéntico** | **idéntico** | **idéntico** |
| `reviewer_id` / `reviewed_at` | 3 / `2026-10-01 09:48:41` → **idénticos** | 3 / `09:49:42` → **idénticos** | 3 / `09:50:20` → **idénticos** |
| `applied_at` / `authorization_reference` / `target_environment` / `application_result` | NULL → **NULL** | ídem | ídem |
| Candidato fuente | `pending`, `reviewed_at` NULL → **sin cambios** | ídem | ídem |

**Campos inmutables modificados: 0 en las tres.** Verificado por comparación cruda (sin casts) de la
fila completa antes y después, sobre 17 campos por propuesta más el estado del candidato. Lo único que
cambió es `status` y el rastro de supersesión.

`supersession_actor_type = agent` y **`supersession_by_id = NULL`**: la ejecutó el agente bajo
autorización del dueño, y atribuirla a la cuenta de una persona que no la ejecutó repetiría exactamente
el error de procedencia que TASK-0006C reparó.

### 7.4 El delta de estado capturado

Las tres registran el mismo cambio sustantivo, que es precisamente el que vuelve a abrir la pregunta:

```
fingerprint congelado 1d0eb041f642… → actual c236bc5159ae…
conceptos creados desde la revisión: 2
  + concepto #2890  oleoducto / oil pipeline  (2026-10-01 13:07:11)
  + concepto #2891  gasoducto / gas pipeline  (2026-10-01 13:07:30)
```

Eso es la justificación de la recomendación que el orquestador aceptó: `petroleum`, `crude oil` y
`oil and gas` se marcaron «demasiado genéricos para un mapeo directo» **cuando `oleoducto` y
`gasoducto` no existían**. La pregunta correcta ahora no es «¿confirmás la decisión vieja?», es «¿sigue
siendo demasiado genérico?» — y sólo una persona puede responderla.

### 7.5 Los candidatos vuelven a la cola de revisión normal

| Candidato | `status` | Propuesta viva | Slot `PENDING_APPLY` |
|---|---|---|---|
| 263 | `pending` | NINGUNA | **libre** |
| 264 | `pending` | NINGUNA | **libre** |
| 265 | `pending` | NINGUNA | **libre** |

`liveFrozenProposal()` devuelve `null` para los tres, así que `freezeReview` vuelve a estar disponible
**sin que haya hecho falta tocar la regla de visibilidad** — la señal de que el endurecimiento de
TASK-0006D estaba bien planteado. Y la página de detalle de cada candidato explica la historia: «Hubo
una revisión anterior, la propuesta #420 (CONTEXT_REQUIRED) del 2026-10-01 09:48:41, que quedó OBSOLETA
… está disponible para una revisión NUEVA contra el estado actual; la decisión anterior es evidencia
histórica, no un punto de partida obligado.»

### 7.6 Auditoría

Cada una tiene exactamente **2 filas** en `taxonomy_audit_log`: la del `freeze` original y la nueva de
supersesión (ids 3007/3008/3009), con `field = superseded_at`, `PENDING_APPLY → SUPERSEDED`,
`actor_type = system`, y **`authorization_reference` y `target_environment` en NULL** — porque
supersedir **no es ejecutar**, y esos dos campos están reservados para el APPLY real. Es la distinción
sobre la que se apoya todo el contrato C2. La autorización del dueño vive en `supersession_reference`
de la propia fila y en el texto del motivo.

### 7.7 Preflight posterior (solo lectura)

Artefacto: `audit/task0006e_preflight_after_supersession_2026-10-02.json`.
`write_statements_observed: 0`.

| Categoría | Propuestas |
|---|---|
| `READY_TO_APPLY` (9) | #491, #492–#495, #629/#630, #631/#632 |
| `BLOCKED_FOR_OTHER_REASON` (3) | #420, #421, #422 — **`ALREADY_SUPERSEDED`** |
| `NEEDS_REVALIDATION` | **0** |

Los tres ya **no** reportan `STALE_TAXONOMY_STATE` ni `READY_TO_APPLY`: reportan
`ALREADY_SUPERSEDED`, que es el requisito 9 («preflight understands SUPERSEDED as terminal historical
state»). Y la cola viva quedó **sin ninguna propuesta obsoleta/bloqueante** — la condición que el
orquestador puso para que TASK-0007 pueda abrirse algún día, **detrás de una autorización de APPLY
nueva y explícita que no existe**.

### 7.8 STOP — la re-revisión humana no se hizo ni se preparó

Instrucción explícita: «The development agent must NOT make the new semantic decisions for
petroleum/crude oil/oil and gas». Se respetó al pie de la letra:

- **no se congeló ninguna propuesta nueva** para 263/264/265 (§9.4: siguen con 1 propuesta cada uno, la
  supersedida);
- **no se preseleccionó ni se insinuó ninguna decisión**: la supersesión es sin sucesor precisamente
  para que no haya una decisión redactada esperando un clic;
- el motivo durable describe **por qué** caducó la revisión, nunca **qué** debería decidirse ahora.

El dueño re-revisará los tres candidatos personalmente por Filament contra el grafo actual, y puede
elegir `CONTEXT_REQUIRED` otra vez o cualquier otra decisión válida.

---

## 8. Tests

Todo con fixtures desechables dentro de `DatabaseTransactions` sobre `pgsql`. **Ninguna propuesta real
participó de ningún test**, y ningún test ejecuta un APPLY real.

| Suite | Resultado |
|---|---|
| `ReviewedProposalSupersessionTest` (**nueva**) | **20/20 PASS** (140 assertions) |
| `ReviewedProposalServiceTest` | **41/41 PASS**, sin editar |
| `ReviewedProposalConfirmationTest` | **47/47 PASS**, sin editar |
| `ReviewedProposalGroupLockingTest` | **7/7 PASS**, sin editar |
| `ReviewedProposalPreflightTest` | **24/24 PASS**, sin editar |
| **Corrida de unidad heredada combinada** | **119/119 PASS** (621 assertions) |
| `TaxonomyReviewedProposalResourceTest` | **12/12 PASS** (11 + 1 nuevo) |
| `TaxonomyCandidateConceptLinkReviewTest` | **21/21** salvo el fallo heredado de `ext-intl` (ver abajo) |
| `TaxonomyReviewedProposalConfirmationUiTest` | **9/9 PASS**, sin editar |
| **Corrida de Filament combinada** | **40/41** |

Cobertura exigida por el comentario, punto por punto:

| Requisito | Test |
|---|---|
| La supersesión preserva el contenido inmutable del predecesor | `supersession_preserves_every_immutable_field_of_the_predecessor` (comparación cruda de la fila completa) |
| El camino sin sucesor libera el candidato para re-revisión | `the_candidate_can_be_reviewed_again_while_the_predecessor_stays_superseded` |
| El invariante de un solo `PENDING_APPLY` sigue valiendo | `the_unique_pending_apply_invariant_still_holds_after_supersession` (un TERCER freeze sigue rechazado) |
| Supersesión duplicada/replay es idempotente | `supersession_is_idempotent_on_replay` (cero escrituras, ninguna segunda fila de auditoría) |
| Una propuesta no obsoleta no se puede supersedir por el camino de obsolescencia | `a_proposal_that_is_not_stale_cannot_be_superseded_through_the_stale_only_flow` |
| Los grupos no se pueden supersedir a medias | `a_bilingual_group_is_superseded_whole_or_not_at_all` + `entering_through_either_sibling_supersedes_the_same_whole_group` |
| La propuesta vieja sigue de solo lectura/auditable | `the_detail_page_of_a_superseded_proposal_renders_without_a_500` + `supersession_writes_its_own_distinguishable_audit_event` |
| Tras supersedir se puede congelar una propuesta nueva mientras el predecesor sigue `SUPERSEDED` | `the_candidate_can_be_reviewed_again_…` + `a_candidate_becomes_reviewable_again_through_the_normal_ui_after_supersession` (por la UI real) |
| No se llama a APPLY/PUBLISH | `supersession_publishes_absolutely_nothing` + `apply_on_a_superseded_proposal_writes_nothing_and_does_not_overwrite_the_trail` |
| El preflight entiende `SUPERSEDED` como estado histórico terminal | `preflight_treats_superseded_as_terminal_history_not_ready_to_apply` |
| Las suites C2/confirmación/preflight/grupo/UX siguen verdes | 119/119 + 40/41 arriba |

Tres pruebas adicionales **a nivel de base de datos**, no de aplicación: no se puede des-supersedir, no
se puede reescribir el rastro, y no se puede registrar un sucesor sin delta persistido.

Un riesgo de render que valía cerrar explícitamente: la sección nueva del infolist mete un delta con
**listas anidadas** en un `KeyValueEntry`, que es la forma exacta del incidente 503/500 de TASK-0002
(`htmlspecialchars()` sobre un valor no escalar = TypeError en PHP 8). Hay un test que abre la página
de detalle de una propuesta **realmente supersedida** y exige `assertOk()`, en vez de confiar en que el
aplanado esté bien — y eso importa de verdad ahora, porque las tres filas reales ya tienen rastro de
supersesión y esa pantalla se abre sobre ellas de aquí en adelante.

**El único fallo local** es el gap preexistente de `ext-intl` en la regresión de nested-signals de
TASK-0002 (`TextEntry::make('confidence')->numeric(4)` llama a `Number::format()`), verde en staging y
**verificado preexistente** en la ronda anterior restaurando el archivo a su versión de HEAD.

### 8.1 Staging

| Ítem | Resultado |
|---|---|
| HEAD de runtime desplegado | **`451ba1188319f0e7200ca3a54b95f0f14fc837c8`** |
| Workflow «Deploy a Contabo» | `completed / success` para ese sha exacto ([run 37035332746](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/actions/runs/37035332746)) |
| Migración | aplicada a la instancia compartida **antes** de desplegar código (aditiva, orden seguro); el `migrate --force` del deploy es un no-op |
| Smoke **antes** de la transición | `/` 200, `/admin/login` 200, las tres pantallas 302 → login 200 |
| Smoke **después** de la transición | idéntico: `/` 200, `/admin/login` 200, las tres pantallas 302 → login 200 |
| 500 / 503 | **ninguno**, en ninguno de los dos smokes |

Límite declarado, igual que en rondas anteriores: esta sesión no tiene clave SSH al host, así que el
HEAD desplegado se verifica por el run del workflow para ese sha exacto y no por inspección directa. El
riesgo de 500 en la pantalla autenticada queda cubierto por los tests que la abren con `assertOk()`,
incluido el nuevo sobre una fila supersedida.

---

## 9. Estructura de evidencia exigida por el contrato de cierre

### 9.1 A) Gates heredados aprobados que siguen vigentes

| Gate | Origen | Estado tras este diff |
|---|---|---|
| Inmutabilidad del payload congelado (9 campos) | TASK-0004 | **Intacto.** Las tres propuestas supersedidas conservan `payload_fingerprint` y `taxonomy_state_fingerprint` idénticos; verificado campo por campo. |
| Separación REVIEW / CONFIRM / APPLY | TASK-0006B | **Intacto y extendido.** `SUPERSEDE` es un cuarto evento con su propio rastro y su propia fila de auditoría; **no** usa `authorization_reference`/`target_environment`, que siguen reservados al APPLY. |
| Gate de confirmación humana exigible | TASK-0006B | **Intacto.** Única precisión: `unconfirmedMembers()` mira sólo miembros `PENDING_APPLY` — ver §4.6. Las 47 pruebas de confirmación pasan sin editar. |
| `confirm()` sólo por HTTP autenticado | TASK-0006C | **Intacto, no tocado.** |
| Asimetría anulación/reasignación de confirmación | TASK-0006C | **Intacto, no tocado.** Las cuatro reglas previas del trigger siguen presentes (verificado en `pg_proc`). |
| Guard de publicación de modelo | TASK-0004 HIGH-2 | **Intacto.** La supersesión no enciende `isApplyingC2Publication()` por ningún camino. |
| Índices únicos parciales de un `PENDING_APPLY` | TASK-0006B | **Intactos y verificados tras migrar.** Son el mecanismo que libera el slot; no se modificaron. |
| Advisory lock de grupo, sin deadlock | TASK-0006B re-audit | **Intacto y reusado**: la supersesión de un grupo toma la misma clave antes de cualquier lock de fila. |
| Preflight de solo lectura + paridad con `apply()` | TASK-0006D | **Intacto.** 24/24 sin editar; el bloqueo nuevo entró por la cadena **compartida**, así que preflight y apply coinciden por construcción. |
| Integridad de payload por miembro de grupo + aborto terminal de grupo | TASK-0006D ronda 2 | **Intacto**, y la atribución de `detected_on_proposal_id` quedó **normalizada** (§4.6). |
| Endurecimiento de UX de candidatos | TASK-0006D | **Intacto y probado en secuencia**: oculto con propuesta viva, visible tras supersedir. |
| Regresión de 32 consultas de búsqueda | heredada | **Heredada sin cambios**: este diff no toca búsqueda, ranking, embeddings, CPV ni la semántica de la taxonomía publicada. |

### 9.2 B) Evidencia nueva de esquema y runtime

1. Migración aditiva aplicada: **10 columnas**, **3 CHECK**, **3 FK** auto-referenciales, **2 índices
   parciales**, **2 reglas nuevas de trigger** — todo verificado de solo lectura contra la base real
   después de migrar.
2. **Cero filas tocadas por la migración**: `superseded_at NOT NULL = 0`, `SUPERSEDED = 0`,
   `PENDING_APPLY = 12` inmediatamente después.
3. Los dos índices únicos parciales **siguen** filtrando por `status = 'PENDING_APPLY'` (`indexdef`
   leído de `pg_indexes`).
4. Las **cuatro** reglas previas del trigger siguen presentes y se sumaron las dos nuevas
   (`pg_proc.prosrc` inspeccionado).
5. Operación `supersedeStaleProposal()` con 5 compuertas de revalidación, transaccional, idempotente y
   serializada por advisory lock en grupos.
6. Comando `taxonomy:supersede-stale-reviewed-proposal` con `--dry-run`, autorización obligatoria y
   reporte de conteos antes/después.
7. `SUPERSEDED` como estado terminal en `apply()`/`preflight()` con cero escrituras.
8. 20 tests nuevos de supersesión + 3 de nivel de base de datos + 3 de UI.

### 9.3 C) Estado exacto antes/después de #420/#421/#422

Ver **§7.3**. Resumen: `status` `PENDING_APPLY → SUPERSEDED` en las tres, rastro completo grabado,
**0 campos inmutables modificados**, candidatos fuente sin cambios.

### 9.4 D) Prueba de que NO se creó ningún sucesor

| Comprobación | Resultado |
|---|---|
| Total de propuestas | **12** antes y después |
| `MAX(id)` de propuestas | **632** — ningún id nuevo |
| Filas con `supersedes_proposal_id` NOT NULL | **0** |
| Filas con `superseded_by_proposal_id` NOT NULL | **0** |
| Filas con `inherited_decision_from_id` NOT NULL | **0** |
| Propuestas por candidato 263 / 264 / 265 | **1 / 1 / 1** (sólo la supersedida) |

### 9.5 E) Prueba de que no hubo mutación de taxonomía publicada

| Medida | Valor | Esperado |
|---|---|---|
| `taxonomy_term_concepts` | **142** | 142 ✓ |
| `taxonomy_canonical_concepts` | **81** | 81 ✓ |
| `taxonomy_term_cpv_relations` | **9749** | 9749 ✓ |
| `taxonomy_candidate_concept_links` | **10** | 10 ✓ |
| `taxonomy_concept_relations` | **2** | 2 ✓ |
| Candidatos `published` | **0** | 0 ✓ |
| Relaciones `approved` | **0** | 0 ✓ |
| Propuestas `APPLIED` | **0** | 0 ✓ |
| Propuestas con `applied_at` NOT NULL | **0** | 0 ✓ |
| Propuestas `ABORTED` | **0** | 0 |
| `PENDING_APPLY` / `SUPERSEDED` | **9 / 3** | 9 / 3 ✓ |
| Las otras nueve propuestas | **0 con status cambiado** | 0 ✓ |

### 9.6 F) Gates invalidados por este diff

**NINGUNO.**

El cambio de mayor riesgo era introducir un estado que `apply()` no conocía. Se cerró con una compuerta
terminal explícita en la cadena compartida (cero escrituras) **más** una prohibición a nivel de base de
datos, y las 119 regresiones heredadas se reejecutaron completas contra el código nuevo **sin editar una
sola línea**. La única modificación de comportamiento en código heredado es el acotamiento de
`unconfirmedMembers()` a miembros `PENDING_APPLY`, que es más preciso y no cambia ningún resultado de
las 47 pruebas de confirmación.

### 9.7 Gates no aplicables

- **APPLY / PUBLISH**: no ejecutados y no autorizados. Ninguna propuesta se aplicó.
- **Sucesores**: no autorizados y no creados.
- **Re-revisión de los tres candidatos**: explícitamente **del dueño**, no del agente. No se hizo
  (§7.8).
- **Merge a `main` / despliegue a producción / rotación de credenciales / migración destructiva**: no
  corresponden y no se hicieron.
