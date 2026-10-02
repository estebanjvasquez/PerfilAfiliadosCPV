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

*(completado tras la ejecución; ver §7.1–§7.4)*

---

## 8. Tests

*(completado tras la ejecución; ver §8.1)*

---

## 9. Estructura de evidencia exigida por el contrato de cierre

*(completado tras la ejecución; ver §9.1–§9.6)*
