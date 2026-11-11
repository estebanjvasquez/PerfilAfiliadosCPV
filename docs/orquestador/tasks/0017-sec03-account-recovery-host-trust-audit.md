# TASK-0017 — SEC-03: recuperación de cuentas, tokens y confianza en Host/proxies (auditoría)

| Campo | Valor |
|---|---|
| Identificador | **TASK-0017**. Se verificó libre el 2026-11-11: `tasks/` llega a 0016 en todas las ramas locales y remotas; no aparece en mensajes de commit ni en `task_id` del handoff |
| Fuente | Issue #2, comentario [`6797800863`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6797800863) (propietario `estebanjvasquez`, 2026-11-11 13:58Z). El texto íntegro está al final de este archivo |
| Tipo | Auditoría y pruebas de seguridad. **No** autoriza correcciones, despliegue ni cambios de infraestructura |
| Relación | Ronda SEC-03 (plan `docs/plans/SECURITY_REVIEW_PLAN.md`). Audita lo implementado en TASK-0012 sin cambiar su propósito ni su identificador; no toca SEC-01 ni TASK-0013..0016 |
| Objetivo auditado | `origin/feature/upgrade-filament-v3` @ `b048df09b345bfefc8bbfe88ed0ca8e4ea09df33` (staging; el propietario reportó `b048df0` y se verificó con `git ls-remote` el 2026-11-11) |
| Rama de pruebas | `audit/sec-03-account-recovery`, worktree `C:\Proyectos\GitHub\PerfilAfiliadosCPV-wt-sec03`, desde `b048df0`. Sin *upstream* hacia la rama que despliega |
| Registro | este archivo, `current_task.md`, `SESSION_RESUME.md`, `docs/security/SECURITY_STATUS.md`, el informe en `audit/` y el handoff, en la rama canónica `fix/sec-01-r4-01-role-editor-preserve` |
| Estado | ~~ASSIGNED~~ → IN_PROGRESS → **READY_FOR_REVIEW** (2026-11-11). REVIEW_HEAD de pruebas `8a4ed35` |
| Gates | sin cambios funcionales, sin despliegue, sin merge, sin push a `feature/upgrade-filament-v3`, sin n8n/taxonomía, sin operaciones destructivas, sin rotar secretos, sin pruebas activas contra cuentas reales; cualquier petición a staging que envíe correo, cambie credenciales o manipule cabeceras requiere autorización específica |

La rama canónica tiene cambios previos que se conservan sin commitear: `AGENTS.md`, `CLAUDE.md`,
`docs/orquestador/PROTOCOL.md`, `docs/orquestador/SESSION_RESUME.md` (en parte) y
`docs/uat/RESULTADOS_BUSCADOR_LOTE001.xlsx`, este último sin seguimiento.

## Arranque y estado verificado (2026-11-11 ~14:00Z)

- Rama `fix/sec-01-r4-01-role-editor-preserve` @ `6ef786c`, igual a su remoto. Árbol con los cambios previos citados arriba,
  que no se tocaron.
- Staging: `origin/feature/upgrade-filament-v3` = `b048df0`; el servidor corre `b048df0` (`git rev-parse HEAD` en solo
  lectura). Coincide con lo reportado por el propietario.
- Tarea activa previa: TASK-0016, READY_FOR_REVIEW. TASK-0013/0014/0015 siguen en revisión en sus sesiones. Ninguna bloquea
  esta auditoría, que se aisló en su propio worktree y su propia rama.
- Estado: ASSIGNED → IN_PROGRESS (solo auditoría) → **READY_FOR_REVIEW**.

## Resultado

**No se confirmó toma de cuentas.** El detalle está en el informe
[`audit/SEC03_TASK0017_ACCOUNT_RECOVERY_HOST_TRUST.md`](../../../audit/SEC03_TASK0017_ACCOUNT_RECOVERY_HOST_TRUST.md) y el
registro acumulativo en [`docs/security/SECURITY_STATUS.md`](../../security/SECURITY_STATUS.md) (§3, §4 y §5).

| ID | Estado | Resumen |
|---|---|---|
| F-SEC03-01 | CONFIRMADO en la aplicación; explotación externa NO VERIFICADA (mitigada por el ingreso del túnel) | El host de los enlaces de recuperación y verificación sale de la petición |
| F-SEC03-02 | CONFIRMADO en la aplicación | Se acepta cualquier host: `TrustHosts` desactivado y panel sin dominio |
| F-SEC03-03 | CONFIRMADO en staging (config + logs) y con oráculo | Todos los clientes con la IP del gateway: los límites por IP son globales |
| F-SEC03-04 | CONFIRMADO en aislamiento | Las solicitudes sin Turnstile consumen el cupo por correo y por RIF |
| F-SEC03-05 | CONFIRMADO en aislamiento | Cambiar el correo conserva la verificación |
| F-SEC03-06 | CONFIRMADO en staging (GET inocuos) | HTTP sin redirección ni HSTS |
| Vinculación del token, firma, bloqueo Livewire, hash, uso único, reemplazo, vencimiento, sesiones, RIF por destinatario, `X-Forwarded-Host`, verificación, CSRF, logs, caché, cookie `Secure` | DESCARTADO EN ALCANCE PROBADO | 13 controles verdes |
| Borde de Cloudflare, producción, correo real, tiempos en staging | NO VERIFICADO | Requieren autorización o quedan fuera de alcance |

## Evidencia

| Qué | Valor |
|---|---|
| Rama de pruebas | `audit/sec-03-account-recovery` (pusheada; no despliega), BASE `b048df0` |
| TESTED / REVIEW_HEAD de pruebas | `8a4ed35df5aafc853ac9e79b33d340a46ea6613c` |
| Archivos | `tests/Feature/Security/Sec03AccountRecoveryControlsTest.php` (13), `tests/Feature/Security/Sec03AccountRecoveryOpenFindingsTest.php` (7 oráculos, rojos por diseño) |
| Enfocada | run `20261111T141320Z-8a4ed35df5aa`: 20 tests, 79 aserciones, 13 OK, 7 fallos esperados; staging 200/200 |
| Suite completa | run `20261111T141441Z-8a4ed35df5aa`: **736 tests, 7939 aserciones; 7 fallos, exactamente los 7 oráculos de hallazgos abiertos**; los 729 restantes (las 716 pruebas previas y los 13 controles) OK, sin errores; staging 200 antes y después; sin contenedores `cpvtest` restantes |
| Mutaciones | ninguna en staging ni en la base compartida; sin correo; sin cuentas reales |

## Gates respetados

Sin cambios funcionales, despliegue, merge, push a la rama de despliegue, n8n o taxonomía, ni rotación de secretos. No se
enviaron peticiones con cabeceras manipuladas a staging. Las propuestas P1–P5 esperan una autorización nueva.

---

## Comentario fuente (íntegro, `6797800863`)
# ASIGNACIÓN EXPLÍCITA — Nueva tarea de auditoría SEC-03: recuperación de cuentas, tokens y confianza en Host/proxies

**Propietario:** solicita abrir una tarea NUEVA e independiente. **Prioridad:** alta (posible toma de cuentas, todavía NO demostrada). **Tipo:** auditoría y pruebas de seguridad; NO autorización para implementar correcciones, desplegar ni cambiar configuración de infraestructura. **Estado inicial:** materializar ASSIGNED conforme a protocolo, después IN_PROGRESS solo para auditoría.

## 0. Arranque obligatorio y continuidad
1. Leer `AGENTS.md`, `docs/orquestador/SESSION_RESUME.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/current_task.md`, en la precedencia efectiva; leer **este comentario completo una vez** y solo referencias directas necesarias. No recorrer el hilo de Issue #2, ni history/audit completos, ni hacer onboarding recursivo.
2. Antes de editar: `git status -sb`, `git branch --show-current`, `git rev-parse HEAD`, y remoto. No cambiar de rama, pull ni descartar cambios con árbol sucio. Contrastar estado vivo con checkpoints fechados; el propietario reportó staging `b048df0` (2026-11-11), pero **verificar** SHA real, sin asumir que sigue siendo actual. Aislar en worktree/rama de auditoría si hay tarea activa o trabajo no revisado; preservar SEC-01 y demás tareas.
3. Consultar los identificadores de tarea **versionados** y materializar una tarea NUEVA con el siguiente TASK-000N disponible en `docs/orquestador/tasks/000N-*.md`. No reutilizar TASK-0012 ni SEC-01/SEC-03 anteriores. Actualizar `current_task.md`, `SESSION_RESUME.md` y estado según protocolo sin borrar antecedentes; si una tarea activa bloquea el cambio, STOP y solicitar resolución.
4. Solo abrir referencias dirigidas: `docs/security/SECURITY_STATUS.md`, `docs/plans/SECURITY_REVIEW_PLAN.md`, documentación de TASK-0012 (`docs/orquestador/tasks/0012-account-recovery.md`) y archivos de código pertinentes. No ejecutar loops, polling ni agentes automáticos.

## 1. Hipótesis y evidencia preliminar (NO afirmar vulnerabilidad)
Inspección estática del SHA `b048df0`:
- `app/Http/Middleware/TrustProxies.php` reconoce `Request::HEADER_X_FORWARDED_HOST` y declara `$proxies` sin valor explícito.
- `app/Http/Kernel.php` tiene `TrustHosts` comentado.
- `app/Support/Auth/AccountRecovery.php` crea tokens mediante `Password::broker(...)->createToken($user)` y genera URL mediante `Filament::getPanel('admin')->getResetPasswordUrl($token, $user)`.
- `app/Filament/Pages/Auth/RequestPasswordReset.php` y `RecoverAccessByRif.php` usan Turnstile, rate limits y envío diferido.
- `config/auth.php`: expire=60 minutos y throttle=60 segundos.
Estas observaciones **no prueban** password-reset poisoning ni IDOR de token. Verificar dependencias fijadas, configuración efectiva, TrustHosts, proxies Cloudflare/Nginx y comportamiento en ejecución.

## 2. Alcance de auditoría
A. **Vinculación del token**: probar con cuentas SINTÉTICAS A/B que un token de A no permite cambiar B aun alterando email/username/ID, campos Livewire, parámetros y rutas. Verificar hash, caducidad, invalidación tras uso, reemplazo/limitación de tokens y comportamiento ante reuso, sin revelar tokens en logs.
B. **Host-header poisoning**: verificar en aislamiento `Host`, `X-Forwarded-Host`, y cabeceras de proxy equivalentes; distinguir cliente no confiable, proxy confiable y aplicación; rastrear dominio final de URL de recuperación, notificaciones, verificación de correo y enlaces firmados. Confirmar si la URL proviene de `APP_URL`, request, panel domain o proxy; verificar si un host no autorizado es rechazado y si el enlace enviado usa solo host permitido.
C. **Recuperación por RIF**: verificar aislamiento entre destinatarios, exclusión de cuentas privilegiadas y ambiguas, filtrado de roles, rate limits, máximo de destinatarios, y que cada enlace sea específico de su usuario.
D. **Flujos de identidad relacionados**: cambio de email, verificación de correo, recuperación con correo normalizado/duplicado, invalidación de sesiones tras cambio, protección CSRF, enumeración por respuesta/tiempo, rate limit detrás de proxy y fuga de URLs/tokens en logs, caché o redirecciones.
E. **Configuración real**: verificar (solo lectura) `TrustProxies`, `TrustHosts`, `APP_URL`, `FILAMENT_DOMAIN`, headers Cloudflare/Nginx, versión exacta Laravel/Filament, comportamiento del broker y rutas reales. No imprimir valores secretos.

## 3. Método seguro y límites
1. Empezar por lectura de código y tests unitarios/integración con `Mail::fake`, `Notification::fake`, usuarios y tokens sintéticos, base aislada y captura controlada de enlaces. Escribir primero pruebas que fallen si el control es vulnerable, con oráculos independientes.
2. Si se requiere una prueba HTTP en staging, **solo observación inocua o cuentas sintéticas expresamente autorizadas**. Antes de cualquier solicitud que genere correos reales, cambie credenciales, use dominios externos o manipule cabeceras contra staging, **STOP y pedir autorización específica y ventana de pruebas**. Nunca usar cuentas de clientes, no enviar tokens a servidores externos, no probar producción.
3. No modificar ni rotar credenciales, proxies, DNS, Cloudflare, correo, BD real o código funcional. No desplegar. No publicar tokens, PII, rutas explotables completas ni evidencias sensibles en GitHub público.
4. Usar pruebas acotadas, con límites de solicitudes, sin escáner agresivo, sin fuzzing masivo ni denegación de servicio. Registrar pruebas negativas y positivas, resultado esperado/observado y limitaciones.

## 4. Entregables de auditoría
- Matriz de amenazas por superficie, actor, precondiciones, severidad CVSS cualitativa/estimada (sin exagerar), probabilidad, impacto y evidencia.
- Para cada hipótesis: `CONFIRMADO`, `DESCARTADO EN ALCANCE PROBADO` o `NO VERIFICADO`, con archivo/línea/commit, test y resultado reproducible; distinguir configuración potencialmente peligrosa de explotación real.
- Pruebas automatizadas reproducibles y no destructivas; suite enfocada y regresión pertinente, registrar conteos y HEAD exactos.
- Propuesta de remediación por prioridad, cambios mínimos y tests de regresión, **sin implementar fixes sin nueva autorización**. Si se confirma toma de cuentas, avisar de inmediato al propietario por canal privado adecuado con evidencia saneada y detener pruebas.
- Actualizar `docs/security/SECURITY_STATUS.md` de forma acumulativa y un informe nuevo en `audit/` (sin sobrescribir hallazgos previos). Documentar riesgos residuales y controles de infraestructura por validar.
- Actualizar `docs/orquestador/SESSION_RESUME.md`, `current_task.md` y `audit/orchestrator_handoff.json` con `task_id`, `review_base_sha`, `review_head_sha`, `handoff_commit_sha` diferenciados y pruebas/evidencias según protocolo.

## 5. Gates y cierre
Esta asignación **autoriza solo auditoría aislada y documentación**. No autoriza cambios funcionales, despliegue staging/producción, merge a main, push que despliegue, modificaciones de n8n/taxonomía, operaciones destructivas de DB, rotación de secretos o pruebas activas contra cuentas reales. Respetar bloqueos de permisos sin rodearlos.

Entregar en Issue #2 mediante el mecanismo documentado (`docs/orquestador/post_issue_comment.ps1` cuando proceda). Estado final `READY_FOR_REVIEW`, con HEAD y evidencia; **STOP**. Si el agente no puede comentar, informar bloqueo y dejar checkpoint versionado sin fingir publicación.

**Respuesta inicial esperada:** TASK nuevo materializado, rama, HEAD, alcance y cualquier gate bloqueante. **Respuesta final:** READY_FOR_REVIEW / Issue #2 / HEAD <sha>.

## Addendum 2026-11-11 — decisión del propietario (`6802438608`), solo registro documental


- **Declaración del propietario:** autoriza en alcance desarrollar P1–P5 y probarlas en aislamiento, sin despliegue automático. P1 protege hosts y URL antes de confiar en cabeceras para P2.
- **Para ejecutarse:** hay que materializar o asignar una tarea según `PROTOCOL.md`; esta aprobación no inicia la implementación por sí sola.
- Esta tarea sigue siendo solo de auditoría y está READY_FOR_REVIEW.
- Este addendum no cambia el propósito, el identificador ni el estado técnico de la tarea.
