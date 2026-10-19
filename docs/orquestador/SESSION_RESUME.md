# CPV — Punto de reanudación (único y corto)

**Checkpoint:** 2026-10-19 ~10:30Z, en la rama `review/export-access` (solo revisión). Sale de `fix/verification-completion` @ `edf20ed` (aceptada en `6262657488`), que contiene `10616af`, `0895258` y `docs/session-continuity` @ `28014c2`.
Las versiones largas anteriores, completas y sin cambios, están en [`history/SESSION_RESUME_hasta_2026-10-16.md`](history/SESSION_RESUME_hasta_2026-10-16.md).
El checkpoint largo de producto está en `68168129:docs/orquestador/SESSION_RESUME.md`.

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

- **Rama canónica de runtime:** **`fix/verification-completion`** @ `edf20ed`, el candidato integrado aceptado. La revisión en curso vive en `review/export-access`, que solo agrega tests, fixtures e informe.
  - Contiene `fix/first-save-postgres` @ `10616af`, que a su vez contiene `0895258` y `28014c2`.
  - Es el candidato de runtime que **lleva los archivos de arranque**: `CLAUDE.md`, `AGENTS.md`, `ENVIRONMENT.md`, `check_tools.ps1` y este archivo.
  - Esos archivos existen solo en estas ramas. Llegan a `feature/upgrade-filament-v3` únicamente si este candidato se despliega con una autorización.
  - Una sesión abierta en otra rama o en un checkout viejo no los carga sola.
- **Cómo situarse sin perder trabajo:**
  1. `git status -sb`. Si hay cambios, **no** se descartan: se commitean en su rama o se usa un worktree aparte.
  2. Con el árbol limpio: `git fetch origin <rama>`, `git switch <rama>` y `git pull --ff-only`.
  3. Alternativa sin tocar el árbol actual: `git worktree add ..\cpv-<rama> origin/<rama>`.
- **Otra computadora:** necesita su propio aprovisionamiento seguro de accesos (`ENVIRONMENT.md` §4). El repositorio no transfiere credenciales.
- **Mensaje exacto para reiniciar:**

  > Trabajá en la rama `review/export-access` (sin descartar cambios; si el árbol no está limpio, usá un worktree). Leé `docs/orquestador/SESSION_RESUME.md` y ejecutá solo el comentario `<id>` del Issue #2.

## 2. Estado, con cada SHA por separado

| Hecho | Valor | Fuente y fecha |
|---|---|---|
| HEAD remoto de `feature/upgrade-filament-v3` | `0f8bdad97590676c966048e18afa35f76110ff1f` | `git ls-remote`, 2026-10-16 12:27Z |
| Código en staging (checkout del VPS) | `0f8bdad`, 0 modificaciones rastreadas (6 carpetas de assets publicados sin rastrear) | SSH de solo lectura, 12:27Z |
| Workflow del último despliegue | run `38873194879`, success, 08:22Z | `6211360371` |
| Imagen de recuperación | `perfilafiliados-app:pre-branding-c02d4ec` | creada ~08:11Z |

### En curso: CPV-EXPORT-ACCESS-REVIEW (READY_FOR_REVIEW — MANUAL; solo revisión, sin cambios de runtime)

- **Instrucción:** `6262799754`. BASE `edf20ed`. Informe: `audit/export_access_review_2026-10-19.md`; evidencia `audit/runs/export_access/`.
- **Confirmado:** `pdf/{empresa}` y `xls/{empresa}` solo pasan por el grupo `web` (sin sesión obligatoria ni autorización). Cualquiera, incluso un invitado, obtiene el perfil o la fila de cualquier empresa. El XLS filtra a la empresa del id, no a todas, pero toma el id de `REQUEST_URI` y responde 500 con `?x` o `abc`; su `Cache-Control` es `public`.
- **Propuesta:** middleware del panel con verificación, `EmpresaPolicy::export` (vínculo + `view_empresa`, o permiso `export_any_empresa`), 404 antes de consultar, XLS con binding tipado y sin `REQUEST_URI`, cabeceras `private, no-store`.
- **Decisiones del propietario:** D1 (¿personal de la Cámara exporta cualquier empresa?), D2 (¿qué hacer con el XLS por empresa?), D3 (¿403 o 404?).
- **Antes:** CPV-VERIFICATION-FLOW-AND-COMPLETION, aceptada (`6262657488`): suite completa 568 OK en `3e684c5`.

### Anteriores

- **CPV-HOME-BRANDING-AND-LAYOUT:** desplegado en `0f8bdad` (`6211360371`). Queda la UAT del propietario.
- **CPV-SESSION-CONTINUITY:** cerrado y aceptado en `6211970495`. Informe: `audit/session_continuity_2026-10-16.md`.
- **Despliegues previos:** Inicio útil `c02d4ec`, mapa `9e7e379`, manual `37e3b71` y TASK-0010A/B/C.
- **Correo:** sigue en `log`; la activación está pendiente del propietario (`docs/deploy/CORREO_DEL_SISTEMA.md`).
- **Ejecutor autónomo:** suspendido.

## 3. Siguiente acción

- **Orquestador/propietario:** revisión manual del candidato integrado. Desplegarlo requiere una autorización aparte del propietario. El push a la rama de despliegue puede tener que hacerlo el propietario.
- **Propietario:**
  - UAT de Inicio en staging;
  - el correo (proveedor, credenciales, prueba controlada);
  - decidir si se reintenta la solicitud #1.
- **Agente:** nada sin una instrucción nueva del Issue #2.

## 4. Worktrees en uso (no tocar sin instrucción)

- `..\wt-task-0010a` (`b43737e`) y `..\wt-task-0010b` (`964550d`) son históricos.
- El runner aislado es `infra/contabo-test-runner` @ `2b3471f`: phpunit con 512M; `ux_check_verification.mjs` + `ux_seed_verification.php`. El seed recibe `CPV_UX_BASE_URL`, y la app servida usa caché en archivo.

## 5. Pendientes conocidos (no arrancan solos)

- **Seguridad, prioritario:** la reparación de las descargas espera revisión y decisiones D1, D2 y D3 (`audit/export_access_review_2026-10-19.md`).
- **Correo:** pendiente del propietario. Sin envío, un afiliado nuevo **no** puede verificarse solo.
- **Datos históricos (solo lectura, sin acción):**
  - 1 fila de `assets` con actor NULL;
  - 1 empresa con 2 filas de `assets` (informe §8).
- **Despliegue:**
  - `paths-ignore` no despliega un cambio que solo toque `resources/manual/*.md`;
  - el agente no puede lanzar `workflow_dispatch` (403);
  - por SSH, nunca `bash -s` por stdin.
- **Avisos de solicitudes:** la reserva no garantiza un único envío.
- **Backlog:**
  - `Empresa::users()` falla sin sesión;
  - `EditEmpresa::getSteps()` falla con `cities` vacía;
  - `StatsOverview` falla con `empresas` vacía;
  - el estado de los ancestros en el buscador;
  - `app/images/user.pdf` es un PDF ajeno;
  - la dependencia mysql del crawler.

## 6. Reglas vigentes (el detalle está en `PROJECT_KNOWLEDGE.md` §8)

- **Secretos:** ninguno en git, chat, Issue ni logs. No crear, rotar ni ampliar permisos sin una instrucción explícita.
- **Permisos:** un rechazo de permisos se **reporta, no se rodea**.
- **Documentación:** toda ronda que cambie el comportamiento actualiza el manual o la ayuda en la misma entrega.
- **Entrega:** un comentario con `docs\orquestador\post_issue_comment.ps1 -BodyFile <md>`. El handoff JSON no es canal de entrega.

## 7. Bajo demanda

- **Herramientas y máquina nueva:** [`ENVIRONMENT.md`](ENVIRONMENT.md).
- **Accesos:** [`ACCESS_BOOTSTRAP.md`](ACCESS_BOOTSTRAP.md).
- **Conocimiento técnico:** [`PROJECT_KNOWLEDGE.md`](PROJECT_KNOWLEDGE.md).
- **Tarea actual:** [`current_task.md`](current_task.md).
- **Historia:** [`history/`](history/).
