# CPV — Punto de reanudación (único y corto)

**Checkpoint:** 2026-11-13 ~11:55Z: **TASK-0026** (SEC-01, Issue #2 `6851565505`) **DEPLOYED_AWAITING_24H**: **staging = `6e7f525`** (workflow `43419358951`, *fast-forward* desde `270e07d`); migración `2026_11_13_000001_harden_public_report_views` sola en el lote 63 de la BD compartida; 13/13 vistas con `security_invoker` y sin acceso de `anon`/`authenticated`; preflight y verificación posterior PASS; monitorización de 24 h hasta el 2026-11-14 11:43:38Z con `monitor_check.sh` (respaldo `sec01-t26-20261113T113455Z`) ([`tasks/0026-sec01-t24-02-staging-deploy.md`](tasks/0026-sec01-t24-02-staging-deploy.md)). Antes, 11:26Z: TASK-0025 **APPROVED** como candidato (`6851472706`). Antes, ~11:25Z: **TASK-0025** (SEC-01, Issue #2 `6850596236`) READY_FOR_REVIEW: corrección candidata de F-SEC01-T24-02 **sin aplicar** (`fix/sec-01-t25-public-views-hardening`: fails-before `56375f5`, TESTED/REVIEW `6e7f525`; migración `security_invoker` + REVOKE a `anon`/`authenticated` en las 13 vistas; clon REST aislado PASS). Aplicarla requiere un gate: el despliegue ejecuta `migrate --force` sobre la BD compartida ([`tasks/0025-sec01-t24-02-public-views-hardening.md`](tasks/0025-sec01-t24-02-public-views-hardening.md)). Antes, 10:39Z: TASK-0024 **APPROVED** (`6850519341`). Antes, ~10:30Z: **TASK-0024** (SEC-01, Issue #2 `6848913937` + decisiones `6849259532`) READY_FOR_REVIEW, solo análisis y pruebas aisladas: R-01, R-02 y R-03 cerradas por el propietario; R-04 (2 huérfanas de `contact_empresa`) remediación demostrada en copia aislada, pendiente de gate; R-05 límite de R4 interno y hallazgos nuevos F-SEC01-T24-01 y F-SEC01-T24-02; staging sin cambios (`270e07d`) ([`tasks/0024-sec01-t23-reservations-diagnosis.md`](tasks/0024-sec01-t23-reservations-diagnosis.md)). Antes, 09:14Z: TASK-0023 **APPROVED con reservas** (`6848907151`). Antes, 2026-11-12 ~18:00Z: **TASK-0023** (SEC-01, Issue #2 `6831152066`) READY_FOR_REVIEW: **staging = `270e07d`** (workflow `43274064073`, *fast-forward* desde `71e8a6d`): sección «Role» con los 6 `*_role` reales en solo lectura; respaldo, restauración aislada, 798/798 y validación sintética en vivo PASS; roles reales idénticos ([`tasks/0023-sec01-t21-01-staging-deploy.md`](tasks/0023-sec01-t21-01-staging-deploy.md)). Antes, 17:36Z: TASK-0022 **APPROVED** (`6831144754`); recuperación sin ejecución automatizada (R1 a decisión manual del propietario; R2 intacto). Antes, ~17:05Z: **TASK-0022** (SEC-01, Issue #2 `6829151868`) READY_FOR_REVIEW, **sin desplegar**: F-SEC01-T21-01 corregido en `fix/sec-01-t22-role-permission-key` (TESTED `4bdec36`, REVIEW `270e07d`; suite 798/798) y recuperación de permisos solo evaluada (como máximo 1 vínculo; 0 escrituras reales) ([`tasks/0022-sec01-t21-01-role-section-and-permission-recovery.md`](tasks/0022-sec01-t21-01-role-section-and-permission-recovery.md)). Antes, 16:17Z: TASK-0021 **APPROVED con reservas** (`6829143372`). Antes, ~12:55Z: **TASK-0021** (SEC-01 R3+R4+R4-01, Issue #2 `6823247114`) READY_FOR_REVIEW: **staging = `71e8a6d`** (workflow `43220318614`), solo los commits de R3/R4/R4-01 sobre `9d0c24d`; SEC-03 intacta; gates 1–4 PASS; residual F-SEC01-T21-01 ([`tasks/0021-sec01-r3-r4-r401-staging-deploy.md`](tasks/0021-sec01-r3-r4-r401-staging-deploy.md)). Antes, 11:42Z: **TASK-0020 APPROVED con reservas** (`6822634878`); **sin tarea activa nueva** (STOP). Reservas: verificación del buzón nuevo no ejecutada en staging; origen en el chat; deuda de UX y `Empresa::create` (ver §5). Antes, ~11:25Z: **TASK-0020** (pruebas operativas SEC-03 en staging, instrucción del propietario en el chat) READY_FOR_REVIEW: adversariales y funcionales PASS (salvo abrir el enlace de verificación del buzón nuevo); datos sintéticos borrados, línea base restaurada ([`tasks/0020-sec03-staging-operational-tests.md`](tasks/0020-sec03-staging-operational-tests.md)). Antes, 10:33Z: **TASK-0019 APPROVED** (`6821212610`), limitada a staging; **sin tarea activa nueva** (STOP). Pendientes sin asignar: pruebas de correo/RIF/Turnstile/cambio de correo con buzones sintéticos, prueba adversarial del borde y de los cupos, aumentos de HSTS (sigue en 1 día), riesgo de restauración por la FK preexistente; SEC-01 detenida; producción sin autorización. Antes, ~10:05Z: **TASK-0019** (SEC-03 en staging, Issue #2 `6820224859`) READY_FOR_REVIEW: **staging = `9d0c24d`** (workflow `43196418453`), con respaldo y restauración ensayada; F-SEC03-03 y F-SEC03-06 verificados en staging; HSTS `max-age=86400` solo en staging ([`tasks/0019-sec03-staging-deploy.md`](tasks/0019-sec03-staging-deploy.md)). TASK-0018 **APPROVED** (`6820214362`). Antes, ~09:40Z: **TASK-0018, ciclo de corrección** (revisión `6819392897`, P2/P4/P5) READY_FOR_REVIEW, **sin desplegar**: `fix/sec-03-p1-p5` @ `9d0c24d`, suite completa 767/767; P2 (proxies exactos) y P4 (orden del borrado de tokens) corregidos; P5 sin defecto de código; F-SEC03-03 y -06 pendientes de validación efectiva. Antes, 2026-11-11 ~18:15Z: **TASK-0018** (SEC-03, correcciones P1–P5, Issue #2 `6802857575`) READY_FOR_REVIEW, **sin desplegar**: rama `fix/sec-03-p1-p5` @ `cb3fff4`, suite completa 753/753 en el runner aislado; sin cambios de infraestructura ([`tasks/0018-sec03-p1-p5-remediation.md`](tasks/0018-sec03-p1-p5-remediation.md)). Antes, 17:17Z: **decisiones del propietario sobre la consolidación de pendientes** (Issue #2 `6802438608`), solo registro documental: no asigna implementación, despliegue ni merge ([`PENDIENTES_CONSOLIDADO_2026-11-11.md`](PENDIENTES_CONSOLIDADO_2026-11-11.md), corte `f6e3134` y addendum). Antes, el mismo día: **TASK-0017** (SEC-03, auditoría de recuperación de cuentas, tokens y Host/proxies, Issue #2 `6797800863`) READY_FOR_REVIEW, solo auditoría, sobre staging `b048df0`. Sin toma de cuentas confirmada; hallazgos F-SEC03-01..06, sin corregir ni desplegar ([`tasks/0017-sec03-account-recovery-host-trust-audit.md`](tasks/0017-sec03-account-recovery-host-trust-audit.md)). Antes, el mismo día: **TASK-0016** (análisis IA de solicitudes de categoría, Issue #2 `6791532623`) READY_FOR_REVIEW y desplegada en staging por autorización del propietario (`feature/upgrade-filament-v3` @ `893351f`, run `42999603780`); proveedor, clave, modelo y presupuesto se configuran en el panel; UAT pendiente ([`tasks/0016-category-request-ai-analysis.md`](tasks/0016-category-request-ai-analysis.md)). TASK-0013, TASK-0014 y TASK-0015: ver `current_task.md`. Anterior: 2026-11-03, rama `fix/sec-01-r4-01-role-editor-preserve`: **TASK-0012** (recuperación de acceso por correo y RIF, Issue #2 `6602168914`) desplegada en staging por autorización del propietario; UAT y prueba de correo real pendientes ([`tasks/0012-account-recovery.md`](tasks/0012-account-recovery.md)). Staging `feature/upgrade-filament-v3` @ `06155ce` (workflow `41630703105` success; rutas de login/recuperación GET 200). TASK-0011 APPROVED (`6440840198`) y aplicada previamente en staging. Anterior, sin cambios (2026-10-21 ~16:40Z, desde `f41bcb6`): **F-SEC01-R4-01** (el editor de roles conserva lo que no muestra), lista para revisión, sin desplegar. R4 aceptado; contención verificada.
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
| Staging en ejecución (`feature/upgrade-filament-v3`) | ~~`b048df0`~~ → ~~`9d0c24d`~~ (SEC-03 P1–P5) → ~~`71e8a6d`~~ (+ SEC-01 R3+R4+R4-01) → ~~`270e07d`~~ (+ F-SEC01-T21-01) → **`6e7f525`** (+ F-SEC01-T24-02, migración en la BD compartida) | antes: `git rev-parse HEAD` en el servidor, solo lectura, 2026-11-11; `tasks/0017`. Luego: despliegue de TASK-0019, workflow `43196418453`, verificado en el servidor, 2026-11-12. Ahora: TASK-0021, workflow `43220318614`, verificado en el servidor, 2026-11-12 ~12:43Z. Luego: TASK-0023, workflow `43274064073`, verificado en el servidor, 2026-11-12 ~17:54Z. Luego: TASK-0026, workflow `43419358951`, verificado en el servidor, 2026-11-13 ~11:45Z |
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
- **SEC-01 / TASK-0021 (2026-11-12, `6823247114`):** R3+R4+R4-01 desplegados en staging (`71e8a6d`). Respaldo `/root/backups/sec01-t21-20261112T123419Z` y rollback a la imagen `pre-sec01-9d0c24d`. El editor de roles ya conserva lo que no muestra, así que guardar un rol ya no borra ocultos. Hay que evitar las casillas de la sección «Role» (F-SEC01-T21-01). Siguiente: revisión y UAT; la recuperación de permisos perdidos es una decisión aparte.
- **SEC-01 / TASK-0026 (2026-11-13, `6851565505`):** F-SEC01-T24-02 desplegado en staging (`6e7f525`); respaldo `/root/backups/sec01-t26-20261113T113455Z`, imagen de rollback `pre-t26-270e07d`; rollback de la migración: `migrate:rollback --step=1` solo si el lote 63 sigue siendo el último, o si no, `DOWN_SQL` + fila 149. Pendiente: lecturas de 24 h (`bash <respaldo>/monitor_check.sh` y ejecuciones de n8n) hacia T+4 h, T+12 h y T+24 h; cierre no antes del 2026-11-14 11:43:38Z. Turnstile: dato de UAT (Samsung Galaxy + Chrome) registrado en `current_task.md`, sin tarea. R-06 en espera; F-SEC01-T24-01 abierta; R-04 con su propio gate.
- **SEC-01 / TASK-0025 (2026-11-13, `6850596236`):** candidato de F-SEC01-T24-02 listo para revisión, sin aplicar ([`../security/SEC01_TASK0025_VISTAS_PUBLIC.md`](../security/SEC01_TASK0025_VISTAS_PUBLIC.md)). Staging y la BD compartida, sin cambios. R-06 en espera de esta revisión; F-SEC01-T24-01 abierta (no conceder `delete_user`/`delete_any_user` fuera de `super_admin`); R-04 con su propio gate. Siguiente: revisión y, si se aprueba, gate de despliegue (§7 del documento).
- **SEC-01 / TASK-0024 (2026-11-13, `6848913937` + `6849259532`):** diagnóstico de reservas sin escrituras en staging ([`../security/SEC01_TASK0024_DIAGNOSTICO_RESERVAS.md`](../security/SEC01_TASK0024_DIAGNOSTICO_RESERVAS.md)). Pendiente de decisión: gate de R-04 (2 filas, nota restringida), corrección de F-SEC01-T24-01 (no conceder `delete_user` a roles no privilegiados mientras tanto) y de F-SEC01-T24-02 (BD compartida). Después de la revisión: tarea independiente de R3-02/R3-03 (candidato aislado, sin desplegar). Siguiente: revisión.
- **SEC-01 / TASK-0023 (2026-11-12, `6831152066`):** F-SEC01-T21-01 desplegado en staging (`270e07d`). Respaldo `/root/backups/sec01-t23-20261112T174040Z` y rollback a la imagen `pre-t23-71e8a6d`. Las 6 filas `*_shield::role` siguen inertes, y borrarlas requiere autorización. Siguiente: revisión.
- **SEC-01 / TASK-0022 (2026-11-12, `6829151868`):** candidato `270e07d` sin desplegar (la sección «Role» muestra los `*_role` reales en solo lectura). Staging sigue en `71e8a6d`: hasta desplegarlo, no marcar las casillas `*_shield::role`. Recuperación: los 128 vínculos de octubre siguen faltando; propuesta de como máximo 1 (script revisado o a mano), pendiente de decisión. Siguiente: revisión y gate de despliegue aparte.
- **SEC-03 / TASK-0020 APPROVED con reservas (2026-11-12, `6822634878`):** sin autorización de despliegue, HSTS ni producción; SEC-01 detenida.
- **SEC-03 / TASK-0020 (2026-11-12, chat):** pruebas operativas en staging PASS; F-SEC03-01..05 verificados en staging (detalle en la tarea). Siguiente: revisión; cierre de SEC-03, HSTS y producción, decisiones aparte.
- **SEC-03 / TASK-0019 APPROVED (2026-11-12, `6821212610`):** solo staging. SEC-03 no se cierra; cada pendiente necesita asignación propia (detalle en la tarea, «Veredicto»).
- **SEC-03 / TASK-0019 (2026-11-12, `6820224859`):** desplegado en staging (`9d0c24d`); gates A–D PASS; respaldo en el servidor (`/root/backups/sec03-20261112T095103Z`), con rollback preparado (imagen `pre-sec03-b048df0`). Pendiente: revisión, pruebas de correo con buzones sintéticos, siguientes etapas de HSTS (con aprobación).
- **SEC-03 / TASK-0018, ciclo de corrección (2026-11-12, `6819392897`):** P2/P4/P5 PASS en código (`9d0c24d`), sin desplegar; gates sin cambios (tarea, addendum del 2026-11-12).
- **SEC-03 / TASK-0018 (2026-11-11, `6802857575`):** P1–P5 corregidas en candidato (`cb3fff4`), sin desplegar. La aplicación efectiva en staging requiere autorización de despliegue y las verificaciones de la tarea, §5 (cabeceras por el túnel, «Always Use HTTPS» en Cloudflare, etapas de HSTS). TASK-0017 no cambia.
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
- **Verificación tras cambio de correo (UX, TASK-0020):** la pantalla dice «Su cuenta está creada… avise a la Cámara que se registró», texto de registro nuevo; confunde cuando se cambió el correo de una cuenta existente. Deuda funcional, sin asignar.
- **`Empresa::create` (TASK-0020):** con un usuario autenticado vincula automáticamente a 4 usuarios del personal a la empresa nueva. Pendiente de revisión independiente; no se modificó.
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
