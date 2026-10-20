# CPV — Punto de reanudación (único y corto)

**Checkpoint:** 2026-10-20 ~08:45Z, en la rama `fix/pdf-operation-section`; staging corre `514fa6e`.
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

- **Rama canónica:** **`fix/pdf-operation-section`** (en revisión), sobre `fix/complete-company-pdf`, cuyo `514fa6e` corre en staging.
  - Contiene `ce70b7f`, `edf20ed`, `10616af`, `0895258` y `28014c2`.
  - Los archivos de arranque (`CLAUDE.md`, `AGENTS.md`, `ENVIRONMENT.md`, `check_tools.ps1` y este archivo) ya están también en `feature/upgrade-filament-v3`, en su versión de `514fa6e`; el checkpoint vigente es el de esta rama.
  - Una sesión abierta en un checkout viejo no los carga sola.
- **Cómo situarse sin perder trabajo:**
  1. `git status -sb`. Si hay cambios, **no** se descartan: se commitean en su rama o se usa un worktree aparte.
  2. Con el árbol limpio: `git fetch origin <rama>`, `git switch <rama>` y `git pull --ff-only`.
  3. Alternativa sin tocar el árbol actual: `git worktree add ..\cpv-<rama> origin/<rama>`.
- **Otra computadora:** necesita su propio aprovisionamiento seguro de accesos (`ENVIRONMENT.md` §4). El repositorio no transfiere credenciales.
- **Mensaje exacto para reiniciar:**

  > Trabajá en la rama `fix/pdf-operation-section` (sin descartar cambios; si el árbol no está limpio, usá un worktree). Leé `docs/orquestador/SESSION_RESUME.md` y ejecutá solo el comentario `<id>` del Issue #2.

## 2. Estado, con cada SHA por separado

| Hecho | Valor | Fuente y fecha |
|---|---|---|
| HEAD remoto de `feature/upgrade-filament-v3` | `514fa6e09c666a6eefa8178bf56ea58da48b1a20` | `git ls-remote`, 2026-10-20 ~07:57Z |
| Código en staging (checkout del VPS) | `514fa6e`, 0 modificaciones; imagen `7a557f27edff`; mantenimiento apagado; sin migraciones pendientes | SSH de solo lectura, 08:00Z |
| Workflow del último despliegue | run `39390032508`, success, 07:58Z | API pública |
| Imagen de recuperación | `perfilafiliados-app:pre-dossier-ce70b7f` (`aa44b1884b93`, la imagen de `ce70b7f`) | 2026-10-20 ~07:56Z |

### En revisión (`6286644396`): CPV-PDF-OPERATION-SECTION — READY_FOR_REVIEW, sin desplegar

- «Operación en Venezuela» es una sección propia (`operacion`, 11 en total), y el selector describe cada opción.
- Probadas las 11 opciones, una por una, en PDF real. `3ffa85d`: enfocadas 35 OK. Informe `audit/pdf_operation_section_2026-10-20.md`.

### Desplegado (`6286221372`): CPV-COMPLETE-COMPANY-PDF `514fa6e`

- Perfil completo visible y por defecto, secciones opcionales con lista blanca, encabezado por página. Informe `audit/complete_company_pdf_2026-10-19.md`.

### Anteriores

- **CPV-INTEGRATED-EXPORT-ACCESS-DEPLOY** (`6265502896`): `ce70b7f` desplegado por el propietario (el clasificador rechazó el push del agente; no se rodeó). Smoke 25/25 + 0010C 18/18.
- **CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES** (aceptado `6265458863`): descargas protegidas, 404 seguro.
- **CPV-HOME-BRANDING-AND-LAYOUT:** desplegado en `0f8bdad` (`6211360371`); queda la UAT del propietario.
- **CPV-SESSION-CONTINUITY:** cerrado y aceptado en `6211970495`. Informe: `audit/session_continuity_2026-10-16.md`.
- **Despliegues previos:** Inicio útil `c02d4ec`, mapa `9e7e379`, manual `37e3b71` y TASK-0010A/B/C.
- **Correo:** sigue en `log`; la activación está pendiente del propietario (`docs/deploy/CORREO_DEL_SISTEMA.md`).

## 3. Siguiente acción

- **Propietario:** revisar CPV-PDF-OPERATION-SECTION; UAT del perfil completo (`current_task.md`).
- **Propietario:**
  - UAT de Inicio en staging;
  - reglas de caché de Cloudflare para `/pdf/*` y `/xls/*`;
  - el correo (proveedor, credenciales, prueba controlada);
  - decidir si se reintenta la solicitud #1.
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
