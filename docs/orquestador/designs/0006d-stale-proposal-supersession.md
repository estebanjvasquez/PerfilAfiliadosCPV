# TASK-0006D / PARTE 3 — Diseño: tratamiento gobernado de propuestas revisadas obsoletas

**Estado:** DISEÑO. **No implementado. No ejecutado sobre ningún dato real.**
**Referencia de gobernanza:** Issue #2 comentario `5949253156`, PARTE 3.
**Autorización pendiente:** la transición de datos sobre #420/#421/#422 requiere una autorización
explícita y separada en un comentario posterior del Issue #2 (ver §8).

---

## 1. El problema, con los datos reales medidos

El preflight de solo lectura de la PARTE 1 (ver `audit/phase6_task0006d_preapply_preflight_2026-10-02.md`)
confirmó lo que el orquestador sospechaba y pidió **no** dar por supuesto:

| Propuesta | Candidato | Término | Decisión | Fingerprint congelado | Resultado |
|---|---|---|---|---|---|
| #420 | 263 | `petroleum` | CONTEXT_REQUIRED | `1d0eb041…` | `STALE_TAXONOMY_STATE` |
| #421 | 264 | `crude oil` | CONTEXT_REQUIRED | `1d0eb041…` | `STALE_TAXONOMY_STATE` |
| #422 | 265 | `oil and gas` | CONTEXT_REQUIRED | `1d0eb041…` | `STALE_TAXONOMY_STATE` |

Las otras 9 propuestas están congeladas con `c236bc51…`, que **es** el fingerprint actual.

La causa del cambio de estado está identificada: después de que #420–#422 se revisaran (~09:48–09:50
del 2026-10-01) y antes de la revisión de #491 (13:08), se crearon dos conceptos activos — #2890
(`oleoducto`/`oil pipeline`) y #2891 (`gasoducto`/`gas pipeline`). Cualquier inserción en
`taxonomy_canonical_concepts` cambia `conceptGraphFingerprint()` y por lo tanto
`dryRunInputFingerprint()`.

### 1.1 Por qué no se puede "simplemente aplicarlas"

`apply()` compara el fingerprint congelado contra el actual y, si difieren, llama a `abort()`, que es
**terminal**: la propuesta pasa a `ABORTED` y, como re-congelar está bloqueado por el índice único
parcial `taxonomy_reviewed_proposals_one_pending_per_candidate` (`WHERE status = 'PENDING_APPLY'`),
la decisión humana queda irrecuperable sobre esa fila. Pasar #420–#422 por `apply()` "para ver qué
pasa" **destruye tres decisiones humanas legítimas** sin publicar nada a cambio. Esa es exactamente
la razón de existir del preflight.

### 1.2 Por qué el bloqueo es correcto y no un falso positivo

Vale la pena decirlo explícitamente porque condiciona el diseño: en este caso concreto, los dos
conceptos nuevos (`oleoducto`, `gasoducto`) son **plausiblemente relevantes** para los tres términos
afectados (`petroleum`, `crude oil`, `oil and gas` son términos genéricos de hidrocarburos). Una
decisión `CONTEXT_REQUIRED` significa «este término es válido pero demasiado genérico para sostener
un mapeo directo»; si el grafo ahora tiene conceptos más específicos que antes no existían, la
pregunta «¿sigue siendo demasiado genérico?» **merece volver a hacerse**. El gate no está siendo
pedante: está señalando un cambio que un humano debería mirar.

Esto importa para elegir entre las opciones: lo que hace falta no es una forma de *saltear* la
revalidación, sino una forma de **volver a pedir la decisión humana sin destruir la anterior**.

---

## 2. Restricciones que cualquier opción tiene que respetar

Salen del contrato C2 ya auditado y aprobado, no de preferencias de esta ronda:

1. **Inmutabilidad del payload.** `decision_payload`, `payload_fingerprint`,
   `taxonomy_state_fingerprint`, `reviewer_id` y `reviewed_at` de una propuesta congelada **nunca** se
   reescriben. El `payload_fingerprint` se computa sobre una lista fija de 9 campos, así que agregar
   columnas no lo invalida — pero modificar cualquiera de esos 9 sí, y con razón.
2. **Un solo `PENDING_APPLY` por candidato/relación**, impuesto por índice parcial en la base (no
   TOCTOU). Un sucesor no puede coexistir como segundo `PENDING_APPLY` mientras el predecesor siga
   ahí.
3. **`ABORTED` es terminal.** No se "des-aborta" nada.
4. **Nada se borra.** Ni filas de propuesta, ni de auditoría.
5. **La confirmación humana sólo llega por HTTP autenticado** (TASK-0006C). Ni consola ni agente
   pueden confirmar; y una confirmación anulada nunca se reasigna, sólo se limpia.
6. **La OPCIÓN D está prohibida** (ver §7).

---

## 3. OPCIÓN A — Supersesión no destructiva + propuesta sucesora

La dirección preferida por el orquestador. Forma concreta:

### 3.1 Esquema (migración aditiva)

```
taxonomy_reviewed_proposals
  + superseded_at                TIMESTAMP NULL
  + superseded_by_proposal_id    BIGINT NULL  -> taxonomy_reviewed_proposals(id)
  + supersession_reference       VARCHAR(255) NULL   -- referencia de gobernanza
  + supersession_reason          TEXT NULL
  + supersession_actor_type      VARCHAR(32) NULL    -- human_reviewer | agent
  + supersession_channel         VARCHAR(16) NULL    -- auto-capturado
  + supersedes_proposal_id       BIGINT NULL  -> taxonomy_reviewed_proposals(id)
  + inherited_decision_from_id   BIGINT NULL  -> taxonomy_reviewed_proposals(id)
  + supersession_state_delta     JSONB NULL   -- el delta de estado calculado en el instante
                                              -- de la transición; ver §3.5
```

**`supersession_state_delta` es parte del esquema, no un extra opcional** (corrección de consistencia
del re-audit `5952211890`, punto E: la versión anterior de este diseño exigía el diff en §3.5 y
nombraba la columna más abajo, pero la lista de columnas la omitía, así que la especificación se
contradecía a sí misma).

Por qué tiene que ser una columna persistida y no un cálculo al momento de mostrar la pantalla: el
`taxonomy_state_fingerprint` es un **hash**, así que el delta exacto **no se puede reconstruir** a
partir de él más tarde. Si no se guarda cuando la supersesión ocurre, la información se pierde para
siempre y la confirmación del sucesor queda sin la evidencia que la hace significativa — que es
justamente la condición que §8 pone para que la OPCIÓN A sea aceptable. Alternativa igualmente durable,
si se prefiriera no agregar la columna: una fila dedicada de `taxonomy_audit_log` con el delta
estructurado en su payload, escrita en la MISMA transacción de la supersesión. Lo que **no** es
aceptable es calcularlo al vuelo en la UI.

Queda **fuera** del `CHECK` de completitud por la misma razón que `superseded_by_proposal_id`: una
supersesión sin sucesor (§3.4) no necesita diff, porque no hay ninguna decisión heredada que el humano
deba evaluar — vuelve a revisar desde la evidencia actual.

Nuevo valor de `status`: **`SUPERSEDED`**. Elegido sobre `STALE_SUPERSEDED` porque el motivo
(obsolescencia, drift, cambio de criterio) ya vive en `supersession_reason`, y meterlo en el nombre
del estado obligaría a inventar un estado nuevo por cada motivo futuro.

`CHECK` de completitud, mismo criterio que los CHECK de confirmación/invalidación de TASK-0006B/C:
`superseded_at`, `supersession_reference`, `supersession_reason`, `supersession_actor_type` y
`supersession_channel` son todos NULL o todos NOT NULL. `superseded_by_proposal_id`,
`supersedes_proposal_id`, `inherited_decision_from_id` y `supersession_state_delta` quedan **fuera**
del CHECK a propósito: una supersesión puede registrarse sin sucesor (ver §3.4), y en ese caso no hay
lineage hacia adelante ni decisión heredada ni diff que mostrar.

Regla de integridad que sí conviene exigir, porque es la que hace que la confirmación no sea
ceremonial: **si hay sucesor, tiene que haber diff.** Expresable como un segundo CHECK
(`superseded_by_proposal_id IS NULL OR supersession_state_delta IS NOT NULL`) sobre el predecesor, o
sobre el sucesor contra `inherited_decision_from_id`. Lo decide la implementación; lo que no puede
pasar es que exista un sucesor con decisión heredada y sin delta persistido.

### 3.2 Compatibilidad con el índice único parcial — el punto crítico

El índice es `WHERE status = 'PENDING_APPLY'`. Al pasar el predecesor a `SUPERSEDED` **sale** del
índice, y recién entonces el sucesor puede entrar como `PENDING_APPLY`. Las dos escrituras van en
**una sola transacción**, en ese orden, con `lockForUpdate()` sobre el predecesor:

```
BEGIN
  SELECT ... FROM taxonomy_reviewed_proposals WHERE id = :pred FOR UPDATE
  -- revalida: status = PENDING_APPLY, payload_fingerprint válido
  UPDATE predecesor SET status='SUPERSEDED', superseded_at=..., supersession_*=...
  INSERT sucesor (status='PENDING_APPLY', supersedes_proposal_id=:pred,
                  taxonomy_state_fingerprint=<ACTUAL>, requires_human_confirmation=TRUE,
                  prepared_by_actor_type='agent')
  UPDATE predecesor SET superseded_by_proposal_id=<nuevo id>
  INSERT taxonomy_audit_log x2  (field='superseded_at' y field='status')
COMMIT
```

**El índice no se debilita ni se toca.** Si dos supersesiones concurrentes compitieran por el mismo
candidato, la segunda encuentra el predecesor fuera de `PENDING_APPLY` bajo el lock y devuelve un
resultado idempotente (`ALREADY_SUPERSEDED`); y si llegara a intentar el INSERT, el índice lo
rechaza igual. No hace falta ningún mecanismo nuevo de concurrencia.

**Grupos bilingües:** una supersesión que toque a un miembro de un grupo tiene que superseder al
**grupo completo** en la misma transacción, tomando primero el advisory lock del grupo
(`groupAdvisoryLockKey()`), por la misma razón que `apply()`: medio grupo superseded dejaría al
hermano sin forma de converger. (Hoy no aplica: los dos grupos reales no están obsoletos.)

### 3.3 El sucesor NO hereda aprobación humana

Requisito explícito del orquestador, y es el corazón del diseño. El sucesor se crea con:

- `prepared_by_actor_type = 'agent'`, `prepared_via` auto-capturado;
- `requires_human_confirmation = TRUE`;
- `taxonomy_state_fingerprint` = el **actual**;
- `decision` y campos de decisión **copiados** del predecesor, pero marcados en el payload como
  `inherited_from_proposal_id` + `inherited_decision = true`;
- `reviewer_id` = quien ejecuta la supersesión, **con** `prepared_by_actor_type='agent'` para que la
  fila no afirme que esa persona tomó la decisión (es exactamente la distinción que TASK-0006B
  introdujo y que el re-audit `5934324928` exigió poder expresar estructuralmente).

Con eso, `apply()` ya rechaza el sucesor con `HUMAN_CONFIRMATION_REQUIRED` **sin ningún código
nuevo**: el gate existente consulta `requires_human_confirmation`. La decisión vieja viaja como
*evidencia*, nunca como aprobación vigente.

### 3.4 Supersesión sin sucesor

Caso real y necesario: si al revisar el cambio el humano concluye que la decisión ya no corresponde,
tiene que poder supersedir **sin** crear sucesor (`superseded_by_proposal_id = NULL`). El candidato
vuelve a quedar sin propuesta viva y — gracias a la PARTE 2 de esta misma tarea — la UI vuelve a
ofrecerle `freezeReview` automáticamente. Esto hace que la OPCIÓN C sea un **subconjunto** de la
OPCIÓN A, no una alternativa (ver §5).

### 3.5 El diff que hace la confirmación significativa, no ceremonial

Advertencia explícita del orquestador: «the successor must show the human what changed». Sin esto, la
confirmación degenera en un botón. Mínimo exigible en la UI de confirmación del sucesor:

1. **El delta de estado**: fingerprint viejo vs. actual, y qué tablas de
   `dryRunInputFingerprint()` cambiaron (`tableVersionSignal()` es por tabla, así que el delta se
   puede calcular sin inventar nada).
2. **Los conceptos nuevos/eliminados** entre los dos estados. Para el caso real son #2890 y #2891,
   que es precisamente la información que vuelve a abrir la pregunta.
3. **La decisión heredada, marcada como heredada**, con enlace al predecesor.
4. **El payload del predecesor**, inmutable y visible.

Limitación honesta: el fingerprint es un hash, así que el delta exacto **no** se puede reconstruir
desde él. El diff tiene que calcularse comparando el estado actual contra lo que se pueda derivar
del predecesor (su `reviewed_at` acota la ventana temporal) o persistiendo un snapshot estructurado
del estado en el momento de la supersesión. Lo segundo es más fiable y es **lo que este diseño
adopta**: la columna `supersession_state_delta JSONB` de §3.1, escrita en la MISMA transacción de la
transición, con el detalle calculado en ese instante. No es opcional ni un "nice to have": si el delta
no se persiste cuando ocurre la supersesión, deja de existir, y entonces la confirmación del sucesor no
puede mostrar qué cambió — precisamente el consentimiento ceremonial que §8 pone como condición para
que la OPCIÓN A sea aceptable.

---

## 4. OPCIÓN B — Registro de revalidación separado

Entidad/evento inmutable aparte (`taxonomy_reviewed_proposal_revalidations`) que ata propuesta vieja
+ fingerprint viejo + fingerprint actual + evidencia + confirmación humana; `apply()` acepta una
revalidación vigente como sustituto de la igualdad de fingerprints.

**Por qué no.** El contrato de obsolescencia de C2 es una sola pregunta con una sola fuente:
«¿el estado actual es idéntico al que el humano revisó?». La OPCIÓN B la convierte en dos —
«¿coinciden los fingerprints?» **o** «¿hay una revalidación válida?» — y entonces `apply()` necesita
decidir qué cuenta como «vigente»: ¿caduca?, ¿la invalida un cambio posterior?, ¿qué pasa con dos
revalidaciones?, ¿y si la revalidación es más vieja que el último cambio del grafo? Cada una de esas
preguntas es una forma nueva de aplicar algo que nadie revisó contra el estado real, dentro del
único gate que hoy no tiene ambigüedad posible.

Único beneficio real sobre A: preserva la identidad de la propuesta original (sigue `PENDING_APPLY`).
Pero A la preserva igual de bien — la fila no se modifica en sus 9 campos de decisión, sólo sale de
la cola. «Preservar la identidad» no exige «seguir en la cola de aplicación».

El orquestador pidió «a very strong justification» para elegirla. No la hay.

---

## 5. OPCIÓN C — Supersesión humana + re-congelamiento manual

Supersedir y devolver el candidato a la UI de revisión normal, para que el humano congele una
propuesta nueva desde cero.

**Es la variante de MÁXIMA agencia humana** y la más simple conceptualmente: no hay decisión
heredada, no hay riesgo de confirmación ceremonial, el humano revisa la evidencia actual y decide.
Su costo es trabajo manual y que no deja rastro de que la decisión nueva *reemplaza* a una anterior
(salvo que la supersesión lo registre, que es justamente lo que A aporta).

**Observación central de este diseño:** C no es una alternativa a A, es **A sin sucesor** (§3.4).
Las dos necesitan exactamente la misma pieza nueva — la transición gobernada a `SUPERSEDED` — y las
dos necesitan la corrección de UX de la PARTE 2, ya entregada en esta tarea. Implementar A entrega C
gratis; implementar C y después querer A obliga a agregar la lineage después.

---

## 6. Comparación en los 10 criterios pedidos

| Criterio | A (supersesión + sucesor) | B (registro de revalidación) | C (supersesión + re-freeze manual) |
|---|---|---|---|
| **Auditabilidad** | Alta. Lineage bidireccional en la fila + 2 filas de auditoría; se reconstruye sin texto libre. | Alta en la tabla nueva, pero la historia queda **repartida** en dos objetos que hay que cruzar. | Media. La supersesión se audita; el vínculo con la decisión nueva es sólo temporal/por candidato. |
| **Agencia humana** | Buena **si** el diff se implementa; sin diff, riesgo real de confirmación ceremonial. | Igual que A y con el mismo riesgo. | **Máxima**: no hay decisión heredada que arrastre un sesgo. |
| **Inmutabilidad** | Intacta. Los 9 campos del fingerprint no se tocan; sólo columnas nuevas + `status`. | Intacta en la propuesta; **pero** debilita el gate de obsolescencia, que es parte del mismo contrato. | Intacta. |
| **Índices parciales únicos** | Compatible por construcción: el predecesor sale de `PENDING_APPLY` antes de que entre el sucesor, en una transacción. | Compatible **sin cambios** (nada sale ni entra en la cola). Es su mejor propiedad. | Compatible, igual que A. |
| **Concurrencia / idempotencia** | `lockForUpdate()` + re-chequeo; advisory lock por grupo cuando aplica. Patrón ya probado 3 veces en este servicio. | Hay que definir "revalidación vigente" bajo concurrencia — **el punto más débil**. | Igual que A, con menos partes. |
| **Complejidad de `apply()`** | **Cero cambios.** El gate de confirmación existente ya rechaza el sucesor. | Cambio **sustantivo** en el gate más crítico. | Cero cambios. |
| **Complejidad de UI** | Media: pantalla de diff + confirmación (reusa la acción de TASK-0006B). | Media-alta: pantalla de revalidación + estado de vigencia. | Baja: reusa `freezeReview`, ya corregido en la PARTE 2. |
| **Alcance de migración** | 1 migración aditiva: **9 columnas** (8 de supersesión/lineage + `supersession_state_delta`) + 1 valor de status + 2 CHECK + 1 índice. | 1 tabla nueva + FKs + índices + cambio de semántica en `apply()`. | 1 migración aditiva, subconjunto de la de A (sin lineage ni delta). |
| **Rollback** | Limpio: las columnas nuevas quedan NULL y `SUPERSEDED` sin usar; nada que revertir en datos. | Limpio en esquema, **sucio en semántica**: si se revierte el cambio de `apply()`, las revalidaciones emitidas pierden efecto en silencio. | Limpio. |
| **Explicar por qué se refrescó** | **Lo mejor**: motivo + referencia + delta de estado en la propia fila. | Bueno, en la tabla nueva. | Débil: la propuesta nueva no dice a qué reemplaza salvo por la lineage de A. |

---

## 7. OPCIÓN D — NO ACEPTABLE

Registrado para que no reaparezca: está **prohibido** sobrescribir `taxonomy_state_fingerprint` de
#420–#422, recalcular su `payload_fingerprint` para que pasen, agregar un flag de "forzar apply
obsoleto", desactivar `STALE_TAXONOMY_STATE`, o borrar y recrear la historia. Las tres primeras
convierten la tamper-detection en decorado; las dos últimas destruyen el contrato de inmutabilidad
que es la razón de ser de toda la fase C2.

---

## 8. Recomendación

**OPCIÓN A, con el sucesor opcional (§3.4) y con el diff de estado como requisito de entrega, no
como mejora opcional.**

Razones, en orden de peso:

1. **No toca `apply()`.** El gate de obsolescencia y el de confirmación humana quedan exactamente
   como están, ya auditados y ya probados. B pide modificar el gate más crítico del sistema para
   resolver un problema de tres filas.
2. **Una sola fuente de verdad sobre ejecutabilidad** sigue siendo `status` + fingerprints. B crea
   una segunda.
3. **Compatible con el índice parcial por construcción**, con el patrón de concurrencia que este
   servicio ya usa tres veces.
4. **Contiene a C.** Supersedir sin sucesor devuelve el candidato a la UI de revisión normal —y la
   PARTE 2 de esta tarea ya hace que esa UI reaparezca sola. Así el dueño de la taxonomía elige
   caso por caso entre «confirmar la decisión heredada viendo qué cambió» y «revisar de nuevo desde
   cero», sin que haga falta otra migración.

Con una condición explícita: **sin el diff de §3.5, la OPCIÓN A es peor que la OPCIÓN C**, porque
presenta al humano una decisión ya redactada y le pide confirmarla sin mostrarle qué cambió — lo que
produciría exactamente el consentimiento ceremonial que el orquestador advirtió. Si el diff no se
implementa, hay que entregar C (supersesión sin sucesor).

### Para los tres casos reales concretos

Recomendación específica: **supersesión sin sucesor** (la rama C dentro de A). Los conceptos nuevos
#2890/#2891 son plausiblemente pertinentes para `petroleum`/`crude oil`/`oil and gas` (§1.2), así que
la pregunta que corresponde no es «¿confirmás la decisión vieja?» sino «¿sigue siendo demasiado
genérico ahora que existen `oleoducto` y `gasoducto`?». Esa es una revisión nueva, no una
confirmación.

### Orden de implementación sugerido

1. Migración aditiva (§3.1) — sin backfill, sin tocar datos.
2. `ReviewedProposalService::supersede()` + tests (idempotencia, concurrencia, inmutabilidad campo
   por campo, grupo completo, CHECK de completitud).
3. `preflight()` reporta `SUPERSEDED` como estado ya resuelto (una línea en
   `evaluateApplicability()`; el vocabulario de bloqueo ya distingue estados resueltos de bloqueos).
4. Cálculo y persistencia del delta de estado (§3.5).
5. UI: lineage y diff en `TaxonomyReviewedProposalResource` (solo lectura, sin APPLY).
6. **Recién entonces**, y con autorización explícita y separada en el Issue #2, la transición de
   datos sobre #420/#421/#422.

**Nada de esto está implementado ni ejecutado en TASK-0006D.** Los pasos 1–5 son código y se podrían
hacer sin tocar datos reales; el paso 6 es una transición de datos y está **NO AUTORIZADA** hasta un
comentario posterior del Issue #2 que la autorice de forma expresa.
