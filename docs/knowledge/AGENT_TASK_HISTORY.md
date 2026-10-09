# Historial de gobierno — TASK-0004 → TASK-0010

**Autor:** agente de desarrollo. **Fecha:** 2026-10-09.

Objetivo: que nadie tenga que releer 68 comentarios de Issue #2 para saber qué se decidió y por qué.
Todas las referencias son ids de comentario de **Issue #2** del repo principal.

---

## 1. Línea de tiempo

| TASK | Qué fue | Resultado | Cierre |
|---|---|---|---|
| TASK-0004 | contrato de revisión inmutable, primeras rondas | PASS | — |
| TASK-0005 | endurecimiento del `paths-ignore` del deploy | PASS | `5914793857` (sección G) |
| TASK-0006 / 0006B / 0006C | confirmación humana vía Filament | CLOSED / PASS | `5947549221` |
| TASK-0006D | `preflight()` sin efectos secundarios + fix de UX de `freezeReview` | CLOSED / PASS | `5954835892` |
| TASK-0006E | ciclo de vida SUPERSEDED + re-revisión humana de 3 términos genéricos | CLOSED / PASS | `5993828105` |
| TASK-0007 | **APPLY/PUBLISH atómico real de taxonomía en staging** | CLOSED / PASS | `6035642794` |
| TASK-0008 | paquete UAT del cliente + guía de revisión del pasante | CLOSED / PASS | `6041102746` |
| TASK-0009 | triage de la UAT del cliente | **no abierta** — espera el feedback del cliente | — |
| TASK-0010 | dos subtareas paralelas (A: solicitud de categoría, B: manual en línea) | **ACTIVE / autorizada, sin empezar** | — |

---

## 2. TASK-0007 — el APPLY real

La tarea más delicada del proyecto: publicó taxonomía sobre datos compartidos reales de forma
irreversible.

**Cadena de autorización** (importa el mecanismo, no sólo el resultado):

1. `6032759610` — se establece el **protocolo de procedencia de autorización**: el propietario autoriza
   en la conversación del orquestador; el orquestador publica un `ORCHESTRATOR AUTHORIZATION RECORD`
   declarando con veracidad que la autorización vino de allí; **ese id** es la referencia durable.
   El orquestador **no** publica un comentario como si lo hubiera escrito el propietario.
2. `6032819854` — el AUTHORIZATION RECORD efectivo. Cita entorno `staging`, el conjunto exacto
   `[491,492,493,494,495,629,630,631,632,1688,1689,1690]`, el manifest fingerprint
   `eb7d1467...86f3702`, la línea base `c236bc51...`, y permite `--force` para esa única ejecución no
   interactiva.
3. Una autorización tecleada por el propietario **en el chat del agente** se trató como **no
   suficiente**, y la ronda se detuvo hasta que existió ese id auditable. El agente **no** publicó el
   acto de gobierno por su cuenta.

**Por qué tanta ceremonia:** `--authorized-by` queda escrito de forma **permanente** en 12 filas y en
el log de auditoría. Una referencia a un mensaje de chat no es auditable por un tercero.

**Resultado [MEDIDO]:** `BATCH_APPLIED`, 12 propuestas en 11 unidades de ejecución, **una sola
transacción**, blocker `null`, `applied_at` 2026-10-07 07:09:25/26.

Estado publicado, campo por campo contra la proyección aprobada:
- candidatos 10 = 3 `published` + 7 `context_required` + 0 pendientes
- relaciones 2 = 2 `rejected`
- conceptos canónicos 81 → **82**; TERM→CONCEPT 142 → **145**; TERM→CPV **9.749 sin cambio**
- `PENDING_APPLY` 12 → 0; `APPLIED` 0 → 12; `SUPERSEDED` 3 y `ABORTED` 0 sin moverse
- concepto nuevo #4819 (`refinería`/`refinery`); TERM→CONCEPT #1191, #1192, #1193
- exactamente 12 filas de auditoría #5249–#5260, `actor_type=system`, `target_environment=staging`
- fingerprint post-APPLY `e1087a48...03db9b5b`, **distinto** del previo, como exige el contrato
- #420/#421/#422 siguen `SUPERSEDED` con `applied_at`, `authorization_reference` y
  `target_environment` en `NULL`

**Validación posterior [MEDIDO]:** regresión congelada 32/32, 288 campos, **0 diffs**; checks
end-to-end `pipeline` / `refinery` / `refinería` PASS. Corroboración independiente: un preflight de
lote sin manifiesto ahora devuelve `BATCH_EMPTY_REQUEST` porque la cola quedó vacía.

**El bloqueo de credencial que no se resolvió fabricando un verde:** la regresión necesitaba
`DEBUG_TOKEN`. Se reportó `BLOCKED_AUTH_TOKEN_UNAVAILABLE`, y después
`BLOCKED_EXISTING_DEBUG_TOKEN_NOT_ACCESSIBLE` tras enumerar y **medir** todas las fuentes plausibles
(variables de entorno, scratchpad, `.wrangler/`, CI del repo del Worker —que no tiene workflows—, el
repo Laravel, y la página consumidora): cero coincidencias en todas. Se desbloqueó sólo cuando el
propietario autorizó rotar **únicamente** `DEBUG_TOKEN` (`6033692919`) y ejecutó él mismo la rotación y
la corrida. **El agente no rotó ningún secreto en ningún momento.**

---

## 3. TASK-0008 — UAT y pasante

Dos entregas independientes, ejecutables en paralelo por personas distintas. Diez archivos en
`docs/uat/`, sólo documentación y plantillas, cero cambios de código.

**Lo que se verificó contra el sistema real en vez de suponerlo** — el contrato prohibía explícitamente
inventar una acción de UI inexistente, así que las guías se escribieron **después** de leer el código:
la pantalla de UAT es un chat con n8n y no un buscador; la cola de revisión tiene sólo dos botones de
decisión; las acciones masivas existen de verdad; los permisos reales son `taxonomy_publish` y
`taxonomy_edit_relations`; los conteos del contrato se corroboraron contra la base viva y coincidieron
exactamente.

**La corrección de gobierno** (`6036293989`, `6038426912`): la calibración pasó a ser estrictamente
**REVIEW-ONLY**, con un banner al inicio de la guía del pasante, una tabla donde las cinco decisiones
mapean a *"Nada. Anótelo en la planilla"*, y el mapeo real de la UI preservado en una subsección aparte
rotulada *"para cuando se habilite"*. Además se invirtió la verificación del resumen de lote: ahora
**afirma cero modificaciones**, con un check objetivo de `reviewed_at` / cambios de estado = 0.

**Un archivo fuera de los nueve enumerados:** `docs/uat/README.md`, como índice de orientación, porque
el paquete apunta a tres audiencias distintas y abrir el archivo equivocado es un modo de fallo real.
Se declaró como ampliación de alcance en lugar de ocultarse.

---

## 4. TASK-0010 — autorizada y sin empezar

**Autorización del propietario:** `6079956985`. **Kickoff:** `6080004466`.
Checkpoint de coordinación: `3d104e6bebb3f23bdae04ec291ee7ca8f99c3f46`.

| Subtarea | Rama | Contrato | Objetivo |
|---|---|---|---|
| **TASK-0010A** | `feature/task-0010a-category-request` | `6079967780` | si el afiliado no encuentra una Categoría CPV adecuada, registrar una **solicitud gobernada** para revisión de la Cámara, con justificación, contexto de empresa/usuario, persistencia y email a un responsable configurable. **No crea ni publica categorías automáticamente.** |
| **TASK-0010B** | `feature/task-0010b-online-manual` | `6079977242` | manual de usuario **autenticado** dentro de Filament, actualizado al sistema actual, con énfasis en la nueva taxonomía, selección de categorías, beneficios y el flujo de solicitud de revisión |

**Reglas de paralelización:** ambas ramas parten del mismo checkpoint; se desarrollan y revisan
independientemente; **ninguna rama de subtarea despliega por sí sola**; cuando ambas estén PASS se
integran en `feature/upgrade-filament-v3`, se corren tests de integración/regresión, y el build
integrado **puede** desplegarse a staging para verificación del propietario. Producción y `main` siguen
fuera de alcance.

**Dependencia de integración declarada:** TASK-0010B puede documentar ahora el comportamiento acordado
de TASK-0010A, pero la integración final debe reconciliar el manual con las **etiquetas de UI realmente
aceptadas** de TASK-0010A antes de desplegar a staging.

**Estado real al 2026-10-09:** existen los worktrees locales `C:\Proyectos\GitHub\wt-task-0010a` y
`wt-task-0010b`, pero **el trabajo no ha comenzado**, por decisión del propietario: primero el loop
autónomo debe quedar perfectamente funcional.

---

## 5. Cambios de protocolo durante el proyecto

| Comentario | Cambio |
|---|---|
| `6032759610` | procedencia de autorización: el chat no es el acto de gobierno |
| `6058416324` | checkpoint de sesión pausada; `SESSION_RESUME.md` como punto de reanudación maestro |
| `6058796680` | confirmación de pausa del proyecto esperando feedback del cliente |
| `6079730982` | **loop autónomo con gates del propietario**: se deja de usar al propietario como mensajero |
| `6079826097` | fallback por agente sin escritura en el Issue + política de polling |
| `6079956985` | autorización de TASK-0010, dos subtareas paralelas |

El protocolo vigente está en `docs/orquestador/AUTONOMOUS_DEV_LOOP.md`.

---

## 6. Dos cierres que el agente no vio en su momento

Vale registrarlo porque explica huecos en la memoria de sesión: TASK-0007 (`6035642794`) y TASK-0008
(`6041102746`) se cerraron formalmente **después** de que el agente entregara su ronda. El agente
reportó `READY_FOR_REVIEW` y supo del cierre más tarde, al leer `SESSION_RESUME.md`.

**Implicación para el loop:** el agente nunca debe asumir que su última ronda sigue abierta. El primer
paso al reanudar es leer el estado publicado, no continuar desde su propia memoria.
