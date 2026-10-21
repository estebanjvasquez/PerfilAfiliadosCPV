# CPV — Punto de reanudación (único y corto)

**Checkpoint:** 2026-10-21 ~16:40Z, rama `fix/sec-01-r4-01-role-editor-preserve` (desde `f41bcb6`): **F-SEC01-R4-01** (el editor de roles conserva lo que no muestra), lista para revisión, sin desplegar. R4 aceptado; contención verificada. Staging en `cf01e9c`.
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

- **Rama canónica:** **`fix/sec-01-r4-01-role-editor-preserve`** (desde R4 aceptado `97737ba` + docs `f41bcb6`; R3 `66f5ce8` por ancestro). TESTED `24413d5`. `planning/management-taxonomy` EN ESPERA: no se promueve.
  - Contiene `ce70b7f`, `edf20ed`, `10616af`, `0895258` y `28014c2`.
  - Los archivos de arranque (`CLAUDE.md`, `AGENTS.md`, `ENVIRONMENT.md`, `check_tools.ps1` y este archivo) ya están también en `feature/upgrade-filament-v3`, en su versión de `cf01e9c`; el checkpoint vigente es el de esta rama.
  - Una sesión abierta en un checkout viejo no los carga sola.
- **Cómo situarse sin perder trabajo:**
  1. `git status -sb`. Si hay cambios, **no** se descartan: se commitean en su rama o se usa un worktree aparte.
  2. Con el árbol limpio: `git fetch origin <rama>`, `git switch <rama>` y `git pull --ff-only`.
  3. Alternativa sin tocar el árbol actual: `git worktree add ..\cpv-<rama> origin/<rama>`.
- **Otra computadora:** necesita su propio aprovisionamiento seguro de accesos (`ENVIRONMENT.md` §4). El repositorio no transfiere credenciales.
- **Mensaje exacto para reiniciar:**

  > Trabajá en la rama `fix/sec-01-r4-01-role-editor-preserve` (sin descartar cambios; si el árbol no está limpio, usá un worktree). Leé `docs/orquestador/SESSION_RESUME.md` y ejecutá solo el comentario `<id>` del Issue #2.

## 2. Estado, con cada SHA por separado

| Hecho | Valor | Fuente y fecha |
|---|---|---|
| HEAD remoto de `feature/upgrade-filament-v3` | `cf01e9c3e30415a34ccda60b312a993c1388167d` | `git ls-remote`, 2026-10-21 ~12:48Z |
| Código en staging | `cf01e9c`, versionados limpios; imagen `081c35c5715d`; mantenimiento y debug apagados; sin migraciones pendientes | SSH de solo lectura, ~12:52Z |
| Workflow / recuperación | run `39602544527` success; `perfilafiliados-app:pre-sec01-87dd997` (`f91e97322189`) | 2026-10-21 |

### Desplegado (`6316265832`): CPV-SEC-01-R1-R2-DEPLOY `cf01e9c`

- F-01 (suplantación solo super admin), F-SEC01-02a (guarda de roles antes del sync), F-04 (`/form` retirado), R2-REV-05 (transacción de cuenta). Smoke `smoke_sec01_readonly.php` 17/17; export 25, 0010C 18, perfil 13. Los smokes con acentos se corren desde bash con `<` (PowerShell 5.1 los re-codifica).

### Anteriores

- Export-access (`ce70b7f`, desplegado por el propietario), perfil completo, home branding (`0f8bdad`, UAT pendiente) y continuidad: ver [`current_task.md`](current_task.md).
- **Correo:** sigue en `log`; activación pendiente del propietario (`docs/deploy/CORREO_DEL_SISTEMA.md`).

## 3. Siguiente acción

- **Seguridad:** registro en [`docs/security/SECURITY_STATUS.md`](../security/SECURITY_STATUS.md), plan en [`docs/plans/SECURITY_REVIEW_PLAN.md`](../plans/SECURITY_REVIEW_PLAN.md). F-01 y R2 desplegados (`cf01e9c`, UAT PASS). R3 aceptado y sin desplegar. **R4:** regla del propietario: solo super_admin administra roles. Código aceptado, sin desplegar; contención verificada. **F-SEC01-R4-01:** corrección lista para revisión; hasta desplegarla, guardar un rol en el editor vivo quita sus permisos ocultos (no guardar roles ahí). Recuperación: solo propuesta (≤1 vínculo). Pendientes: R3-02 y R3-03; 14 de 24 relation managers; 9 búsquedas; decisiones F-03/F-06. SEC-02..05 sin empezar; gerencia en espera. El análisis de visibilidad del repositorio es solo informativo, fuera del alcance de seguridad.
- **Accesos:** cada comando que los necesite usa el bootstrap documentado ([`ENVIRONMENT.md`](ENVIRONMENT.md), [`ACCESS_BOOTSTRAP.md`](ACCESS_BOOTSTRAP.md)); cada ejecutor guarda sus credenciales fuera de git. Si faltan `gh` o `python`, no es un bloqueo: se usan los mecanismos documentados.
- **Reanudar:** solo con una nueva asignación del propietario.
- **Propietario:** revisar F-SEC01-R4-01 y su propuesta de recuperación; despliegue R3+R4(+R4-01); UAT de secciones/perfil (`current_task.md`); Inicio en staging; caché Cloudflare `/pdf/*` `/xls/*`; correo; solicitud #1.
- **Agente:** nada sin una instrucción nueva del Issue #2.

## 4. Worktrees en uso (no tocar sin instrucción)

- `..\wt-task-0010a` (`b43737e`) y `..\wt-task-0010b` (`964550d`) son históricos.
- El runner aislado es `infra/contabo-test-runner` @ `3e76459` (phpunit 512M, poppler-utils, scripts UX del dossier).

## 5. Pendientes conocidos (no arrancan solos)

- **`JoinViews` (Cámara):** su vista falla con empresas incompletas, y su acción Pdf apunta a un método que no existe. Además usa `ExperienceView`/`PresenceView`, que pierden las experiencias con clave UUID y la experiencia internacional sin oficinas (el PDF ya las lee de los modelos).
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
