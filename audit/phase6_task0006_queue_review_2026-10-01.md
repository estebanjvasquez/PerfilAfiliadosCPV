# TASK-0006 — Revisión humana de la cola real (reanudada tras el cierre de TASK-0006A)

> **ESTADO ACTUAL: `CORRECTIONS_REQUIRED` / COMPUERTA DE GOBERNANZA HUMANA.** El re-audit del
> orquestador (Issue #2 comentario
> [`5934324928`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5934324928))
> **no acepta las propuestas #492–#495 como decisiones humanas completadas**: las reclasifica como
> **`AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED`**. Las secciones 1–9 describen la ronda tal como
> se ejecutó; **la sección 10 contiene la reclasificación, los 3 bloqueos y el hueco de workflow
> reportado**, y manda sobre la lectura de las secciones 1–3 en cuanto a la naturaleza de esas
> cuatro propuestas. Nada se aplicó ni publicó.

**Fuente:** Issue #2 comentario [`5933152293`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5933152293)
(TASK-0006A = PASS/CLOSED; autoriza reanudar TASK-0006 solo para la revisión/freeze de la cola
restante).

**Re-audit:** Issue #2 comentario [`5934324928`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5934324928)
(2026-10-01, HEAD revisado `bd70ac7`) — ver sección 10. Texto verbatim en
[`docs/orquestador/tasks/0006-queue-human-review.md`](../docs/orquestador/tasks/0006-queue-human-review.md).

**Base:** HEAD `d532a0bd88d3dd3af27a4ea8ba53f179fa6c7f3c`. **Fecha:** 2026-10-01.

**Alcance autorizado:** revisar/congelar los candidatos **266–271** y las **2 relaciones candidatas**,
con la UI mejorada. Sin APPLY/publicación, sin nueva corrida de generación de candidatos, sin merge a
`main` ni producción, preservando las 4 propuestas ya congeladas. **Instrucción explícita: si un
ítem es ambiguo o la evidencia es insuficiente, no adivinar — dejarlo y reportarlo.**

---

## 1. Resultado

**4 decisiones congeladas, 4 ítems reportados sin decidir.** Cero APPLY, cero publicación.

| Ítem | Término / relación | Resultado |
|---|---|---|
| Candidato 266 | `exploration` (en) | **CONGELADO** → `CONTEXT_REQUIRED`, propuesta #492 — reclasificada `AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED` (§10) |
| Candidato 267 | `upstream` (en) | **CONGELADO** → `CONTEXT_REQUIRED`, propuesta #493 — reclasificada `AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED` (§10) |
| Candidato 268 | `midstream` (en) | **CONGELADO** → `CONTEXT_REQUIRED`, propuesta #494 — reclasificada `AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED` (§10) |
| Candidato 269 | `downstream` (en) | **CONGELADO** → `CONTEXT_REQUIRED`, propuesta #495 — reclasificada `AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED` (§10) |
| Candidato 270 | `refinery` (en) | **SIN DECIDIR** — requiere aclaración (ver §4) |
| Candidato 271 | `refinería` (es) | **SIN DECIDIR** — requiere aclaración (ver §4) |
| Relación 61 | `production` → `production casing` | **SIN DECIDIR** — requiere política (ver §5) |
| Relación 62 | `oil` → `oil-base mud` | **SIN DECIDIR** — requiere política (ver §5) |

---

## 2. Atribución de las 4 decisiones congeladas (importante para auditoría)

> **Resuelto por el re-audit `5934324928`:** la atribución documentada **no** basta. El orquestador
> dictaminó que `reviewer_id=3` + nota contradictoria en `context_reason` no es una base válida de
> auditoría, y reclasificó #492–#495 como `AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED`. Leer esta
> sección junto con §10.2 y §10.3.

`ReviewedProposalService::freeze()` exige un `User` real y lo graba como `reviewer_id` con
`actor_type=user`. En esta instalación **todas** las cuentas con permiso `update` sobre candidatos son
personas nombradas con rol `super_admin` (#3, #10, #16, #17); no existe una cuenta de servicio. La
cuenta del operador que dirigió este trabajo (#14) tiene rol `afiliados` y **no** tiene ese permiso.

Esto se planteó explícitamente al operador antes de escribir nada, porque atribuir decisiones del
agente a la cuenta de una persona nombrada afecta la veracidad de un rastro de auditoría cuyo
propósito es registrar **quién** decidió. El operador instruyó proceder bajo la cuenta **#3**
(`eamner@yahoo.com`, la misma que tomó las 4 decisiones anteriores) con la atribución documentada.

**Para que el registro durable sea autodescriptivo y no dependa de este documento**, cada una de las
4 propuestas lleva la atribución **dentro de su propio `context_reason`**:

> `[Decision preparada por el agente Claude Code por instruccion explicita del operador y congelada
> bajo la cuenta de revisor #3; ver audit/phase6_task0006_queue_review_2026-10-01.md. No es una
> decision tomada de forma autonoma por el titular de la cuenta.]`

**Distinción clara para el orquestador:**

| Propuestas | Decididas por | Decisión |
|---|---|---|
| #420, #421, #422 | el revisor **humano** (TASK-0006, 2026-10-01 09:48–09:50) | `CONTEXT_REQUIRED` sobre 263/264/265 |
| #491 | el revisor **humano** (TASK-0006, 2026-10-01 13:08) | `MAP_TO_EXISTING` sobre 272 (`pipeline`) → concepto #2890 |
| #492, #493, #494, #495 | el **agente**, por instrucción del operador, bajo la cuenta #3 | `CONTEXT_REQUIRED` sobre 266/267/268/269 |

---

## 3. Las 4 decisiones congeladas y su fundamento

**Observación transversal:** los seis candidatos de la cola restante son `PROPOSE_NEW_CONCEPT`, con
**cero** duplicados posibles según el Builder, **cero** coincidencias en el explorador de conceptos, y
los seis marcados **`stale=true`** (el grafo de conceptos cambió desde que se generó el candidato).

Sobre la obsolescencia: `freeze()` estampa el fingerprint del estado de taxonomía **actual**, y
`apply()` aborta si ese estado vuelve a cambiar. La marca `stale` del candidato indica que la
*sugerencia del Builder* se calculó contra un grafo anterior — y para `CONTEXT_REQUIRED` eso es
irrelevante, porque la decisión consiste precisamente en **no** usar esa sugerencia ni mapear a nada.
Por eso la obsolescencia no debilita estas cuatro decisiones, mientras sí habría sido un factor en un
`MAP_TO_EXISTING` o `CREATE_NEW`.

Las cuatro son además la decisión **más conservadora disponible**: `CONTEXT_REQUIRED` congela cero
mapeos y cero conceptos, y su `apply()` escribe cero mapeos de taxonomía.

| # | Término | Evidencia | Por qué CONTEXT_REQUIRED |
|---|---|---|---|
| 266 | `exploration` (en, `technical_term`, `oil_gas_exclusivity` 0.88) | 8 relaciones CPV propias, **todas** en `needs_review`, dispersas en CPV-25, CPV-25.01, CPV-25.02, CPV-25.06 y subcódigos | Designa una **fase** de la cadena de valor, no un producto/servicio específico. La dispersión entre familias confirma que no resuelve a una categoría |
| 267 | `upstream` (en) | 6 relaciones CPV en `needs_review`, dispersas en familias sin relación entre sí: CPV-37.10, CPV-31.05, CPV-37.06, CPV-31.01, CPV-30.01, CPV-37.01 | Designa un **segmento** de la cadena. Esa dispersión es evidencia directa de no-especificidad; mapearlo abanicaría el término a categorías superficialmente relacionadas |
| 268 | `midstream` (en) | **cero** relaciones CPV (ni aprobadas ni candidatas) | Segmento de la cadena, sin ninguna evidencia gobernada que respalde un mapeo específico |
| 269 | `downstream` (en) | 2 relaciones CPV en `needs_review` | Segmento de la cadena, sin evidencia aprobada que justifique un mapeo incondicional |

Es exactamente el caso para el que se introdujo `CONTEXT_REQUIRED` en TASK-0004 ronda 3: término
válido del dominio petrolero pero demasiado genérico/ambiguo para mapearlo sin contexto.

Ningún texto de esta tarea afirma que `CONTEXT_REQUIRED` sea consumido hoy por el runtime de
búsqueda — ningún consumidor lo lee.

---

## 4. Candidatos 270 y 271 — sin decidir, requieren aclaración

`refinery` (#270, en, `technical_term`) y `refinería` (#271, es, **`translation_alias`** con
`canonical_term='refinery'`, región LATAM) son un **par bilingüe del mismo concepto**, y **ambos
candidatos proponen el mismo nombre de concepto nuevo: `refinery`**.

El significado no es ambiguo: una refinería es una instalación concreta de procesamiento downstream, y
no existe hoy ningún concepto canónico para ella (cero duplicados, cero coincidencias en el
explorador). Lo que está bloqueado es **cómo expresar la decisión correcta dentro de C2**:

1. **Congelar `CREATE_NEW` en los dos crearía dos conceptos duplicados.** Verificado en el código de
   `apply()`: el concepto se crea poblando **un solo** nombre según el idioma del término —
   `canonical_name_en` si el término es `en`, `canonical_name_es` si no. Para #271 (es) eso dejaría
   `canonical_name_es='refinery'`, o sea la palabra **inglesa** en el campo español.
2. **No se puede congelar `MAP_TO_EXISTING` en uno apuntando al concepto del otro**, porque ese
   concepto solo existiría después de un `APPLY`, que no está autorizado. `freeze()` valida que el
   concepto destino exista.

O sea: la secuencia correcta (crear el concepto desde uno, luego vincular el hermano) **no es
expresable** con las decisiones C2 disponibles sin un APPLY intermedio. Y la identidad bilingüe
deseada del concepto (`refinery` / `refinería`) no cabe en el payload de `CREATE_NEW`, que acepta un
único `new_concept_name`.

**No se adivinó.** Ambos candidatos quedan `pending` sin propuesta. Lo que hace falta decidir:

- qué nombre canónico ES y EN debe llevar el concepto;
- en qué orden resolver el par, dado que vincular el segundo término exige que el concepto ya exista
  (es decir, probablemente un APPLY intermedio autorizado entre las dos decisiones);
- o bien si corresponde una capacidad nueva (que `CREATE_NEW` acepte nombre ES y EN, y/o poder
  agrupar varios términos en un mismo concepto nuevo) — lo cual sería trabajo de diseño con compuerta
  propia, no parte de TASK-0006.

---

## 5. Relaciones 61 y 62 — sin decidir, requieren política

Las dos son propuestas automáticas del Builder (`proposeConceptRelations`,
`canonical-concept-builder/phase-c-v1`) del tipo no direccional `RELATED_TO`, y las dos se apoyan en
**similitud léxica por una palabra genérica compartida**, con `term_or_alias_overlap = 0`:

| Relación | Origen → destino | Confianza | Evidencia | Lectura |
|---|---|---|---|---|
| #61 | `production / production` → `production casing` | 0.537 | `shared_cpv`=1, `name_similarity`=0.6111, `term_or_alias_overlap`=0, `weight`=0 | La similitud viene enteramente de la palabra compartida "production". `production casing` es un bien tubular específico; `production` es una fase de la cadena. La relación no es falsa (se usa en pozos de producción) pero es incidental |
| #62 | `oil / oil` → `oil-base mud` | 0.4359 | `shared_cpv`=1, `name_similarity`=0.3077, `term_or_alias_overlap`=0 | Igual patrón por la palabra "oil". Existe una relación real, pero es **composicional** (el lodo tiene base de aceite), que `RELATED_TO` no captura bien; la propuesta surgió de similitud léxica |

**Por qué no se decidieron:** admitir o rechazar aristas `RELATED_TO` débiles derivadas de una palabra
genérica compartida es una **decisión de política de curación**, y no existe en el proyecto ningún
umbral declarado de confianza/evidencia para relaciones concepto↔concepto. Decidir sin esa política
sería adivinar la intención del dueño de la taxonomía, que es justo lo que la instrucción prohíbe.

Lo que hace falta: un criterio explícito (p. ej. umbral mínimo de confianza, o la regla de que
`term_or_alias_overlap = 0` con similitud impulsada por una palabra genérica basta para rechazar), o
una decisión caso por caso del dueño. Ambas relaciones quedan en `status=candidate` sin propuesta.

Nota: si la decisión fuera publicar #62, vale considerar que el tipo semánticamente correcto podría no
ser `RELATED_TO` — pero cambiar el tipo propuesto no es una decisión de revisión, es otra propuesta.

---

## 6. Estado protegido — antes y después

| Tabla | Antes | Después | Nota |
|---|---|---|---|
| `taxonomy_candidate_concept_links` | 10 | **10** | sin cambios |
| `taxonomy_concept_relations` | 2 | **2** | sin cambios |
| `taxonomy_term_concepts` | 142 | **142** | **nada publicado** |
| `taxonomy_canonical_concepts` | 81 | **81** | **ningún concepto creado** |
| `taxonomy_term_cpv_relations` | 9749 | **9749** | sin cambios |
| `taxonomy_reviewed_proposals` | 4 | **8** | +4 decisiones congeladas (#492–#495) |
| Propuestas aplicadas | 0 | **0** | **ningún APPLY ocurrió** |

**Las 4 propuestas preexistentes (#420, #421, #422, #491) quedaron intactas** — verificado
explícitamente: mismos ids, mismas decisiones, mismos estados, `applied_at` en NULL.

**Ítems que debían quedar sin tocar, verificado:** candidatos #270 y #271 en `status=pending` con 0
propuestas; relaciones #61 y #62 en `status=candidate` con 0 propuestas.

**Las filas fuente no fueron mutadas por el freeze**, como manda el contrato C2: los 10 candidatos
siguen en `status=pending` con `reviewed_at=NULL`. `freeze()` nunca toca el origen.

Estado de la cola tras esta ronda:

- **Con decisión congelada (8):** 263, 264, 265, 266, 267, 268, 269, 272
- **Sin decidir (2 candidatos + 2 relaciones):** 270, 271, y las relaciones 61, 62

---

## 7. Gates heredados

| Gate | Invalidado | Análisis |
|---|---|---|
| Regresión de 32 queries | **NO** | Cero cambios de código. No se tocó ningún consumidor de búsqueda ni la taxonomía publicada; `taxonomy_term_concepts` y `taxonomy_term_cpv_relations` sin cambios |
| Invariantes de taxonomía publicada | **NO** | 142 / 81 / 9749 sin cambios. Los 81 conceptos incluyen los 2 que creó el humano durante TASK-0006A (ya aceptados por el comentario `5933152293`) |
| Implementación C2 (TASK-0004) | **NO** | Se **consumió** `freeze()` sin modificarlo. Ninguna guarda tocada |
| TASK-0005 / TASK-0006A | **NO** | Sin cambios de código |
| Riesgo adyacente de relaciones aprobadas | n/a | Sigue OPEN para una tarea futura de endurecimiento; esta ronda no lo toca |

**Nota del comentario `5933152293` a tener en cuenta en el futuro:** los conceptos #2890 y #2891 ya
forman parte del estado vivo de taxonomía y **deben incluirse en la validación de
obsolescencia/fingerprint antes de cualquier APPLY futuro**. Las 4 propuestas congeladas en esta ronda
estamparon su `taxonomy_state_fingerprint` con esos conceptos ya presentes.

---

## 8. Sin cambios de código

Esta ronda es **exclusivamente de revisión de datos**: no se modificó ningún archivo de aplicación,
test, migración ni configuración. Las únicas escrituras son las 4 propuestas congeladas y sus
entradas de auditoría correspondientes.

---

## 9. Condiciones STOP — ninguna alcanzada

Ningún APPLY ni publicación; ninguna de las 4 propuestas preexistentes modificada, borrada ni
re-congelada; ninguna corrida nueva de generación de candidatos; sin migración destructiva; sin
despliegue a producción; sin merge a `main`; sin rotación de credenciales. Los ítems ambiguos quedaron
sin decidir y reportados, no adivinados.

---

## 10. Re-audit del orquestador — comentario `5934324928` (CORRECTIONS_REQUIRED / compuerta de gobernanza humana)

**HEAD revisado:** `bd70ac7b3cf012bdd1f57329f4891fe08a0445f4`. **Veredicto:**
`CORRECTIONS_REQUIRED / WAITING FOR HUMAN GOVERNANCE DECISIONS`. Texto verbatim en
[`docs/orquestador/tasks/0006-queue-human-review.md`](../docs/orquestador/tasks/0006-queue-human-review.md).

### 10.1 Aceptado por el re-audit

| Punto | Verificado por el orquestador |
|---|---|
| HEAD solo documentación/auditoría | sin cambios de código de aplicación ni de runtime |
| Cero APPLY/publicación | confirmado |
| `taxonomy_term_concepts` = 142, `taxonomy_term_cpv_relations` = 9749 | sin cambios |
| `taxonomy_canonical_concepts` = 81 | incluye los 2 conceptos `pipeline` creados por el humano, ya aceptados |
| Propuestas #420/#421/#422/#491 | intactas |
| 270/271 y relaciones 61/62 dejados sin decidir | **correcto**, no adivinados |
| Regresión de 32 queries | sigue heredada; nada de búsqueda/runtime/mapeo publicado cambió |

### 10.2 BLOQUEO 1 — las decisiones 266–269 no son decisiones de revisión humana

El contrato de TASK-0006 es explícitamente una **revisión humana controlada** de la cola real. El
orquestador **no acepta** las propuestas #492–#495 como decisiones humanas completadas, y la razón es
de fondo, no de forma: `reviewer_id=3` / `actor_type=user` dice **estructuralmente** que el usuario #3
revisó la propuesta, mientras la nota durable en `context_reason` dice que la decisión la preparó
Claude Code. **Una auditoría de gobernanza no puede apoyarse en una atribución contradictoria.**

Esa contradicción es exactamente el riesgo que esta sesión planteó al operador **antes** de escribir
(ver §2) y que el operador resolvió instruyendo proceder bajo la cuenta #3 con atribución documentada.
El re-audit confirma que la atribución documentada **no resuelve** el problema estructural: deja el
registro diciendo dos cosas distintas a la vez.

**Reclasificación aplicada en este documento, en `current_task.md` y en el handoff:**

| Propuesta | Candidato | Clasificación nueva |
|---|---|---|
| #420, #421, #422 | 263, 264, 265 | `HUMAN_REVIEWED / PROTECTED` |
| #491 | 272 (`pipeline`) | `HUMAN_REVIEWED / PROTECTED` |
| **#492, #493, #494, #495** | **266, 267, 268, 269** | **`AGENT_PREPARED / HUMAN_CONFIRMATION_REQUIRED`** |

El análisis de los cuatro términos (§3) se conserva íntegro, pero **con el estatus de
RECOMENDACIÓN/EVIDENCIA**, no de decisión completada. El orquestador lo acepta explícitamente en ese
carácter.

**Acción requerida y cumplida en esta ronda:** no aplicar #492–#495; **no** borrarlas, mutarlas,
reemplazarlas ni re-congelarlas sin una autorización de limpieza separada y explícita; preservarlas
como registros congelados existentes y marcarlas en handoff/auditoría. Hecho: esta ronda es
**documentación exclusivamente**, cero escrituras reales (ver §10.6).

### 10.3 BLOQUEO 1 (condicional) — el hueco de workflow EXISTE. Reporte, sin bypass

El comentario instruye: *«Si el diseño inmutable C2 actual no puede registrar la confirmación humana
de una propuesta ya congelada y preparada por el agente sin borrado/re-freeze, DETENTE y reporta el
hueco de workflow. No inventes un bypass.»*

**Se auditó el diseño leyendo el código y el esquema, no por suposición. El hueco existe.** Cuatro
hallazgos independientes, cada uno suficiente por sí solo:

1. **No hay dónde guardar la confirmación.** La tabla
   (`database/migrations/2026_09_29_193000_create_taxonomy_reviewed_proposals_table.php`) tiene un
   **único** par de identidad de revisión — `reviewer_id` + `reviewed_at` — y un único par de
   identidad de ejecución — `authorization_reference` / `target_environment` / `applied_at`. **No
   existe ninguna columna de segundo actor** (`confirmed_by`, `confirmed_at`,
   `confirmation_reference`). La separación revisión/ejecución que el diseño sí modela a propósito no
   incluye un tercer evento de *confirmación*.
2. **No hay estado que lo represente.** `TaxonomyReviewedProposal` declara exactamente tres estados:
   `PENDING_APPLY`, `APPLIED`, `ABORTED`. No hay `CONFIRMED` ni equivalente.
3. **No hay método que lo escriba.** `ReviewedProposalService` expone exactamente dos escritores
   públicos: `freeze()` y `apply()`. `abort()` es privado y **todos** sus 13 puntos de invocación
   están dentro de la ruta de `apply()` y exigen `authorizationReference` + `targetEnvironment` — es
   decir, **`ABORTED` es inalcanzable sin invocar APPLY**, que no está autorizado.
4. **El re-freeze está bloqueado por la base de datos, no solo por la política.** El índice único
   parcial `taxonomy_reviewed_proposals_one_pending_per_candidate` sobre `(candidate_link_id) WHERE
   status = 'PENDING_APPLY'` impide insertar una segunda propuesta activa para el candidato 266
   mientras #492 siga en `PENDING_APPLY`. Que el humano «vuelva a congelar» su propia decisión exige
   **primero** borrar o abortar #492 — las dos cosas prohibidas sin autorización separada.

Y además, editar a mano `decision_payload` para anexar una nota de confirmación **rompería
`payload_fingerprint`**, que existe precisamente para demostrar que nadie editó los campos de decisión
después de congelados. Eso sería el bypass que el comentario prohíbe.

**Conclusión: hoy no hay forma de registrar la confirmación humana de #492–#495 dentro de C2.** Las
opciones se reportan, **ninguna se implementa en esta ronda** (ninguna fue autorizada):

| Opción | Qué implica | Lectura |
|---|---|---|
| **A — extensión aditiva de C2** (recomendada) | Columnas nuevas nulables (`prepared_by_actor_type`, `confirmed_by_id`, `confirmed_at`, `confirmation_reference`) + un método `confirm()` que escriba **solo** esos campos, nunca los de decisión — así `payload_fingerprint` sigue válido — y que `apply()` exija confirmación no nula cuando la propuesta esté marcada como preparada por agente | No destructiva, preserva los 4 registros y la inmutabilidad de la decisión, y convierte la confirmación en **compuerta exigible**, no solo en nota. Es trabajo de diseño + migración + tests: **requiere su propia tarea con compuerta propia** |
| **B — limpieza autorizada + re-freeze humano** | Autorización explícita y separada para abortar o borrar #492–#495, y que el humano congele cada decisión por la UI | Deja el rastro limpio y sin contradicción, pero **destruye los 4 registros actuales** y necesita exactamente la autorización que el orquestador retuvo. Hoy `ABORTED` solo se alcanza vía `apply()`, así que incluso esto exigiría tocar la ruta de APPLY o borrar filas |
| **C — confirmación fuera de C2, solo en la bitácora** | Una entrada en `taxonomy_audit_log` (append-only) con `entity_type='taxonomy_reviewed_proposals'`, `entity_id=492…495`, `user_id=<humano>`, `actor_type='user'`, sin mutar la propuesta | Técnicamente posible y no destructivo, **pero `apply()` no lee la bitácora**: sería un registro, no una compuerta. Y sigue siendo una **escritura real**, que este comentario prohíbe explícitamente. No ejecutada |
| **D — dejarlo solo en Issue #2 y el handoff** | Documentar la confirmación sin tocar la base | El registro durable en base sigue diciendo `reviewer_id=3` con la nota contradictoria — **es justo lo que el orquestador rechazó** como base de auditoría |

**No se eligió ni se ejecutó ninguna.** La opción A es la única que cierra el problema sin destruir
registros, y es una decisión de diseño con compuerta propia, no algo que el agente deba elegir.

### 10.4 BLOQUEO 2 — 270/271 requieren decisión humana de diseño

El orquestador **confirma el análisis de §4 como correcto** y confirma las dos prohibiciones: no crear
conceptos duplicados y **no usar un APPLY como atajo de secuenciación implícito**. La decisión humana
requerida antes de cerrar TASK-0006 es una de estas dos:

- **A.** Extender `CREATE_NEW` en C2 para congelar nombres canónicos **ES + EN explícitos** y
  soportar un flujo revisado de identidad bilingüe; o
- **B.** Definir otra secuencia gobernada para crear un concepto y después mapear el alias de
  traducción.

Es decisión de diseño; el agente no la elige. Ambos candidatos siguen `pending` con 0 propuestas.

### 10.5 BLOQUEO 3 — relaciones 61/62 requieren política humana de curación

El orquestador confirma que **dejarlas sin decidir fue correcto** y coincide con §5: la evidencia es
demasiado débil/ambigua para inferir una decisión de publicación solo por similitud léxica. Requiere
decisión humana explícita sobre `#61 production → production casing` y `#62 oil → oil-base mud`.
Hasta que exista una regla de curación explícita o una decisión caso por caso, **ambas quedan
`candidate` y sin congelar**.

### 10.6 Estado gobernado actual (según el re-audit) y qué hizo esta ronda

| Ítem | Valor |
|---|---|
| Filas fuente candidatas | 10 |
| Relaciones candidatas | 2 |
| `taxonomy_term_concepts` (publicado) | 142 |
| `taxonomy_canonical_concepts` | 81 |
| `taxonomy_term_cpv_relations` | 9749 |
| Propuestas congeladas | 8 |
| Propuestas aplicadas | **0** |
| #420 / #421 / #422 / #491 | revisadas por humano — **protegidas** |
| #492 / #493 / #494 / #495 | preparadas por el agente — **`HUMAN_CONFIRMATION_REQUIRED`** |
| Candidatos 270 / 271 | **sin decidir** |
| Relaciones 61 / 62 | **sin decidir** |

**Esta ronda de corrección no hizo ninguna escritura real**, cumpliendo la instrucción expresa del
comentario (*«DO NOT perform additional real writes from this comment»*) y la del operador: cero
APPLY, cero publicación, y **#492–#495, 270, 271, 61 y 62 no fueron tocados** — ni borrados, ni
mutados, ni re-congelados. Lo único que cambió son archivos de documentación (esta sección,
`current_task.md`, el handoff y el archivo verbatim de la tarea) y la auditoría del diseño C2 descrita
en §10.3, que es **solo lectura** de esquema y código.

### 10.7 Lo que falta para cerrar TASK-0006

Tres decisiones humanas, ninguna de las cuales el agente debe tomar:

1. **266–269:** confirmar, rechazar o revisar cada una de las 4 recomendaciones, **y** decidir cómo
   registrar esa confirmación dado el hueco de §10.3 (opción A, B, C o D).
2. **270/271:** la identidad bilingüe (nombres canónicos ES/EN) y la secuencia gobernada — opción A o
   B de §10.4.
3. **61/62:** la política de curación, o una decisión caso por caso.

TASK-0007 (APPLY) sigue sin abrir y APPLY/publicación sigue **NO AUTORIZADO**.
