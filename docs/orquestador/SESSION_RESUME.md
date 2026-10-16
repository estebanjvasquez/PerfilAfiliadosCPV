# CPV — Punto de reanudación (único y corto)

**Checkpoint:** 2026-10-16 ~09:00Z, en la rama `docs/session-continuity`. Las versiones largas anteriores, completas y sin
cambios, están en [`history/SESSION_RESUME_hasta_2026-10-16.md`](history/SESSION_RESUME_hasta_2026-10-16.md). El
checkpoint largo de producto sigue en `68168129:docs/orquestador/SESSION_RESUME.md` (rama `feature/home-branding-layout`).

> **Prioridad:** las instrucciones del usuario y del comentario del Issue #2 asignado prevalecen sobre este archivo.
> Si algo de aquí contradice GitHub o el servidor, prevalece la evidencia viva: se registra la discrepancia y se sigue
> en forma acotada. Las filas con fecha son hechos observados entonces, **no** estado vivo.

## 0. Arranque (objetivo: ≤5 min y ≤~10 KB de documentación del proyecto; es un objetivo, no una garantía de tokens)

1. **Este archivo**, completo.
2. **Git:** `git status -sb`, `git branch --show-current`, `git rev-parse HEAD`. Sin cambiar de rama, sin `pull` y sin descartar cambios.
3. **El comentario del Issue #2 asignado, completo y una sola vez** (API anónima, ver [`ENVIRONMENT.md`](ENVIRONMENT.md) §3).
   - De lo que cite, se abren **solo** las referencias directas que la tarea necesita: una fuente, una especificación o una aceptación.
   - Cada referencia se abre una vez, y de ella se extraen solo los requisitos pertinentes.
   - **No** se recorren comentarios en cadena: no se siguen las referencias de una referencia ni se lee el hilo.
4. **Accesos:** por comando y solo si la tarea los necesita, con `. docs\orquestador\bootstrap_access.ps1 -Quiet; <cmd>`.
5. **Herramientas:** solo las que la tarea necesita, con `docs\orquestador\check_tools.ps1 -Name <tool> -Version`. Informa nombre, estado y versión, y no instala nada.

**No en el arranque:**
- onboarding recursivo ni una auditoría amplia del código;
- leer `history/**`, `audit/**`, informes o el hilo del Issue #2;
- `audit/orchestrator_handoff.json` (515 KB): solo campos concretos, si hacen falta;
- suites de tests, volcados de logs, escaneo del disco o de transcripciones;
- loops, agentes o sondeos. El protocolo autónomo está **suspendido** desde el 2026-10-14 y es referencia histórica.

**Leer código sí:** abrir de forma dirigida los archivos que la tarea necesita para implementar es trabajo normal, no onboarding.

Manifiesto del arranque, con bytes: [`STARTUP_DRY_RUN.json`](STARTUP_DRY_RUN.json).

## 1. Arranque canónico y distribución

- **Rama canónica:** `docs/session-continuity`. Su punta tiene este checkpoint; el SHA está en la entrega final de CPV-SESSION-CONTINUITY en el Issue #2.
  - `CLAUDE.md`, `AGENTS.md`, `ENVIRONMENT.md`, `check_tools.ps1` y este archivo corto existen **solo en esta rama**.
  - Una sesión abierta en otra rama o en un checkout viejo **no** los carga sola.
- **Cómo situarse sin perder trabajo:**
  1. `git status -sb`. Si hay cambios, **no** se descartan: se commitean en su rama o se usa un worktree aparte.
  2. Con el árbol limpio: `git fetch origin docs/session-continuity` y `git switch docs/session-continuity`, y después `git pull --ff-only`.
  3. Alternativa sin tocar el árbol actual: `git worktree add ..\cpv-continuity origin/docs/session-continuity`.
- **Otra computadora:** necesita su propio aprovisionamiento seguro de accesos (`ENVIRONMENT.md` §4). El repositorio no transfiere credenciales.
- **Pendiente obligatorio:** integrar estos archivos de arranque a `feature/upgrade-filament-v3` en la **próxima entrega de runtime autorizada explícitamente**.
  - No se integran solos, porque `CLAUDE.md`, `AGENTS.md` y `check_tools.ps1` no están todos cubiertos por `paths-ignore` y podrían disparar un despliegue.
  - Hasta entonces **no** están en todas las ramas ni en todas las computadoras.
- **Mensaje exacto para reiniciar** (el propietario lo pega en una sesión nueva):

  > Trabajá en la rama `docs/session-continuity` (sin descartar cambios; si el árbol no está limpio, usá un worktree). Leé `docs/orquestador/SESSION_RESUME.md` y ejecutá solo el comentario `<id>` del Issue #2.

## 2. Estado, con cada SHA por separado

| Hecho | Valor | Fuente y fecha |
|---|---|---|
| HEAD remoto de `feature/upgrade-filament-v3` | `0f8bdad97590676c966048e18afa35f76110ff1f` | 2026-10-16 08:24Z, registro `6211360371` |
| Workflow de ese push | run `38873194879`, success, 08:21:57–08:22:32Z | `6211360371` |
| Código en staging (checkout del VPS) | `0f8bdad`, 0 modificaciones | post-state 08:24:51Z |
| Imagen `app` en staging | `sha256:37e023fd…`, recreada 08:22:28Z | post-state |
| Imagen de recuperación | `perfilafiliados-app:pre-branding-c02d4ec` | creada ~08:11Z |

Una rama remota no prueba un despliegue: el estado de staging sale del VPS. Estas filas no se volvieron a comprobar en este checkpoint.

### Producto: CPV-HOME-BRANDING-AND-LAYOUT, DESPLEGADO

- **Recorrido:**
  - instrucción `6210321522`;
  - entrega `6210758420`;
  - aceptación `6211023097`;
  - despliegue **`0f8bdad`** (lo empujó el propietario), registrado en `6211360371`: DEPLOYED_FOR_OWNER_REVIEW — MANUAL.
- **Rama** `feature/home-branding-layout`:
  - TESTED_HEAD `17e2b07`;
  - checkpoint de producto `68168129a00e90004a7547ee5a1f9d270e7f15a5`;
  - el merge de esa rama en esta trae su evidencia: `audit/home_branding_layout_2026-10-16.md` §9 y `audit/runs/home_branding_deploy_2026-10-16/`.
- **Resultados:** smoke 37/37 y 0010C 18/18; el correo sigue en `log`; la taxonomía no cambió.
- **No verificado en vivo:** la geometría en un navegador real, «Salir» con clic y el recorrido SPA. Eso queda para la UAT del propietario.

### Continuidad: CPV-SESSION-CONTINUITY

- **Recorrido:** instrucción `6210552318`, entrega `6211267473`, revisión y cierre `6211767961`; entrega final en esta rama. Solo documentación; **sin** despliegue de runtime.
- **Informe:** `audit/session_continuity_2026-10-16.md`.

### Ya desplegado antes (registrado)

Inicio útil `c02d4ec`, mapa de la taxonomía `9e7e379`, manual por módulo `37e3b71` y TASK-0010A/B/C (C con OK del propietario, `6189493183`). El correo sigue en `log` y su activación está pendiente del propietario (`docs/deploy/CORREO_DEL_SISTEMA.md`). El ejecutor autónomo del VPS está suspendido.

## 3. Siguiente acción

- **Propietario:** UAT de Inicio en staging. Comprobar el logo, «Inicio», el orden de las tarjetas en la computadora y en el teléfono, los botones de ayuda, «Salir» y la vuelta a Inicio desde el menú. **No** hay que repetir ningún push ni despliegue.
- **Orquestador/propietario:** aceptar o corregir el cierre de CPV-SESSION-CONTINUITY.
- **Agente:** nada sin una instrucción nueva del Issue #2.

## 4. Worktrees en uso (no tocar sin instrucción)

`..\wt-task-0010a` (`b43737e`) y `..\wt-task-0010b` (`964550d`) son históricos. El runner aislado es `infra/contabo-test-runner` @ `ac4fc4f`.

## 5. Pendientes conocidos (no arrancan solos)

- **Despliegue:** `paths-ignore` no despliega un cambio que solo toque `resources/manual/*.md`, y el agente no puede lanzar `workflow_dispatch` (403). Por SSH, nunca `bash -s` por stdin.
- **Correo:** sin correo, un usuario nuevo no recibe la verificación. La reserva de avisos no garantiza un único envío.
- **Defectos:** `EmpresaPgsql` no compila; la casilla `ovid` dice «COVID.»; ISO 9001/17025 sin etiqueta; `app/images/user.pdf` es ajeno.
- **Fallas latentes:** `Empresa::users()` sin sesión, `EditEmpresa::getSteps()` con `cities` vacía, `StatsOverview` con `empresas` vacía.

## 6. Reglas vigentes (el detalle está en `PROJECT_KNOWLEDGE.md` §8)

- **Secretos:** ninguno en git, chat, Issue ni logs. No crear, rotar ni ampliar permisos sin una instrucción explícita.
- **Permisos:** un rechazo de permisos se **reporta, no se rodea**. El push a `feature/upgrade-filament-v3` puede tener que ejecutarlo el propietario.
- **Documentación:** toda ronda que cambie el comportamiento actualiza el manual o la ayuda en la misma entrega.
- **Entrega:** un comentario con `docs\orquestador\post_issue_comment.ps1 -BodyFile <md>`. El handoff JSON no es canal de entrega.

## 7. Bajo demanda

- **Herramientas por entorno y máquina nueva:** [`ENVIRONMENT.md`](ENVIRONMENT.md).
- **Accesos:** [`ACCESS_BOOTSTRAP.md`](ACCESS_BOOTSTRAP.md).
- **Conocimiento técnico:** [`PROJECT_KNOWLEDGE.md`](PROJECT_KNOWLEDGE.md).
- **Tarea actual:** [`current_task.md`](current_task.md).
- **Historia:** [`history/`](history/).
