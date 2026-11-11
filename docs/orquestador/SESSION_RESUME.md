# CPV — Punto de reanudación (único y corto)

**Checkpoint:** 2026-11-11 17:17Z: **decisiones del propietario sobre la consolidación de pendientes** (Issue #2 `6802438608`), solo registro documental: no asigna implementación, despliegue ni merge ([`PENDIENTES_CONSOLIDADO_2026-11-11.md`](PENDIENTES_CONSOLIDADO_2026-11-11.md), corte `f6e3134` y addendum). Antes, el mismo día: **TASK-0017** (SEC-03, auditoría de recuperación de cuentas, tokens y Host/proxies, Issue #2 `6797800863`) READY_FOR_REVIEW, solo auditoría, sobre staging `b048df0`. Sin toma de cuentas confirmada; hallazgos F-SEC03-01..06, sin corregir ni desplegar ([`tasks/0017-sec03-account-recovery-host-trust-audit.md`](tasks/0017-sec03-account-recovery-host-trust-audit.md)). Antes, el mismo día: **TASK-0016** (análisis IA de solicitudes de categoría, Issue #2 `6791532623`) READY_FOR_REVIEW y desplegada en staging por autorización del propietario (`feature/upgrade-filament-v3` @ `893351f`, run `42999603780`); proveedor, clave, modelo y presupuesto se configuran en el panel; UAT pendiente ([`tasks/0016-category-request-ai-analysis.md`](tasks/0016-category-request-ai-analysis.md)). TASK-0013, TASK-0014 y TASK-0015: ver `current_task.md`. Anterior: 2026-11-03, rama `fix/sec-01-r4-01-role-editor-preserve`: **TASK-0012** (recuperación de acceso por correo y RIF, Issue #2 `6602168914`) desplegada en staging por autorización del propietario; UAT y prueba de correo real pendientes ([`tasks/0012-account-recovery.md`](tasks/0012-account-recovery.md)). Staging `feature/upgrade-filament-v3` @ `06155ce` (workflow `41630703105` success; rutas de login/recuperación GET 200). TASK-0011 APPROVED (`6440840198`) y aplicada previamente en staging. Anterior, sin cambios (2026-10-21 ~16:40Z, desde `f41bcb6`): **F-SEC01-R4-01** (el editor de roles conserva lo que no muestra), lista para revisión, sin desplegar. R4 aceptado; contención verificada.
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

  > Trabaje en la rama `fix/sec-01-r4-01-role-editor-preserve` (sin descartar cambios; si el árbol no está limpio, use un worktree). Lea `docs/orquestador/SESSION_RESUME.md` y ejecute solo el comentario `<id>` del Issue #2.

## 2. Estado, con cada SHA por separado

| Hecho | Valor | Fuente y fecha |
|---|---|---|
| Staging en ejecución (`feature/upgrade-filament-v3`) | `b048df0` | `git rev-parse HEAD` en el servidor, solo lectura, 2026-11-11; `tasks/0017` |
| HEAD remoto de `feature/upgrade-filament-v3` | `06155ce0579654c4c8b0d8f4dd4901d835b3dbb7` | push verificado, 2026-11-03; TASK-0012 |
| Staging TASK-0012 | workflow `41630703105` success; `/admin/login`, `/admin/password-reset/request`, `/admin/recuperar-acceso` HTTP 200 | verificación GET, 2026-11-03; sin envío de formulario/correo |
| Código en staging | `cf01e9c`, versionados limpios; imagen `081c35c5715d`; mantenimiento y debug apagados; sin migraciones pendientes | SSH de solo lectura, ~12:52Z |
| Workflow / recuperación | run `39602544527` success; `perfilafiliados-app:pre-sec01-87dd997` (`f91e97322189`) | 2026-10-21 |

### Desplegado (`6316265832`): CPV-SEC-01-R1-R2-DEPLOY `cf01e9c`

- F-01 (suplantación solo super admin), F-SEC01-02a (guarda de roles antes del sync), F-04 (`/form` retirado), R2-REV-05 (transacción de cuenta). Smoke `smoke_sec01_readonly.php` 17/17; export 25, 0010C 18, perfil 13. Los smokes con acentos se corren desde bash con `<` (PowerShell 5.1 los re-codifica).

### Anteriores

- Export-access (`ce70b7f`, desplegado por el propietario), perfil completo, home branding (`0f8bdad`, UAT pendiente) y continuidad: ver [`current_task.md`](current_task.md).
- **Correo:** ~~sigue en `log`; activación pendiente del propietario~~ → `smtp` en staging desde el 2026-10-26 (`tasks/0012`; RT-MAIL-2026-10 en `SECURITY_STATUS.md`). El propietario declara haber recibido correos de verificación y recuperación (`6802438608`); falta evidencia formal de todos los flujos, incluido RIF (`docs/deploy/CORREO_DEL_SISTEMA.md`).

## 3. Siguiente acción

- **Decisiones del propietario (`6802438608`, 2026-11-11):** detalle en el addendum de [`PENDIENTES_CONSOLIDADO_2026-11-11.md`](PENDIENTES_CONSOLIDADO_2026-11-11.md). Autoriza en alcance: P1–P5 de SEC-03 con pruebas aisladas (P1 antes de P2); revisión final, integración y despliegue **en staging** de R3+R4+R4-01; pruebas controladas de correo en staging; configurar y probar la búsqueda semántica en staging; un plan comparativo de producción, checklist y ensayo aislado. **Nada de eso empieza sin materializar o asignar su tarea** según `PROTOCOL.md`. UAT declarada por el propietario en 10 comprobaciones; no equivale a aprobación técnica, integración ni despliegue. Gate de producción: seguridad crítica y alta cerrada, UAT completa, aceptación de la Cámara y autorización explícita.
- **SEC-03 / TASK-0017 (2026-11-11):** informe `audit/SEC03_TASK0017_ACCOUNT_RECOVERY_HOST_TRUST.md`. Lo más urgente para el propietario: F-SEC03-03, porque los límites por IP son globales y unas pocas peticiones bloquean el login a todos; y F-SEC03-01, que hay que verificar en producción antes de llevar la recuperación allí. Las correcciones P1–P5 esperan autorización. Si se confía en proxies, quitar `X-Forwarded-Host`.
- **Seguridad:** registro en [`docs/security/SECURITY_STATUS.md`](../security/SECURITY_STATUS.md), plan en [`docs/plans/SECURITY_REVIEW_PLAN.md`](../plans/SECURITY_REVIEW_PLAN.md). F-01 y R2 desplegados (`cf01e9c`, UAT PASS). R3 aceptado y sin desplegar. **R4:** regla del propietario: solo super_admin administra roles. Código aceptado, sin desplegar; contención verificada. **F-SEC01-R4-01:** corrección lista para revisión; hasta desplegarla, guardar un rol en el editor vivo quita sus permisos ocultos (no guardar roles ahí). Recuperación: solo propuesta (≤1 vínculo). Pendientes: R3-02 y R3-03; 14 de 24 relation managers; 9 búsquedas; decisiones F-03/F-06. SEC-02..05 sin empezar; gerencia en espera. El análisis de visibilidad del repositorio es solo informativo, fuera del alcance de seguridad.
- **Accesos:** cada comando que los necesite usa el bootstrap documentado ([`ENVIRONMENT.md`](ENVIRONMENT.md), [`ACCESS_BOOTSTRAP.md`](ACCESS_BOOTSTRAP.md)); cada ejecutor guarda sus credenciales fuera de git. Si faltan `gh` o `python`, no es un bloqueo: se usan los mecanismos documentados.
- **Reanudar:** solo con una nueva asignación del propietario.
- **Propietario:** revisar F-SEC01-R4-01 y su propuesta de recuperación; despliegue R3+R4(+R4-01); UAT de secciones/perfil (`current_task.md`); Inicio en staging; caché Cloudflare `/pdf/*` `/xls/*`; correo; solicitud #1.
- **Propietario:** hacer UAT de recuperación; autorizar aparte, si lo desea, una prueba de correo real a su buzón de prueba.
- **Agente:** sin trabajo técnico hasta una asignación nueva (STOP). TASK-0016: UAT declarada por el propietario (`6802438608`). TASK-0012: desplegada; faltan las pruebas controladas de correo y RIF.

## 4. Worktrees en uso (no tocar sin instrucción)

- `..\wt-task-0010a` (`b43737e`) y `..\wt-task-0010b` (`964550d`) son históricos.
- El runner aislado es `infra/contabo-test-runner` @ `3e76459` (phpunit 512M, poppler-utils, scripts UX del dossier).

## 5. Pendientes conocidos (no arrancan solos)

- **`JoinViews` (Cámara):** su vista falla con empresas incompletas, y su acción Pdf apunta a un método que no existe. Además usa `ExperienceView`/`PresenceView`, que pierden las experiencias con clave UUID y la experiencia internacional sin oficinas (el PDF ya las lee de los modelos).
- **Correo:** ~~pendiente del propietario. Sin envío, un afiliado nuevo **no** puede verificarse solo~~ → activo en staging (§2, «Anteriores»). Pendiente: evidencia formal de cada flujo con cuentas y buzones de prueba (autorizada en `6802438608`).
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
