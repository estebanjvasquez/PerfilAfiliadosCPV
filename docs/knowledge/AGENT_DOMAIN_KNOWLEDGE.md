# Conocimiento de dominio — taxonomía CPV y buscador

**Autor:** agente de desarrollo. **Fecha:** 2026-10-09.
Marcas de confianza según `README.md` §4.

Este archivo contiene el conocimiento **de negocio y de criterio** que no está en el código ni en la
referencia técnica, y que es el que más fácilmente se pierde al cambiar de sesión.

---

## 1. Qué problema resuelve la taxonomía

Una empresa afiliada describe lo que hace con el vocabulario del sector petrolero venezolano. Un
comprador busca con el suyo, o con el de un pliego internacional. La taxonomía es el puente, y su
destino son las **categorías CPV** normalizadas.

Sin ese puente, el buscador sólo encuentra coincidencias literales, y en este dominio la coincidencia
literal falla casi siempre: quien ofrece *cabria* no escribe *torre de perforación*, y quien busca
*drilling rig* no escribe ninguna de las dos.

Jerarquía de la taxonomía, con el vocabulario exacto que ve el usuario en pantalla
**[LEÍDO-EN-CÓDIGO]**: **Grupo → Familia → Categoría**, que en las planillas de revisión corresponden a
`Grupo_CPV` / `Familia_CPV` / `Categoria_CPV`.

---

## 2. Terminología venezolana que importa

Casos reales usados como patrón de calibración del pasante **[DOCUMENTADO]** — contratos de TASK-0008,
comentarios `6035726459` y `6035827197`:

| Término local | Equivalente | Nota |
|---|---|---|
| **cabria** | derrick / torre de perforación | |
| **mechurrio** | flare / quemador | |
| **guaya fina** | slickline | distinto de *wireline* genérico |
| **macolla** | well pad / localización múltiple | |
| **tratamiento de aguas de perforación** | drilling water treatment | **capacidad compuesta**: no debe reducirse a un término genérico de "agua" |

El último caso es el patrón importante: una capacidad compuesta pierde su significado si se mapea al
componente más genérico. *Tratamiento de aguas de perforación* no es *tratamiento de aguas*.

---

## 3. Términos genéricos: precedente ya resuelto por la Cámara

**[DOCUMENTADO]** En TASK-0006E/TASK-0007 los revisores humanos de la propia Cámara resolvieron estos
términos como **CONTEXT_REQUIRED**, no como mapeo directo:

`petroleum`, `oil and gas`, `crude oil`, `exploration`, `upstream`, `midstream`, `downstream`.

**Criterio que esto establece:** un término que nombra *el sector entero* no identifica una capacidad.
Mapearlo a una categoría CPV concreta genera falsos positivos masivos, porque casi toda empresa
afiliada "es" petróleo y gas. La decisión correcta es pedir contexto, no elegir una categoría.

Las propuestas #1688 (`petroleum`), #1689 (`crude oil`) y #1690 (`oil and gas`) fueron creadas por el
propietario personalmente a través de Filament, todas CONTEXT_REQUIRED **[DOCUMENTADO]**.

---

## 4. El estándar de evidencia, con el caso concreto

**[MEDIDO]** El ejemplo canónico, elegido porque el pasante lo encontrará:

> `refinery` / `refinería` propone **CPV-37.06.16S** con peso **0.7455**, y su único respaldo es
> *"Similitud semántica (distancia coseno 0.254)"*. Sigue **sin aprobar, a propósito**.

**Qué enseña:** un peso alto **no es evidencia**. 0.7455 parece convincente y no dice nada sobre si la
empresa realmente presta ese servicio. La similitud semántica indica *parecido de texto*, no
*capacidad verificada*.

**Criterio operativo:** la evidencia válida es la descripción real de la empresa, su documentación o su
sitio web. El peso del motor es una sugerencia para ordenar la cola de revisión, nunca una
justificación para aprobar.

---

## 5. El vocabulario de cinco decisiones, y por qué sólo dos tienen botón

**[LEÍDO-EN-CÓDIGO]** `app/Filament/Resources/Concerns/ManagesTaxonomyRelationReview.php` expone
exactamente dos acciones de decisión:

- **`Aprobar`** — requiere permiso `taxonomy_publish`. **Publica la relación a la búsqueda en vivo.**
- **`Rechazar`** — requiere permiso `taxonomy_edit_relations`.

Ambas exigen confirmación y un **`Motivo` obligatorio** que se escribe en `taxonomy_audit_log`.
Existen además acciones masivas `Aprobar seleccionadas` / `Rechazar seleccionadas`.

El vocabulario de revisión tiene **cinco** decisiones. Las tres restantes —`NEEDS_CONTEXT`,
`ESCALATE`, `POSSIBLE_NEW_CATEGORY`— **no tienen botón**: se registran sólo en la planilla y la fila se
deja intacta en *En revisión*.

**Dos trampas reales, no teóricas:**

1. Usar la acción **`Editar`** para cambiar el estado a mano **evita** la confirmación y el motivo
   obligatorio, y deja la auditoría incompleta. Nunca se usa para decidir.
2. Las acciones **masivas existen de verdad**, y con 9.282 filas pendientes la tentación también es
   real. Están prohibidas en calibración, y la aprobación masiva es GATE C siempre.

Estados que se ven en pantalla **[LEÍDO-EN-CÓDIGO]**: *Candidata (no urgente)*, *En revisión*,
*Aprobada*, *Rechazada*, *Descartada*. Tipos de relación: *Exacto*, *Sinónimo explícito*, *Léxico
fuerte*, *Léxico*, *Contextual*, *Ancestro*, *Manual*. El filtro por defecto es *En revisión* ordenado
por peso descendente, y existe un filtro *Código sin categoría (huérfano)* y un campo *Preview de
impacto* que indica a cuántas empresas afectaría la decisión.

---

## 6. Por qué publicar mappings no mejoró la búsqueda

Este es el hallazgo de dominio más contraintuitivo del proyecto, y conviene entenderlo antes de
"arreglar" nada.

**[LEÍDO-EN-CÓDIGO]** En `canonical-expansion.ts`, la rama de herencia por concepto exige
`taxonomy_term_cpv_relations.status = 'approved'` en un término **hermano** del mismo concepto canónico.

**[MEDIDO]** Consecuencia tras el APPLY real de TASK-0007:
- el concepto #2890 contiene **un solo** término (#24), así que la herencia entre hermanos no tiene de
  quién heredar;
- los términos #22/#23 del concepto #4819 **sí** son hermanos, pero sus únicas filas CPV están en
  `candidate`, y la rama exige `approved`.

Medido dos veces de forma independiente: por SQL contra Supabase, y por HTTP contra el Worker. Las
consultas `pipeline`, `refinery` y `refinería` devuelven `canonical_concepts: []` y `cpv_relations: []`,
y aun así devuelven 20 / 27 / 17 candidatos vía `LITERAL_MATCH` y `SEMANTIC_INFERENCE`.

**Lectura correcta:** la búsqueda funciona; la capa de taxonomía simplemente **aún no aporta** para
esos términos, porque nadie autorizó aprobar sus relaciones CPV. **No es un bug.** Convertirlo en
"resultado" aprobando relaciones en masa es exactamente lo que GATE C prohíbe.

---

## 7. La cola de revisión, en escala

**[MEDIDO]** Estado aceptado al 2026-10-08:

| Estado TERM→CPV | Filas |
|---|---|
| `approved` | 212 |
| `candidate` | 17 |
| `needs_review` | **9.282** |
| `deprecated` | 238 |
| **total** | **9.749** |

Los 9.282 `needs_review` son el trabajo humano pendiente, y la razón de ser de la revisión por lotes
del pasante. A 50–100 por lote, es un esfuerzo sostenido de meses, no una tarea.

**Implicación de criterio:** cualquier propuesta de "resolver la taxonomía" automáticamente choca con
que el valor del sistema está precisamente en que esas 9.282 decisiones tengan criterio humano
trazable. El auto-mapeo (`auto_mapper_v1`) ya pobló la cola; lo que falta no es más automatización.

---

## 8. Calibración del pasante: estrictamente sin publicar

**[DOCUMENTADO]** Diseño aceptado en TASK-0008 (cierre `6041102746`), tras una corrección de gobierno
en `6036293989`:

El primer lote de 30–50 relaciones es **REVIEW-ONLY**. El pasante **no hace clic en ningún botón que
cambie el estado** de una relación: ni *Aprobar*, ni *Rechazar*, ni *Editar*, ni *Eliminar*, ni ninguna
acción masiva. Las cinco decisiones se registran **sólo en la planilla**.

La guía conserva la correspondencia con la pantalla real en una subsección aparte, claramente rotulada
*"para cuando se habilite"*, de modo que el mapeo no se pierde pero tampoco se ejecuta.

**Cómo se evalúa el lote:** comparar `Decision` del pasante contra `Decision_Final` del supervisor,
**excluyendo del denominador los casos genuinamente ambiguos**, y calcular la tasa de coincidencia.
Referencia de calidad: **≥90% en casos no ambiguos**. Se busca específicamente **patrón de
sobre-aprobación**, y se registran términos ambiguos y categorías faltantes.

**[DOCUMENTADO]** El ≥90% es un **indicador de calidad, no un umbral de publicación automática**. La
redacción fue corregida explícitamente para que no se leyera así. TASK-0008 **no concede** permiso
operativo de publicación al pasante; eso requiere una tarea separada y autorización del propietario.

---

## 9. La UAT del cliente no es un buscador

**[MEDIDO]** `public/cira-test/index.html` es un **chat** —"CIRA - Asistente CPV"— que postea a un
webhook de n8n. Tiene botones de prompt rápido, un textarea con placeholder *"Escribe una consulta de
prueba..."* y un botón *"Nueva conversación"*. La URL responde 200.

Dos consecuencias que invalidarían una UAT entera si se ignoran:

1. **El chat arrastra contexto.** Sin reiniciar la conversación antes de cada consulta, la respuesta
   está contaminada por la anterior. La guía del cliente instruye reiniciar siempre.
2. **Si el flujo de n8n está detenido, todas las consultas fallan igual.** Una UAT completa parecería
   un fallo catastrófico del buscador cuando el buscador no se ejecutó nunca. **Verificar que el
   workflow esté activo es preflight obligatorio antes de enviar el enlace al cliente.**

**[MEDIDO]** El webhook **no** se invocó desde la tarea de preparación, para no disparar nada externo;
y una sonda GET habría sido inconclusa de todos modos, porque los webhooks de n8n son POST-only.

---

## 10. Qué no existe y conviene no inventar

- **[MEDIDO]** No hay en `docs/` ninguna expectativa de negocio aprobada sobre qué empresa debe salir
  en qué consulta (verificado por búsqueda). Por eso la planilla de UAT deja `Empresa_esperada` en
  blanco para que lo llene el cliente, en lugar de sembrar expectativas inventadas por el equipo
  técnico.
- No existe un conjunto de "respuestas correctas" contra el que medir relevancia. La línea base de
  regresión mide **no-cambio**, no **acierto**. Son cosas distintas: la regresión protege contra
  regresiones, no demuestra calidad.
- **[DESCONOCIDO]** No hay métrica de calidad de búsqueda acordada con el cliente. Ver
  `AGENT_OPEN_QUESTIONS.md`.
