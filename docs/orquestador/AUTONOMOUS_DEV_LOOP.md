# Protocolo de loop autónomo — Orquestador ↔ Agente de desarrollo

**Versión:** 1.0  
**Fecha:** 2026-10-09  
**Objetivo:** reducir al mínimo la intervención del propietario durante desarrollo, pruebas y correcciones, manteniendo control humano sólo en decisiones críticas o cambios de alcance/fase.

---

## 1. Principio operativo

Una vez que el propietario autoriza una **tarea o fase** y el orquestador publica su contrato en Issue #2,
esa autorización crea un **sobre de trabajo autorizado** (*authorization envelope*).

Dentro de ese sobre, el orquestador y el agente de desarrollo deben iterar entre sí de forma autónoma:

**IMPLEMENTAR → PROBAR → PUSH → REVISAR → CORREGIR → REPROBAR → PUSH → REVISAR → PASS**

El propietario **no** debe actuar como mensajero entre ambos durante ese ciclo.

Issue #2 es el bus de coordinación técnico. El repositorio y los artefactos de auditoría son la fuente
de evidencia.

---

## 2. Qué se autoriza implícitamente dentro de una tarea ya aprobada

Mientras el trabajo se mantenga dentro del contrato de la tarea/fase, el agente puede, sin volver a
pedir autorización al propietario:

- implementar el código especificado;
- crear/modificar tests;
- corregir bugs detectados durante la revisión;
- refactorizar lo estrictamente necesario para cumplir los criterios de aceptación;
- actualizar documentación y artefactos de auditoría;
- ejecutar tests unitarios, integración, regresión y smoke tests;
- repetir pruebas cuantas veces sea necesario;
- realizar commits y push a la rama autorizada;
- corregir hallazgos del orquestador;
- actualizar `current_task.md` y `orchestrator_handoff.json`;
- usar staging para pruebas **cuando el contrato de la tarea ya lo contemple**;
- realizar deploys a staging **cuando el contrato de la tarea los haya autorizado explícitamente**;
- hacer escrituras no destructivas en staging/test **si estaban previstas explícitamente en el contrato**.

Un fallo de test, bug, regresión, inconsistencia documental o corrección técnica normal **NO es un gate
del propietario**. Se corrige dentro del loop.

---

## 3. Gates que SÍ requieren autorización del propietario

El loop debe detenerse y solicitar autorización sólo cuando aparezca uno de estos casos:

### GATE A — Nueva tarea, fase o ampliación material de alcance
- iniciar TASK nueva;
- pasar a una fase distinta del roadmap;
- añadir objetivos que no estaban en el contrato;
- cambiar criterios de producto o negocio.

### GATE B — Producción / main / tráfico real
- merge a `main`;
- deploy a producción;
- habilitar tráfico real o comportamiento público nuevo;
- cambiar infraestructura productiva fuera del sobre autorizado.

### GATE C — Datos destructivos o publicación masiva
- borrados;
- migraciones destructivas;
- rollback con pérdida;
- reescrituras masivas;
- aprobación/publicación masiva de taxonomía;
- otra mutación de datos cuyo impacto exceda el contrato aprobado.

### GATE D — Credenciales, secretos y privilegios
- rotar o crear secretos;
- cambiar credenciales;
- elevar permisos;
- crear accesos privilegiados;
- conceder al pasante capacidad operativa de publicación.

### GATE E — Decisión humana de negocio/dominio
- dos soluciones técnicamente válidas con impacto de producto distinto;
- clasificación taxonómica ambigua que necesita criterio CPV;
- aceptación de un trade-off de relevancia;
- cambio de UX/comportamiento que requiera criterio del cliente.

### GATE F — Coste, contrato, seguridad o acción externa irreversible
- compras/costes no autorizados;
- cambios contractuales;
- incidente de seguridad;
- acción irreversible en un sistema externo;
- cualquier riesgo material no contemplado.

Ante un gate, el agente/orquestador debe registrar:
1. qué se encontró;
2. por qué excede el sobre autorizado;
3. opciones;
4. recomendación;
5. impacto de no decidir.

Y detener **sólo** la parte bloqueada. Si hay trabajo independiente seguro dentro del mismo contrato,
puede continuar.

---

## 4. Bus de comunicación: Issue #2

Durante una tarea activa, el usuario deja de ser el transportista de mensajes.

### Agente de desarrollo

Después de cada ronda sustantiva:
1. commit + push a la rama autorizada;
2. actualizar `audit/orchestrator_handoff.json`;
3. dejar working tree limpio;
4. publicar o exponer el estado de revisión:
   - `READY_FOR_REVIEW`
   - `Issue #2`
   - `HEAD <sha>`
5. leer el último comentario del orquestador en Issue #2;
6. si el veredicto es `CORRECTIONS_REQUIRED`, aplicar las correcciones directamente, sin pedir al
   propietario que retransmita el comentario;
7. repetir hasta `PASS`, un gate de propietario o un bloqueo externo real.

Si el agente no puede escribir comentarios en Issue #2, el fallback es:
- commit/push;
- actualizar `audit/orchestrator_handoff.json` con `READY_FOR_REVIEW`, HEAD y resumen;
- el orquestador detecta el nuevo HEAD desde GitHub.

### Orquestador

Cuando detecta un nuevo HEAD de revisión:
1. verificar que pertenece a la rama/tarea autorizada;
2. comparar contra el último HEAD aceptado;
3. leer código/tests/docs **reales**, no sólo el resumen del agente;
4. verificar pruebas, Actions y estado vivo cuando corresponda;
5. publicar la auditoría completa en Issue #2;
6. clasificar:
   - `PASS`
   - `CORRECTIONS_REQUIRED`
   - `BLOCKED_EXTERNAL`
   - `OWNER_GATE_REQUIRED`
7. si es `CORRECTIONS_REQUIRED`, el comentario debe ser ejecutable por el agente sin intervención del
   propietario;
8. no pedir autorización para correcciones ordinarias ya cubiertas por el contrato.

---

## 5. Regla de continuidad de gates

El orquestador nunca reinicia gates silenciosamente.

Para cada ronda debe clasificar la evidencia anterior como:
- **INHERITED / VALID**
- **NEWLY EXECUTED**
- **INVALIDATED / MUST RERUN**
- **NOT APPLICABLE**

Un cambio documental no invalida tests de runtime.
Un cambio de runtime sí puede invalidar tests relevantes.
Una mutación de datos puede invalidar regresiones aunque el código no cambie.

---

## 6. Cuándo se informa al propietario

El propietario recibe mensajes sólo cuando:

1. hace falta su autorización por un gate A–F;
2. una tarea/fase llega a `PASS / CLOSED`;
3. existe un bloqueo externo que sólo él puede resolver;
4. hace falta una decisión de negocio;
5. se propone abrir la siguiente tarea/fase.

No se le debe pedir que copie comentarios técnicos entre ChatGPT y el agente.

---

## 7. Formato de mensajes

### Dev → revisión

```
READY_FOR_REVIEW
Issue #2
HEAD <exact-sha>
```

### Orquestador → dev, correcciones

Comentario en Issue #2:

```
[ORCHESTRATOR RE-AUDIT — TASK-XXXX — CORRECTIONS_REQUIRED]
...
```

Debe incluir sólo hallazgos verificables, correcciones exigidas, aceptación preservada y límites de
alcance.

### Gate de propietario

```
OWNER_GATE_REQUIRED
TASK-XXXX
GATE <A-F>
DECISION: <qué autorización/decisión hace falta>
RECOMMENDATION: <opción recomendada>
IMPACT: <qué queda bloqueado>
```

### Cierre

```
[ORCHESTRATOR FINAL RE-AUDIT — TASK-XXXX — PASS / CLOSED]
```

---

## 8. Regla de no expansión silenciosa

El loop autónomo no significa autonomía ilimitada.

El agente y el orquestador pueden elegir **cómo** corregir técnicamente dentro del alcance aprobado,
pero no pueden cambiar **qué** producto se está construyendo, qué datos se publican, qué permisos se
otorgan o qué entorno productivo se altera sin el gate correspondiente.

---

## 9. Reanudación de una sesión

Al retomar:
1. `docs/orquestador/SESSION_RESUME.md`
2. `docs/orquestador/AUTONOMOUS_DEV_LOOP.md` (este documento)
3. encabezado de `docs/orquestador/current_task.md`
4. campos `current_*` de `audit/orchestrator_handoff.json`
5. último comentario del orquestador en Issue #2
6. HEAD remoto

Si hay una tarea activa y autorizada, el agente entra directamente al loop. No pide una nueva
autorización sólo porque cambió la sesión o el dispositivo.

---

## 10. Regla final

**Autorización al inicio de la tarea/fase + gates críticos explícitos. Todo lo demás fluye entre
orquestador y agente hasta PASS.**


---

## 11. Polling de revisión cuando el agente no puede comentar

Si el agente de desarrollo no tiene credencial para escribir en Issue #2, el loop sigue siendo autónomo
usando dos superficies:

1. **Señal de salida del agente:** `audit/orchestrator_handoff.json` + nuevo HEAD remoto.
2. **Señal de vuelta del orquestador:** comentario en Issue #2 que cite la TASK y el HEAD revisado.

### Después de cada push

El agente debe:
1. actualizar el handoff con:
   - `review_state = "READY_FOR_REVIEW"`
   - `review_head = "<exact-sha>"`
   - `review_task = "TASK-XXXX"`
   - `review_issue = 2`
   - `review_requested_at = "<UTC ISO-8601>"`
2. hacer commit + push;
3. confirmar que `origin/feature/upgrade-filament-v3` apunta al HEAD esperado;
4. consultar Issue #2 periódicamente hasta encontrar una revisión del orquestador para ese HEAD.

### Cadencia recomendada de polling

Para no depender del propietario ni saturar GitHub:
- cada **2 minutos** durante los primeros **20 minutos**;
- luego cada **5 minutos** hasta completar **60 minutos**;
- después, si todavía no existe revisión, dejar:
  `review_state = "WAITING_ORCHESTRATOR_REVIEW"`
  y detener el proceso activo sin pedir intervención al propietario.

La espera de una revisión NO es un `OWNER_GATE_REQUIRED`.

El monitor automático del orquestador actúa como respaldo y revisa GitHub periódicamente. Cuando el
agente se reactive, su primer paso es consultar Issue #2 antes de pedir instrucciones.

### Cómo reconocer la revisión correcta

No basta con "el último comentario". El agente debe aceptar una revisión sólo si:
- el comentario es posterior a `review_requested_at`;
- menciona la TASK activa;
- menciona o corresponde inequívocamente a `review_head`;
- el veredicto es uno de:
  - `PASS / CLOSED`
  - `CORRECTIONS_REQUIRED`
  - `BLOCKED_EXTERNAL`
  - `OWNER_GATE_REQUIRED`.

Si aparece `CORRECTIONS_REQUIRED`, el agente entra directamente en la siguiente ronda.

---

## 12. Campos de handoff para el loop autónomo

Durante una TASK activa, `audit/orchestrator_handoff.json` debe mantener como mínimo:

```json
{
  "review_state": "IN_PROGRESS | READY_FOR_REVIEW | WAITING_ORCHESTRATOR_REVIEW | CORRECTIONS_REQUIRED | PASS_CLOSED | OWNER_GATE_REQUIRED | BLOCKED_EXTERNAL",
  "review_task": "TASK-XXXX",
  "review_head": "<sha>",
  "review_issue": 2,
  "review_requested_at": "<UTC ISO-8601>",
  "last_orchestrator_comment_id": "<id-or-null>",
  "last_orchestrator_verdict": "<verdict-or-null>"
}
```

Estos campos son de coordinación, no sustituyen el estado funcional de la tarea.

