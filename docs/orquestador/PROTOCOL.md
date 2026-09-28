# PROTOCOL — Canal de tareas Claude Code ↔ Orquestador (GitHub)

**Efectivo desde:** 2026-09-28
**Reemplaza:** la conversación de Claude Code como fuente de tareas. GitHub es la fuente de verdad.

---

## 0. Por qué existe este archivo

El orquestador (ChatGPT) actualmente tiene acceso **READ** a GitHub, pero su integración
recibe `HTTP 403` al intentar escribir archivos. Hasta que ese permiso se resuelva,
**Claude Code es el mecanismo de materialización** de las tareas que Esteban entregue de
parte del orquestador: Esteban pega el prompt del orquestador, Claude Code lo escribe como
un archivo de tarea en este repo, lo ejecuta, y deja el resultado también en GitHub.

## 1. Orden de lectura obligatorio al iniciar cada ciclo

Antes de actuar sobre cualquier tarea nueva, leer en este orden:

1. `docs/orquestador/PROTOCOL.md` (este archivo)
2. `docs/orquestador/current_task.md`
3. **Únicamente** la tarea activa referenciada ahí (`docs/orquestador/tasks/000N-*.md`) y los
   archivos que esa tarea referencie explícitamente.

**No depender del historial de conversación para reconstruir una tarea.** Si una tarea no
está materializada como archivo en `docs/orquestador/tasks/`, no se considera asignada,
sin importar lo que se haya dicho en el chat.

## 2. Identificadores

Las tareas se numeran `TASK-0002`, `TASK-0003`, `TASK-0004`, ... (consecutivo, nunca se
reutiliza un número). El archivo correspondiente vive en
`docs/orquestador/tasks/000N-slug-descriptivo.md`.

## 3. Reglas de colaboración (vigentes, heredadas del Collaboration Protocol acordado)

- **GitHub es la fuente de verdad.** El estado real del trabajo es lo que está commiteado
  y pusheado, no lo que se describe en una conversación.
- **Source-first diagnosis.** Antes de reportar un hallazgo (bug, causa raíz, resultado de
  una consulta), verificarlo contra la fuente real (código desplegado, base de datos,
  logs) — no contra una suposición o un dato de segunda mano. Esta sesión ya cometió el
  error de reportar una conclusión sin verificar dos veces; ambas veces se autocorrigió
  antes de que el usuario lo notara. La regla existe para no repetirlo.
- **Rama de trabajo:** `feature/upgrade-filament-v3`. Todo el desarrollo ocurre ahí.
- **Nunca merge a `main` sin autorización explícita** del usuario.
- **Nunca force-push** a una rama compartida sin autorización explícita.
- **No secretos en el repo.** GitHub no es un transporte de credenciales. Nunca commitear
  tokens, contraseñas, ni credenciales de ningún tipo — ni siquiera en archivos de
  auditoría o handoff. Sanitizar cualquier valor sensible antes de reportarlo.
- **No publicación automática de taxonomía.** El pipeline de conceptos canónicos
  (`--apply`) solo puede encolar candidatos en las tablas de revisión
  (`taxonomy_candidate_concept_links` status=pending, `taxonomy_concept_relations`
  status=candidate). Nunca escribe directamente en `taxonomy_term_concepts` ni en
  `taxonomy_canonical_concepts`. Solo la aprobación humana en Filament mueve un candidato
  a publicado.
- **No operaciones destructivas de base de datos** (DELETE masivo, rebuild-all) sin
  autorización explícita y sin contar filas antes/después.
- **No modificar el workflow de n8n** sin autorización explícita — es infraestructura
  compartida con tráfico real de producción.
- **Gates de autorización humana:** merge a main, deploy, edición de n8n, operaciones
  masivas de taxonomía, publicación de candidatos, operaciones destructivas de DB, rotación
  de credenciales. Ninguna de estas ocurre sin pedirlo explícitamente.

## 4. Máquina de estados de una tarea

```
ASSIGNED → IN_PROGRESS → READY_FOR_REVIEW → APPROVED
                              ↓
                       CORRECTIONS_REQUIRED → IN_PROGRESS → ...
```

- **ASSIGNED**: la tarea fue materializada como archivo en `docs/orquestador/tasks/`, pero
  su ejecución no ha empezado.
- **IN_PROGRESS**: Claude Code está trabajando en ella.
- **READY_FOR_REVIEW**: el trabajo fue commiteado, pusheado, testeado, y el handoff
  (`audit/orchestrator_handoff.json`) fue actualizado. **STOP** — no seguir con la próxima
  tarea sin instrucción explícita.
- **APPROVED**: el orquestador o el usuario aprobó el resultado.
- **CORRECTIONS_REQUIRED**: el orquestador o el usuario pidió cambios; vuelve a
  `IN_PROGRESS`.

## 5. Regla de STOP

**Después de alcanzar `READY_FOR_REVIEW`, Claude Code se detiene.** No empieza la
siguiente tarea, no mergea a main, no publica taxonomía, no realiza ninguna acción
adicional, hasta recibir una instrucción explícita del usuario o del orquestador. La
respuesta final al usuario en ese punto es mínima:

```
READY_FOR_REVIEW
Issue #<n>
HEAD <sha>
```

## 6. Formato del handoff (`audit/orchestrator_handoff.json`)

El handoff distingue explícitamente tres SHAs distintos (no colapsarlos en uno):

- `review_base_sha` — el commit desde el cual se partió para esta tarea (el estado que el
  orquestador ya revisó o conoce).
- `review_head_sha` — el commit que contiene el trabajo de la tarea en sí (código, tests),
  antes de tocar el propio archivo de handoff.
- `handoff_commit_sha` — el commit que actualiza `audit/orchestrator_handoff.json` con el
  resultado de la tarea (normalmente uno más que `review_head_sha`).

Cada tarea completada agrega o actualiza un bloque en el handoff con estos tres valores,
más el `task_id` (`TASK-000N`), tests corridos, y cualquier mutación de base de datos con
conteos antes/después.

## 7. Canal de Issue en GitHub

Issue #2 (`CPV - Development Orchestration & Review`) es el canal de coordinación humano-
legible.

**Corrección (2026-09-28, tras TASK-0002):** la premisa original de esta sección — que el
orquestador solo tiene lectura y no puede escribir en GitHub — quedó obsoleta. El orquestador
comentó exitosamente en el Issue #2 (comentarios `5872689869` y `5874313894`), y esos
comentarios son ahora **el canal de revisión autoritativo en vivo**: es ahí donde llegan los
veredictos (`APPROVED`/`CORRECTIONS_REQUIRED`) por tarea. **Al iniciar cada ciclo, además de
leer `current_task.md`, hay que revisar si hay comentarios nuevos en el Issue #2** — no alcanza
con mirar solo `audit/orchestrator_handoff.json`.

El PAT de **Claude Code** (esta sesión) sigue con el mismo scope asimétrico: puede crear issues
pero sigue dando `403` al intentar comentar en ellos. Por eso Claude Code sigue sin poder
escribir en el Issue directamente, y `audit/orchestrator_handoff.json` sigue siendo el
checkpoint versionado que Claude Code produce como respuesta — pero ya no es un sustituto de
leer los comentarios del Issue, es un complemento.

## 8. Qué NO asumir

- No asumir que una tarea existe porque se mencionó en el chat — debe estar materializada
  como archivo.
- No asumir el contenido de un prompt del orquestador que no fue pegado textualmente en
  esta sesión o no está commiteado en `docs/orquestador/tasks/`. Si falta, pedirlo antes de
  inventar contenido.
- No asumir que un SHA, conteo, o resultado de test es correcto sin haberlo verificado con
  un comando real en esta sesión.
