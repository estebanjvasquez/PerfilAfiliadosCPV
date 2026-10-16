# CPV — Punto de reanudación (único y corto)

**Checkpoint:** 2026-10-16 ~08:40Z. Lo anterior, completo y sin cambios, está en
[`history/SESSION_RESUME_hasta_2026-10-16.md`](history/SESSION_RESUME_hasta_2026-10-16.md).

> **Prioridad:** las instrucciones del usuario y del comentario del Issue #2 asignado prevalecen sobre este
> archivo. Si algo de aquí contradice GitHub o el servidor, prevalece la evidencia viva: se registra la
> discrepancia y se sigue en forma acotada. Las filas con fecha son hechos observados entonces, **no** estado
> vivo.

## 0. Arranque (presupuesto: ≤5 min, ≤~10 KB de documentación)

1. Este archivo, completo. No leer el historial, el hilo del Issue #2, los informes ni `audit/**`.
2. `git status -sb`, `git branch --show-current`, `git rev-parse HEAD`. No cambiar de rama, no `pull`, no
   descartar cambios ajenos.
3. **Solo** el comentario del Issue #2 que asigne el usuario, leído completo (API anónima, ver
   [`ENVIRONMENT.md`](ENVIRONMENT.md) §3). Más comentarios, solo si ese comentario los cita.
4. Accesos, por comando y solo si la tarea los necesita: `. docs\orquestador\bootstrap_access.ps1 -Quiet; <cmd>`.
5. Herramientas, solo las que la tarea necesita: `docs\orquestador\check_tools.ps1 -Name <tool>` (solo nombres y
   estado, sin instalar nada).

**No** se arranca con: loops automáticos, agentes o subagentes, sondeos, suites de tests, volcados de logs,
auditorías del código, escaneo del disco o de transcripciones, ni lectura completa de
`audit/orchestrator_handoff.json` (515 KB; si hace falta, solo campos concretos). El protocolo autónomo está
**suspendido** desde el 2026-10-14 y queda como referencia histórica.

Manifiesto de este arranque, con archivos y bytes: [`STARTUP_DRY_RUN.json`](STARTUP_DRY_RUN.json).

## 1. Estado, con cada SHA por separado

| Hecho | Valor | Observado |
|---|---|---|
| HEAD remoto de la rama de despliegue `feature/upgrade-filament-v3` | `c02d4ec8f578b25f8101ac3109c7931e23298f2b` | 2026-10-16 08:10Z (`git ls-remote`) |
| Código corriendo en staging (checkout del VPS) | `c02d4ec`, 0 modificaciones | 2026-10-16 08:10Z (SSH, solo lectura) |
| Imagen del contenedor `app` en staging | `sha256:97236501…`, arrancado 2026-10-15 15:40:23Z | 2026-10-16 08:10Z |
| Imagen de recuperación | `perfilafiliados-app:pre-branding-c02d4ec` (= la imagen de arriba) | creada 2026-10-16 ~08:11Z |

Una rama remota no prueba un despliegue: el estado de staging sale del VPS.

### Tarea de producto en curso: CPV-HOME-BRANDING-AND-LAYOUT

- **Instrucción:** `6210321522` (propietario). **Entrega:** `6210758420` (READY_FOR_REVIEW — MANUAL).
- **Rama:** `feature/home-branding-layout`. BASE `6e26302` (= `c02d4ec` más audit/docs). **TESTED_HEAD
  `17e2b076f221f36037a193b8fcab58a11d243009`**, **REVIEW_HEAD `0f8bdad97590676c966048e18afa35f76110ff1f`**.
- **Qué hace:** «Escritorio» pasa a «Inicio», el logo va como título y las tarjetas siguen el orden cuenta → ayuda → avisos.
  Se probó en aislamiento: 40 tests y 135/135 en navegador. Informe: `audit/home_branding_layout_2026-10-16.md`.
- **Despliegue:** el propietario lo **aprobó en el chat** el 2026-10-16. Las precondiciones pasaron:
  - estado previo en `audit/runs/home_branding_deploy_2026-10-16/`;
  - el smoke de 0010C dio 18/18;
  - 0 migraciones pendientes y 0 runs en curso.
  - El fast-forward `c02d4ec..0f8bdad` a la rama de despliegue lo **rechazó el clasificador de permisos**, así que **NO está desplegado**.
- **Smoke posterior preparado:** `docs/deploy/smoke_home_branding_readonly.php`.

### Tarea de continuidad: CPV-SESSION-CONTINUITY

- Instrucción `6210552318`. Rama `docs/session-continuity` (sale de `0f8bdad`). Solo documentación, sin desplegar.
- Informe: `audit/session_continuity_2026-10-16.md`.

### Ya desplegado y aceptado (registrado)

- **Inicio útil:** `c02d4ec`.
- **Mapa de la taxonomía:** `9e7e379`.
- **Manual por módulo:** `37e3b71`.
- **TASK-0010A/B/C:** el propietario informó la prueba de C como OK (`6189493183`).
- **Correo del sistema:** sigue en modo `log` y la activación está **pendiente del propietario** (`docs/deploy/CORREO_DEL_SISTEMA.md`).
- **Ejecutor autónomo del VPS:** **suspendido**. No se reactiva sin un registro del propietario.

## 2. Siguiente acción

- **Propietario:** ejecutar el despliegue aprobado, que es un fast-forward sin forzar:
  `git push origin 0f8bdad97590676c966048e18afa35f76110ff1f:refs/heads/feature/upgrade-filament-v3`.
  El workflow «Deploy a Contabo» se dispara solo. Después, un agente puede capturar el estado posterior y
  correr `smoke_home_branding_readonly.php` y `smoke_task_0010c_readonly.php`. Esos smokes son de solo lectura y van
  como `www-data` dentro de `app`.
- **Propietario/orquestador:** revisar `6210758420` (producto) y la entrega de CPV-SESSION-CONTINUITY.
- **Agente:** ninguna otra cosa sin una instrucción nueva del Issue #2.

## 3. Ramas y worktrees en uso (no tocar sin instrucción)

| Ubicación | Rama | Nota |
|---|---|---|
| repo principal | la de la tarea asignada | ver `git branch --show-current` |
| `..\wt-task-0010a` | `feature/task-0010a-category-request` @ `b43737e` | histórico, sin trabajo pendiente conocido |
| `..\wt-task-0010b` | `feature/task-0010b-online-manual` @ `964550d` | histórico |
| worktree temporal del runner | `infra/contabo-test-runner` @ `ac4fc4f` | arnés de tests aislado |

## 4. Pendientes conocidos (no arrancan solos)

- **Workflow:** `paths-ignore` (`**.md`) no despliega un cambio que solo toque `resources/manual/*.md`. El agente no puede lanzar `workflow_dispatch` (403).
- **Vía SSH manual de despliegue:** nunca enviar el script por stdin con `bash -s` (2026-10-15).
- **Correo pendiente:** sin correo, un usuario nuevo no recibe la verificación.
- **Reserva de avisos:** no garantiza un único envío.
- **`EmpresaPgsql` no compila** (desde `a5265ce`).
- **Defectos ya vistos en la interfaz:**
  - la casilla `ovid` dice «COVID.»;
  - ISO 9001/17025 no tienen etiqueta;
  - `app/images/user.pdf` es un PDF ajeno.
- **Fallas latentes:**
  - `Empresa::users()` falla si no hay sesión;
  - `EditEmpresa::getSteps()` falla si `cities` está vacía;
  - `StatsOverview` divide por cero si `empresas` está vacía.

## 5. Reglas vigentes (resumen; el detalle está en `PROJECT_KNOWLEDGE.md` §8)

- **Secretos:** ningún secreto en git, chat, Issue ni logs. No crear, rotar ni ampliar permisos sin una instrucción explícita.
- **Permisos:** un rechazo de permisos se **reporta, no se rodea**. Si rechaza el push a `feature/upgrade-filament-v3`, lo ejecuta el propietario.
- **Documentación:** toda ronda que cambie el comportamiento actualiza el manual o la ayuda en la misma entrega.
- **Entrega:** un solo comentario en el Issue #2 con `docs\orquestador\post_issue_comment.ps1 -BodyFile <md>`. No se usa `audit/orchestrator_handoff.json` como canal.

## 6. Bajo demanda (no en el arranque)

- **Herramientas por entorno y máquina nueva:** [`ENVIRONMENT.md`](ENVIRONMENT.md).
- **Accesos** (solo nombres): [`ACCESS_BOOTSTRAP.md`](ACCESS_BOOTSTRAP.md).
- **Conocimiento técnico:** [`PROJECT_KNOWLEDGE.md`](PROJECT_KNOWLEDGE.md).
- **Tarea actual en una página:** [`current_task.md`](current_task.md).
- **Historia:** [`history/`](history/).
