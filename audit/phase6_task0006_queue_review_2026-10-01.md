# TASK-0006 — Revisión humana de la cola real (reanudada tras el cierre de TASK-0006A)

**Fuente:** Issue #2 comentario [`5933152293`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5933152293)
(TASK-0006A = PASS/CLOSED; autoriza reanudar TASK-0006 solo para la revisión/freeze de la cola
restante).

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
| Candidato 266 | `exploration` (en) | **CONGELADO** → `CONTEXT_REQUIRED`, propuesta #492 |
| Candidato 267 | `upstream` (en) | **CONGELADO** → `CONTEXT_REQUIRED`, propuesta #493 |
| Candidato 268 | `midstream` (en) | **CONGELADO** → `CONTEXT_REQUIRED`, propuesta #494 |
| Candidato 269 | `downstream` (en) | **CONGELADO** → `CONTEXT_REQUIRED`, propuesta #495 |
| Candidato 270 | `refinery` (en) | **SIN DECIDIR** — requiere aclaración (ver §4) |
| Candidato 271 | `refinería` (es) | **SIN DECIDIR** — requiere aclaración (ver §4) |
| Relación 61 | `production` → `production casing` | **SIN DECIDIR** — requiere política (ver §5) |
| Relación 62 | `oil` → `oil-base mud` | **SIN DECIDIR** — requiere política (ver §5) |

---

## 2. Atribución de las 4 decisiones congeladas (importante para auditoría)

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
