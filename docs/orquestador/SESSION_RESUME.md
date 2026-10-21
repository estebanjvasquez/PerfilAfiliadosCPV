# CPV — Punto de reanudación (único y corto)

**Checkpoint:** 2026-10-21 ~07:45Z, en la rama `fix/sec-01-account-path-boundaries` (seguridad, SEC-01 R2); staging corre `87dd997`.
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

- **Rama canónica:** **`fix/sec-01-account-path-boundaries`** (SEC-01 R2, desde `0139e95`): fix de inyección de privilegios en usuarios, F-04 removido, pruebas y evidencia. `fix/sec-01-impersonation-gate` (F-01) y esta rama están aceptadas pendientes de despliegue, sin desplegar. `planning/management-taxonomy` EN ESPERA: no se promueve.
  - Contiene `ce70b7f`, `edf20ed`, `10616af`, `0895258` y `28014c2`.
  - Los archivos de arranque (`CLAUDE.md`, `AGENTS.md`, `ENVIRONMENT.md`, `check_tools.ps1` y este archivo) ya están también en `feature/upgrade-filament-v3`, en su versión de `87dd997`; el checkpoint vigente es el de esta rama.
  - Una sesión abierta en un checkout viejo no los carga sola.
- **Cómo situarse sin perder trabajo:**
  1. `git status -sb`. Si hay cambios, **no** se descartan: se commitean en su rama o se usa un worktree aparte.
  2. Con el árbol limpio: `git fetch origin <rama>`, `git switch <rama>` y `git pull --ff-only`.
  3. Alternativa sin tocar el árbol actual: `git worktree add ..\cpv-<rama> origin/<rama>`.
- **Otra computadora:** necesita su propio aprovisionamiento seguro de accesos (`ENVIRONMENT.md` §4). El repositorio no transfiere credenciales.
- **Mensaje exacto para reiniciar:**

  > Trabajá en la rama `fix/sec-01-account-path-boundaries` (sin descartar cambios; si el árbol no está limpio, usá un worktree). Leé `docs/orquestador/SESSION_RESUME.md` y ejecutá solo el comentario `<id>` del Issue #2.

## 2. Estado, con cada SHA por separado

| Hecho | Valor | Fuente y fecha |
|---|---|---|
| HEAD remoto de `feature/upgrade-filament-v3` | `87dd9975e7cfcc2c9a5eb72d4686fbc11fc907f4` | `git ls-remote`, 2026-10-20 ~10:07Z |
| Código en staging (checkout del VPS) | `87dd997`, 0 modificaciones; imagen `f91e97322189`; mantenimiento apagado; sin migraciones pendientes | SSH de solo lectura, 10:10Z |
| Workflow del último despliegue | run `39405700502`, success, 10:08Z | API pública |
| Imagen de recuperación | `perfilafiliados-app:pre-opsection-514fa6e` (`7a557f27edff`, la imagen de `514fa6e`) | 2026-10-20 ~10:07Z |

### Desplegado (`6288459314`): CPV-PDF-OPERATION-SECTION `87dd997`

- «Operación en Venezuela» es sección propia (`operacion`, 11 en total); selector con descripciones. 11 opciones probadas en PDF real; smokes 13/25/18. `audit/pdf_operation_section_2026-10-20.md` §8.

### Anteriores

- **CPV-INTEGRATED-EXPORT-ACCESS-DEPLOY** (`6265502896`): `ce70b7f` desplegado por el propietario (el clasificador rechazó el push del agente; no se rodeó). Smoke 25/25 + 0010C 18/18.
- **CPV-COMPLETE-COMPANY-PDF** (`6286221372`): perfil completo por defecto, secciones con lista blanca. `audit/complete_company_pdf_2026-10-19.md`.
- **CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES** (aceptado `6265458863`): descargas protegidas, 404 seguro.
- **CPV-HOME-BRANDING-AND-LAYOUT:** desplegado en `0f8bdad` (`6211360371`); queda la UAT del propietario.
- **CPV-SESSION-CONTINUITY:** cerrado y aceptado en `6211970495`. Informe: `audit/session_continuity_2026-10-16.md`.
- **Correo:** sigue en `log`; activación pendiente del propietario (`docs/deploy/CORREO_DEL_SISTEMA.md`).

## 3. Siguiente acción

- **Seguridad (CPV-SEC-01):** registro acumulativo en `docs/security/SECURITY_STATUS.md`. R1 F-01 en `fix/sec-01-impersonation-gate` (aviso `6293694720`). R2 (`6310273754`, correcciones `6310331211`) en `fix/sec-01-account-path-boundaries`: concesión de roles autorizada ANTES de persistir, F-04 removido, traversal sin hallazgos; suite 606 OK. **F-01 SIGUE SIN desplegar** (`87dd997`): riesgo vivo. R2 no aceptada aún. F-02 editor de roles / F-03 / F-06 son decisiones del propietario. SEC-02..05 restantes sin empezar; gerencia en espera.
- **Propietario:** desplegar F-01 y SEC-01 R2 (decisión); UAT de secciones/perfil (`current_task.md`); Inicio en staging; caché Cloudflare `/pdf/*` `/xls/*`; correo; solicitud #1.
- **Agente:** nada sin una instrucción nueva del Issue #2.

## 4. Worktrees en uso (no tocar sin instrucción)

- `..\wt-task-0010a` (`b43737e`) y `..\wt-task-0010b` (`964550d`) son históricos.
- El runner aislado es `infra/contabo-test-runner` @ `3e76459`: phpunit con 512M, poppler-utils (texto y páginas de los PDF de evidencia); `ux_check_dossier.mjs` + `ux_seed_dossier.php`. El seed recibe `CPV_UX_BASE_URL`, y la app servida usa caché en archivo.

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
