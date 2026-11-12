# TASK-0018 — SEC-03: correcciones P1–P5 (origen canónico, hosts, IP real, Turnstile, cambio de correo, HTTPS/HSTS)

| Campo | Valor |
|---|---|
| ID | `TASK-0018` (siguiente libre: ningún archivo, rama ni commit usaba 0018; verificado en todas las ramas remotas) |
| Fuente | Issue #2, comentario `6802857575` (propietario, 2026-11-11 17:36Z), íntegro al final |
| Autorización de alcance | decisiones del propietario `6802438608`; revisión documental `6802648398`, commit `733d1e5` |
| Relación | implementación **independiente** de la auditoría TASK-0017 (`6797800863`), que no se modifica; TASK-0012 (recuperación) tampoco |
| Estado | **READY_FOR_REVIEW** (2026-11-11). Sin despliegue, sin merge, sin cambios de infraestructura → 2026-11-12: CORRECTIONS_REQUIRED (`6819392897`) → **READY_FOR_REVIEW** tras el ciclo de corrección (addendum al final) → 2026-11-12: **APPROVED** como implementación aislada (`6820214362`, propietario); F-SEC03-03 y F-SEC03-06 siguen pendientes de verificación operativa. El despliegue es TASK-0019 |
| Rama / worktree | `fix/sec-03-p1-p5` (pusheada; no despliega: el workflow solo corre con `feature/upgrade-filament-v3`) / `PerfilAfiliadosCPV-wt-sec03-fix` |
| BASE | staging `b048df09b345bfefc8bbfe88ed0ca8e4ea09df33` (verificado en vivo: `ls-remote` y `git rev-parse HEAD` en el servidor, árbol limpio) + pruebas de la auditoría `8a4ed35df5aafc853ac9e79b33d340a46ea6613c` (la rama parte de ese commit, sin reescribirlo) |
| TESTED_HEAD = REVIEW_HEAD | `cb3fff4ebac09fe8faf7e656c06dbfe8de0be883` (código `93c36f7` + manual `cb3fff4`) → tras la revisión: **`9d0c24d613fb32d9a0ff91c5cc9d0e4b7d036823`** |
| Pruebas | Sec03 `20261111T180241Z-cb3fff4ebac0`: **37 tests, 170 aserciones, OK**. Suite completa `20261111T180350Z-cb3fff4ebac0`: **753 tests, 8030 aserciones, OK** |

## 1. Arranque y estado vivo (2026-11-11 ~17:40Z)

- Rama canónica `fix/sec-01-r4-01-role-editor-preserve` @ `733d1e5` = remoto. Cambios locales del propietario
  (`AGENTS.md`, `CLAUDE.md`, `PROTOCOL.md`, §0 de `SESSION_RESUME.md`, `docs/uat/RESULTADOS_BUSCADOR_LOTE001.xlsx`)
  preservados: no se descartan ni se commitean.
- `feature/upgrade-filament-v3` = `b048df0` en el remoto y en el servidor. Sin tarea activa incompatible.
- No se cambió de rama: la implementación está en un worktree nuevo.

## 2. Matriz por hallazgo

«Código» = corrección en la rama, probada en aislamiento. «Efectiva» = aplicada al entorno, que **no** ocurrió: no hubo
despliegue ni cambios de infraestructura.

| ID | Antes (TASK-0017, `b048df0`) | Corrección en código (`93c36f7`) | Prueba / evidencia | Efectiva en staging | Riesgo residual |
|---|---|---|---|---|---|
| F-SEC03-01 | Host de los enlaces tomado de la petición | P1: `URL::formatHostUsing` → `CanonicalUrl::formatRoot`: todo `route()`/`url()` (recuperación, verificación, notificaciones) usa la raíz de `APP_URL`; con `APP_URL` https, siempre https. Rutas con dominio propio (p. ej. `FILAMENT_DOMAIN`) conservan su dominio. Fuera de `APP_ENV=local` | Oráculos `f_sec03_01_*` (2), **sin cambios**, ahora verdes. `p1_an_https_app_url_gives_https_links_even_from_a_plain_http_request`, `p1_a_signed_link_built_with_the_canonical_host_is_still_accepted`. Control `a_forged_x_forwarded_host_*` sigue verde | No (sin despliegue) | Producción no inspeccionada: `APP_URL` debe ser el host público real |
| F-SEC03-02 | Se aceptaba cualquier Host | P1: `TrustHosts` activado en el Kernel con hosts **exactos**: host de `APP_URL`, dominios del panel y `APP_TRUSTED_HOSTS`; sin subdominios ni comodines; también rige en pruebas, no en `local` | Oráculo `f_sec03_02_*` verde (404). `p1_the_application_host_is_still_served` (también en mayúsculas), `p1_an_extra_trusted_host_is_served_and_a_lookalike_subdomain_is_not` | No | Si algún cliente legítimo usa otro Host, recibirá 404 (ver §5, compatibilidad) |
| F-SEC03-03 | Todos los clientes con la IP del gateway; límites por IP globales | P2: `TrustProxies` confía solo en `TRUSTED_PROXIES` (por defecto loopback y `172.16.0.0/12`, la red bridge de Docker de este `docker-compose.yml`; `*` rechazado; `none` apaga). Desde un par de confianza, la IP del cliente es `CF-Connecting-IP` (se copia a `X-Forwarded-For` y se descarta lo que traiga el cliente); si falta o no es IP, se queda la del par. Solo se acepta `X-Forwarded-For` (y `-Proto` si se enciende la redirección). **`X-Forwarded-Host` se elimina siempre** (gate técnico: P1 aplicado antes) | Oráculo `f_sec03_03_*` verde. `p2_*` (5): par no confiable ignorado, XFF del cliente ignorado, cabecera inválida, `none`/`*`/vacío, XFH eliminado, petición real por el Kernel | No | Que `CF-Connecting-IP` llegue a PHP por cloudflared y nginx está **NO VERIFICADO** en vivo (§5). Si no llega, el comportamiento queda como antes (IP del gateway), sin empeorar |
| F-SEC03-04 | Pedidos sin Turnstile consumían el cupo por correo y por RIF | P3: orden nuevo en las dos pantallas: cupo por origen → Turnstile → cupo por correo/RIF. Mismos textos visibles | Oráculos `f_sec03_04_*` (2) verdes. `p3_failed_turnstile_still_counts_against_the_origin_limit`, `p3_the_holders_quota_still_limits_valid_requests_with_the_same_answer`; `AccountRecoveryTest` (TASK-0012) verde | No | Mientras F-SEC03-03 no sea efectiva, el cupo por origen sigue siendo compartido |
| F-SEC03-05 | Cambiar el correo conservaba `email_verified_at` | P4: `User::booted()`: si el correo cambia de verdad (no solo mayúsculas/espacios), `email_verified_at = null` aunque la edición intente fijarlo, y se borran los tokens de `password_resets` del correo anterior. El panel pide verificar el buzón nuevo al entrar; la ayuda por RIF no le escribe. Manual actualizado (`cb3fff4`) | Oráculo `f_sec03_05_*` verde. `p4_*` (3) | No | No cierra otras sesiones abiertas (informativo, como en TASK-0017); no envía la verificación automáticamente (el usuario la pide en la pantalla) |
| F-SEC03-06 | HTTP 200 sin redirección; sin HSTS | P5: middleware `EnforceHttps`. HSTS `max-age=86400` (1 día) en respuestas https, **sin** `includeSubDomains` ni `preload`. Redirección a https **apagada** por defecto; encendida, solo redirige si un proxy de confianza dice `X-Forwarded-Proto: http`, al host canónico (301 GET/HEAD, 308 el resto), así que no hay bucle | `p5_*` (3): HSTS presente/ausente/apagado; redirección apagada por defecto; encendida: sin bucle con https, sin cabecera ni con cliente no confiable | No | HTTP sigue respondiendo hasta activar «Always Use HTTPS» en Cloudflare o la redirección (§5). HSTS de 1 día da poca protección: subir por etapas con aprobación |

**Toma de cuentas:** la auditoría no la demostró y estas correcciones no cambian esa conclusión. Las 13 pruebas de
control de vinculación del token siguen verdes.

## 3. Configuración nueva (`config/security.php`; todas con valor por defecto, sin cambios obligatorios en `.env`)

| Variable | Defecto | Efecto |
|---|---|---|
| `APP_CANONICAL_URL` | `true` | P1, enlaces con la raíz de `APP_URL` |
| `APP_TRUSTED_HOSTS_ENFORCE` / `APP_TRUSTED_HOSTS` | `true` / vacío | P1, `TrustHosts`; hosts extra exactos, separados por comas |
| `TRUSTED_PROXIES` | `127.0.0.1,::1,172.16.0.0/12` | P2, pares de confianza; `none` apaga; `*` no se admite |
| `TRUSTED_PROXY_CLIENT_IP_HEADER` | `CF-Connecting-IP` | P2; vacío = `X-Forwarded-For` estándar (despliegue sin Cloudflare) |
| `HTTPS_REDIRECT` | `false` | P5, redirección (requiere verificar `X-Forwarded-Proto` por el túnel) |
| `HSTS_MAX_AGE` | `86400` | P5; `0` apaga. Reversible: con `0` deja de enviarse; los navegadores lo olvidan en ≤1 día |
| `HSTS_INCLUDE_SUBDOMAINS` / `HSTS_PRELOAD` | `false` / `false` | **No activar sin aprobación específica del propietario** |

Ninguna se aplica en `APP_ENV=local`. Tras un despliegue autorizado, el `config:cache` del workflow toma el archivo nuevo.

## 4. Pruebas (runner aislado de Contabo, PostgreSQL desechable, PHP 8.2.35)

| Corrida | SHA | Resultado |
|---|---|---|
| Enfocada, 1.ª (`--filter` mal escapado por SSH) | `93c36f7` | run `20261111T175036Z-93c36f719a33`: **«No tests executed!»**. El script reportó GREEN, pero **no vale como evidencia**; se repitió |
| Enfocada `Sec03\|AccountRecovery\|Auth` | `93c36f7` | run `20261111T175210Z-93c36f719a33`: 84 tests, 494 aserciones, OK |
| `Sec03` | `93c36f7` | run `20261111T175347Z-93c36f719a33`: 37 tests (13 controles + 7 oráculos + 17 nuevas), 170 aserciones, OK |
| Suite completa | `93c36f7` | run `20261111T175456Z-93c36f719a33`: 753 tests, 8030 aserciones, OK |
| `Sec03` | `cb3fff4` | run `20261111T180241Z-cb3fff4ebac0`: 37 tests, 170 aserciones, OK |
| **Suite completa (TESTED_HEAD)** | `cb3fff4` | run `20261111T180350Z-cb3fff4ebac0`: **753 tests, 8030 aserciones, OK** |

En todas, staging 200 antes y después, y sin contenedores `cpvtest` restantes. Comando:
`run_tests.sh <sha> [--filter <patrón>]` con `CPV_TEST_ROOT` fuera del repo (runner `infra/contabo-test-runner` @ `c77f3df`).

- Antecedente histórico (TASK-0017, `8a4ed35`): 736 tests, 7939 aserciones, 7 fallos (los oráculos). Ahora: 736 + 17
  nuevas = 753, sin fallos; los 7 oráculos pasan **sin haber cambiado** (`git diff 8a4ed35 cb3fff4` sobre los dos archivos
  de TASK-0017: vacío).
- `tests/TestCase.php` limpia `Request::setTrustedHosts`/`setTrustedProxies` entre pruebas (estado estático de Symfony
  que en producción no sobrevive a la petición).

## 5. Plan de infraestructura y verificaciones (NO aplicado; cada punto requiere autorización)

1. **Cloudflare (P5, recomendado):** activar «Always Use HTTPS» para `pruebas.camarapetrolera.app`. Es la redirección sin
   riesgo de bucle, porque la hace el borde. HSTS puede quedar en la aplicación (1 día) o pasar al borde; no ambos con
   valores distintos.
2. **Redirección en la aplicación (alternativa a 1):** antes de `HTTPS_REDIRECT=true`, confirmar que por el túnel llega
   `X-Forwarded-Proto` con el esquema del borde (`https` en peticiones https). Si llegara `http` en peticiones https,
   habría bucle: por eso está apagada.
3. **IP real (P2):** tras un despliegue autorizado, comprobar que `CF-Connecting-IP` llega a PHP: dos clientes de redes
   distintas no comparten el límite por IP. Alternativa en nginx (infraestructura): registrar temporalmente la presencia
   de `$http_cf_connecting_ip`, sin la IP, en el log. No se necesita `real_ip` en nginx: lo resuelve la aplicación.
4. **nginx (opcional, defensa en profundidad):** `server_name` explícito con el host de `APP_URL` y un `default_server`
   que responda 444. **No se cambió `docker/nginx/default.conf`**: ese archivo se despliega con el código, y cambiarlo
   sería aplicar infraestructura.
5. **Compatibilidad del Host:** el túnel envía el Host original (sin `httpHostHeader` en el ingreso; verificado en solo
   lectura) y la única ruta a `:8080` es la de staging; sin crons locales; en los logs, 3 URLs con el host canónico y 0
   con `localhost:8080` (conteo agregado). Gate: GET inocuo de `/admin/login` tras el despliegue (un 404 indicaría Host
   distinto; revertir o fijar `APP_TRUSTED_HOSTS`).
6. **HSTS por etapas:** 1 día (por defecto) → 1 semana → 6 meses, cada paso con aprobación; `includeSubDomains` y
   `preload` solo con aprobación específica, por el efecto sobre otros subdominios de `camarapetrolera.app`.
7. **Producción (checklist, sin tocar):** `APP_URL` = host público real; topología de proxies igual a la de staging (si
   no, fijar `TRUSTED_PROXIES` y la cabecera); ingreso del túnel por hostname exacto; HSTS y redirección según 1–2.
8. **Runner (pruebas UX):** el servidor UX del runner sirve en `http://<contenedor>:8000` con `APP_URL` por defecto. Con
   `TrustHosts`, una corrida `--ux-script` sobre esta rama necesita `APP_URL` (o `APP_TRUSTED_HOSTS`) con ese host. No
   afecta a PHPUnit; esta tarea no corrió pruebas UX.

## 6. Criterios de aceptación

- [x] Ningún host no autorizado aparece en enlaces sensibles (oráculos `f_sec03_01_*`, `p1_*`).
- [x] Hosts no autorizados rechazados; el host legítimo y los extra configurados siguen sirviendo.
- [x] `Host`/`X-Forwarded-Host` del cliente no influyen en enlaces; P1 aplicado antes de confiar en cabeceras para P2.
- [x] Límites por IP separados por cliente detrás del proxy confiable, en aislamiento; protecciones por cuenta intactas.
- [x] Turnstile inválido no agota la cuota del destinatario; el cupo por origen y los de destinatario siguen frenando.
- [x] Una dirección nueva no hereda la verificación; la misma dirección la conserva; tokens del correo anterior borrados.
- [x] HSTS sin `includeSubDomains`/`preload`; redirección probada sin bucles y apagada hasta verificar el túnel.
- [x] Tokens A/B, expiración, uso único, reemplazo e invalidación: controles de TASK-0017 verdes.
- [ ] Aplicación efectiva en staging: requiere autorización de despliegue y las verificaciones de §5.

## 7. Archivos cambiados (rama `fix/sec-03-p1-p5`, `8a4ed35..cb3fff4`)

- Nuevos: `config/security.php`, `app/Support/Http/CanonicalUrl.php`, `app/Http/Middleware/EnforceHttps.php`,
  `tests/Feature/Security/Sec03RemediationTest.php`.
- Modificados: `app/Http/Kernel.php`, `app/Http/Middleware/TrustHosts.php`, `app/Http/Middleware/TrustProxies.php`,
  `app/Providers/AppServiceProvider.php`, `app/Models/User.php`, `app/Filament/Pages/Auth/RequestPasswordReset.php`,
  `app/Filament/Pages/Auth/RecoverAccessByRif.php`, `tests/TestCase.php`, `resources/manual/manual_usuario.md`.
- Sin cambios: `docker/nginx/default.conf`, `docker-compose.yml`, workflows, migraciones y las pruebas de TASK-0017.

## 8. Gates pendientes

Revisión humana; merge o integración en `feature/upgrade-filament-v3`; despliegue en staging (con el GET de §5.5); las
verificaciones de §5.2–5.3; Cloudflare «Always Use HTTPS»; etapas de HSTS; producción. Nada de SEC-01 se inició.

---

## Comentario fuente `6802857575` (íntegro)

> # ASIGNACIÓN EXPLÍCITA — NUEVA TAREA SEC-03: correcciones P1–P5
>
> **Proyecto:** CPV — Perfil de Afiliados. **Prioridad:** P0 (seguridad). **Tipo:** implementación independiente de la auditoría TASK-0017. **Autorización de alcance:** decisiones del propietario en `6802438608`; revisión documental aprobada en `6802648398`, commit `733d1e5`. **Esta asignación autoriza materializar una tarea NUEVA e implementar y probar P1–P5 exclusivamente en aislamiento; NO autoriza desplegar en staging o producción.**
>
> ## 0. Arranque, continuidad y materialización
> 1. Leer `AGENTS.md`, `docs/orquestador/SESSION_RESUME.md`, `docs/orquestador/PROTOCOL.md` y `docs/orquestador/current_task.md` siguiendo la precedencia vigente; leer **este comentario una sola vez** y solo sus referencias directas necesarias: `docs/orquestador/tasks/0017-sec03-account-recovery-host-trust-audit.md`, `docs/security/SECURITY_STATUS.md`, `docs/plans/SECURITY_REVIEW_PLAN.md`, `docs/orquestador/tasks/0012-account-recovery.md`, y el informe de auditoría `audit/SEC03_TASK0017_ACCOUNT_RECOVERY_HOST_TRUST.md` únicamente si los permisos de lectura lo permiten.
> 2. Antes de editar, comprobar `git status -sb`, rama, HEAD y remoto. No cambiar de rama ni descartar/pisar trabajo local; el propietario tiene cambios locales en `SESSION_RESUME.md` §0. Verificar el estado vivo, sin inferirlo del checkpoint `b048df0` del 11/11.
> 3. Comprobar el último identificador **versionado** y materializar una **tarea nueva consecutiva** en `docs/orquestador/tasks/` conforme a `PROTOCOL.md`. No reutilizar ni cambiar el propósito de TASK-0017 (auditoría) ni de TASK-0012 (recuperación). Si hay tarea activa incompatible, permisos insuficientes o conflicto de trabajo, STOP y pedir resolución.
> 4. Aislar implementación en worktree/rama que **no dispare despliegues**, preservando la rama canónica `fix/sec-01-r4-01-role-editor-preserve` y la rama runtime `feature/upgrade-filament-v3`. No hacer onboarding recursivo, polling, loops ni barridos generales.
>
> ## 1. Objetivo y alcance de correcciones
> Corregir los seis hallazgos de SEC-03 mediante P1–P5, sin romper autenticación, verificación de email, recuperación por email/RIF, protección CSRF, permisos ni sesiones. La auditoría no demostró toma de cuentas: no exagerar severidad ni atribuir explotación.
>
> **P1 — F-SEC03-01 y 02 (origen canónico/TrustHosts):**
> - Hacer que enlaces sensibles de recuperación, verificación y notificaciones usen exclusivamente origen canónico autorizado; verificar `APP_URL`, configuración Filament y `TrustHosts`.
> - Rechazar hosts no autorizados; impedir influencia de `Host` o `X-Forwarded-Host` controlados por clientes sobre enlaces. Compatibilidad con dominios/rutas legítimos.
> - Criterio: ningún host no autorizado aparece en enlaces sensibles.
>
> **P2 — F-SEC03-03 (IP real y límites):**
> - Diagnosticar la cadena Cloudflare → túnel → Nginx → Laravel y distinguir proxies confiables de cabeceras del cliente.
> - Corregir identificación de IP para que los límites por IP no sean globales entre clientes, manteniendo protecciones por cuenta y antiabuso.
> - **Gate técnico:** P1 debe estar aplicado antes de confiar en cabeceras adicionales para P2, o se debe neutralizar `X-Forwarded-Host` no confiable. Nunca confiar indiscriminadamente en `X-Forwarded-For`/cabeceras de cliente.
> - Cualquier modificación real de Cloudflare, Nginx, túneles o infraestructura compartida requiere autorización adicional: preparar plan/config aislada, NO aplicarla.
>
> **P3 — F-SEC03-04 (Turnstile y cuotas):**
> - Validar Turnstile antes de consumir cuotas de recuperación por correo o RIF; conservar protecciones anti-enumeración y antiabuso.
> - Criterio: Turnstile inválido no agota la cuota del destinatario.
>
> **P4 — F-SEC03-05 (cambio de email):**
> - Al cambiar realmente el correo, invalidar estado de verificación y tokens/procesos pendientes pertinentes; requerir nueva verificación.
> - Si el correo no cambia, preservar verificación válida.
> - Criterio: una dirección nueva no hereda verificación anterior.
>
> **P5 — F-SEC03-06 (HTTPS/HSTS):**
> - Diseñar y probar en aislamiento redirección HTTP→HTTPS y HSTS compatible con el proxy/túnel; sin bucles.
> - Evaluar duración, subdominios y reversibilidad. No activar `includeSubDomains` ni `preload` sin aprobación específica.
> - Si exige cambios de infraestructura compartida, dejar propuesta y evidencia, NO ejecutarlos.
>
> ## 2. Método de prueba y criterios de aceptación
> - Reutilizar oráculos y controles de TASK-0017 como base, con pruebas sintéticas independientes y reproducibles. En auditoría: 13 controles verdes, 7 oráculos rojos por diseño, 736 tests/7939 aserciones con exactamente esos 7 fallos; estos son **antecedentes históricos**, no resultados actuales.
> - Probar tokens vinculados a cuenta A/B, expiración, uso único, reemplazo e invalidación; hosts y cabeceras manipuladas **solo en aislamiento**; límites por IP tras proxies confiables; Turnstile/correo/RIF; cambio de email; HTTPS/HSTS y compatibilidad con Filament/Laravel.
> - Ejecutar pruebas enfocadas y suite completa en entorno aislado. Registrar comando, HEAD, conteos, resultados y cualquier fallo residual; los oráculos correspondientes deben pasar cuando el control se corrija. No falsear pruebas verdes, no eliminar oráculos para ocultar hallazgos.
> - Sin cuentas reales, correos a clientes, tokens reales ni pruebas activas contra staging o producción. Si una comprobación requiere cabeceras manipuladas en staging, envío real de correo, pruebas de infraestructura o ventana especial: **STOP y pedir autorización específica**.
>
> ## 3. Límites y gates humanos
> NO merge a `main`, NO push a rama con despliegue, NO deploy staging/producción, NO n8n, NO cambios reales de DNS/Cloudflare/proxies/infraestructura, NO rotaciones de secretos, NO cambios de taxonomía ni DB real/destructiva, NO exposición de secretos/PII/tokens en GitHub público. No iniciar SEC-01: es una asignación posterior e independiente, aún no activada.
>
> ## 4. Entregables y cierre
> - Archivo de nueva tarea con ID consecutivo real, alcance, estado, referencias y criterios de aceptación.
> - Cambios mínimos P1–P5; matriz por F-SEC03-01..06: estado anterior, corrección, prueba/evidencia y riesgo residual (separar corrección en código de aplicación efectiva en infraestructura).
> - Pruebas enfocadas y suite completa con SHAs verificables; plan de configuración de infraestructura si corresponde.
> - Actualizar de forma acumulativa `docs/security/SECURITY_STATUS.md`, `docs/orquestador/current_task.md` y `SESSION_RESUME.md` sin borrar antecedentes. Actualizar `audit/orchestrator_handoff.json` **solo si las reglas vigentes autorizan su escritura**; si no, documentar bloqueo, no eludirlo. Diferenciar `review_base_sha`, `review_head_sha`, `handoff_commit_sha` según protocolo.
> - Entregar mediante comentario en Issue #2 si el mecanismo autorizado funciona; si no, reportar bloqueo sin afirmar publicación. Incluir tarea, rama, BASE, TESTED_HEAD, REVIEW_HEAD, pruebas, riesgos residuales, gates pendientes y archivos cambiados.
> - Al alcanzar `READY_FOR_REVIEW`: **STOP**. Esperar revisión y aprobación humana. No comenzar SEC-01 automáticamente.
>
> **Respuesta inicial esperada:** ID materializado, rama/worktree, HEAD y bloqueos. **Respuesta final:** `READY_FOR_REVIEW / Issue #2 / HEAD <sha>` con evidencia enlazada.

## Addendum 2026-11-12: ciclo de corrección `6819392897` (CORRECTIONS_REQUIRED → READY_FOR_REVIEW)

Revisión del propietario (2026-11-12 09:04Z) sobre la entrega `6803731840`: verificación y correcciones mínimas de P2,
P4 y P5, en la misma tarea, solo en aislamiento. Lo anterior de este archivo se conserva como antecedente.

| Campo | Valor |
|---|---|
| Estado | `READY_FOR_REVIEW` → `CORRECTIONS_REQUIRED` / `IN_PROGRESS` (`6819392897`) → **`READY_FOR_REVIEW`** (2026-11-12) |
| Rama | `fix/sec-03-p1-p5` (misma; no despliega). Verificado al empezar: local = remoto = `cb3fff4`; `feature/upgrade-filament-v3` = `b048df0` |
| BASE del ciclo | `cb3fff4ebac09fe8faf7e656c06dbfe8de0be883` (la entrega revisada); BASE de la tarea sin cambios: `b048df0` + `8a4ed35` |
| Commits del ciclo | `22800c1` (correcciones P2/P4 + 14 pruebas de revisión), `9d0c24d` (arreglo de una prueba de P5) |
| TESTED_HEAD = REVIEW_HEAD | `9d0c24d613fb32d9a0ff91c5cc9d0e4b7d036823` |

### A. Matriz de la revisión

| Punto | Veredicto | Defecto demostrado y corrección | Evidencia |
|---|---|---|---|
| **P2** | **PASS** en código; tránsito real de cabeceras **NO VERIFICADO** (gate conservado) | **Sí.** El valor por defecto `172.16.0.0/12` confiaba en las redes de otros sitios del mismo host: en solo lectura hay 4 redes bridge (`172.17`, `.18`, `.19` y `.20`, con 5 contenedores ajenos). PHP solo ve llegar el tráfico desde **un** par: el gateway de `perfilafiliados_default`. Corrección: `TRUSTED_PROXIES` por defecto = `127.0.0.1,::1,172.18.0.1` (lista exacta). Con el código anterior la prueba `review_p2_by_default_only_the_exact_gateway_*` **falla**; con la corrección, pasa | 6 pruebas `review_p2_*` (§C). Demostración con el código anterior: run `20261112T092358Z-a45af63e14a3` |
| **P4** | **PASS** | **Sí.** Los tokens del correo anterior se borraban en `updated`, después del `UPDATE`: si el borrado fallaba fuera de una transacción, el correo quedaba cambiado y los tokens viejos vivos. Corrección: borrar en `updating`, antes del `UPDATE`, que es el orden seguro ante fallos. Un rollback revierte las dos cosas. Con el código anterior la prueba `review_p4_a_failed_token_cleanup_*` **falla** (mismo run) | 4 pruebas `review_p4_*`. Sin escritores que salten los eventos: grep de `DB::table('users')`, `saveQuietly`, `withoutEvents`, `upsert` y `->update()` sobre `User`, sin resultados; el formulario real (`EditUser`) se probó |
| **P5** | **PASS de código**; F-SEC03-06 sigue **pendiente en parte** (HTTPS efectivo en el borde) | No se encontró defecto de código. Orden de middleware `TrustHosts` → `TrustProxies` → `EnforceHttps`; `X-Forwarded-Proto` solo cuenta desde pares de confianza, y solo con la redirección encendida. Un fallo de prueba en el ciclo fue **de la prueba**: `call()` no aplica `withHeaders()`. Se corrigió en `9d0c24d`, sin tocar el código | 4 pruebas `review_p5_*` |

Ningún hallazgo se marca cerrado. F-SEC03-03 queda pendiente de validar con cabeceras reales y F-SEC03-06 de HTTPS
efectivo; los demás, «corregido en candidato».

### B. Cadena Cloudflare → cloudflared → nginx → PHP (inspección de solo lectura, 2026-11-12)

| Salto | Observado | Qué llega a PHP |
|---|---|---|
| Cloudflare (borde) | No inspeccionado (sin acceso a la cuenta; sin pruebas activas) | Según la documentación de Cloudflare, el borde pone `CF-Connecting-IP`, añade a `X-Forwarded-For` y fija `X-Forwarded-Proto`. **NO VERIFICADO en vivo** |
| cloudflared (systemd en el host) | Ingreso por hostname exacto → `http://localhost:8080`, *catch-all* `http_status:404`; sin `httpHostHeader` (conserva el Host original) | Las cabeceras del borde; reenvío no verificado en vivo |
| docker-proxy | `127.0.0.1:8080` → nginx:80; `userland-proxy` por defecto | El origen TCP que ve nginx es el gateway de `perfilafiliados_default` |
| nginx (contenedor) | `server_name _`, `fastcgi_param HTTPS on`; sin `real_ip`, `proxy_set_header` ni `fastcgi_param HTTP_*` (0 coincidencias) | `REMOTE_ADDR` = gateway; las cabeceras del cliente pasan como `HTTP_*` (por defecto en fastcgi) |
| PHP/Laravel | `TrustHosts` → `TrustProxies` → `EnforceHttps` | Desde el gateway: `CF-Connecting-IP` → IP del cliente; `X-Forwarded-Host` descartado; `X-Forwarded-Proto` ignorado mientras la redirección esté apagada |

La red `perfilafiliados_default` tiene solo 2 contenedores (app y nginx). Las otras redes del host no pueden fijar la IP.
**Gate:** confirmar el tránsito de `CF-Connecting-IP` y `X-Forwarded-Proto` requiere desplegar y observar, o registrar
temporalmente su presencia en nginx (infraestructura). Ambos necesitan autorización específica.

### C. Pruebas nuevas de la revisión (14, en `Sec03RemediationTest`, prefijo `review_`)

- P2:
  - solo el gateway exacto es de confianza: otras redes de Docker, otro contenedor y rangos privados no lo son;
  - un `CF-Connecting-IP` con lista, en blanco, con puerto, fuera de rango o inyectado deja la IP del par; IPv6 sí;
  - `X-Forwarded-For` estándar se lee de derecha a izquierda, y solo desde un par de confianza;
  - `X-Forwarded-Proto`, `-Port`, `-Prefix` y `-Host`, y `Forwarded` (RFC 7239), desde un par de confianza, no cambian
    esquema, host, puerto, ruta base ni enlaces;
  - desde un par no confiable ninguna cabecera cambia nada, ni con la redirección encendida;
  - dos clientes detrás del gateway ya no comparten el cupo por origen.
- P4:
  - un fallo al borrar los tokens deja la cuenta intacta;
  - un rollback restaura el correo, la verificación y los tokens;
  - un token emitido antes del cambio no sirve ni con el correo viejo (`INVALID_USER`) ni con el nuevo (`INVALID_TOKEN`);
  - el formulario real de Usuarios anula la verificación.
- P5:
  - 301 para GET y HEAD, y 308 para POST, PUT, PATCH y DELETE, conservando ruta y *query*, sin HSTS en la respuesta http;
  - el destino es el host canónico aunque la petición venga por otro host de confianza o con `X-Forwarded-Host`;
  - al seguir la redirección se llega a https con HSTS; un `X-Forwarded-Proto: http, https` ambiguo o de un cliente no
    confiable no redirige ni da HSTS;
  - `includeSubDomains` y `preload` solo aparecen si se activan a propósito.

Las 17 pruebas de la entrega anterior, los 13 controles y los **7 oráculos de TASK-0017 no se modificaron**: `git diff
cb3fff4 9d0c24d` no toca los dos archivos de TASK-0017, y en `Sec03RemediationTest` solo hay líneas añadidas.

### D. Corridas (runner aislado `infra/contabo-test-runner` @ `c77f3df`, PostgreSQL desechable, PHP 8.2.35)

Comando: `run_tests.sh <sha> [--filter <patrón>]`, enviado como script por `scp` y verificado con la línea `OK (N tests…)`
de `artifacts/<run>/phpunit.txt`.

| Corrida | SHA | Resultado |
|---|---|---|
| `Sec03` | `22800c1` | run `20261112T091141Z-22800c15b35e`: 51 tests, 225 aserciones, **1 fallo**: `review_p5_the_redirect_keeps_path_*`, porque la prueba usaba `call()` con `withHeaders()`. Corregido en la prueba |
| Demostración con el código anterior (rama temporal `tmp/sec03-review-before` = pruebas de `9d0c24d` + `User.php` y `config/security.php` de `cb3fff4`; **borrada** después) | `a45af63` | run `20261112T092358Z-a45af63e14a3`: 10 tests, **2 fallos esperados** (`review_p2_by_default_*`, `review_p4_a_failed_token_cleanup_*`); las otras 8 pasan |
| `Sec03` | `9d0c24d` | run `20261112T091358Z-9d0c24d613fb`: **51 tests, 242 aserciones, OK** (13 controles + 7 oráculos + 31 de remediación) |
| Enfocada `Sec03\|AccountRecovery\|Auth\|AccountWrite\|AccountPrivilege` | `9d0c24d` | run `20261112T091515Z-9d0c24d613fb`: **111 tests, 620 aserciones, OK** |
| **Suite completa (TESTED_HEAD)** | `9d0c24d` | run `20261112T091647Z-9d0c24d613fb`: **767 tests, 8102 aserciones, OK** (753 + 14) |

En todas: staging 200 antes y después; sin contenedores `cpvtest` restantes; sin advertencias, omisiones ni errores en la
salida de PHPUnit.

### E. Diff del ciclo (`cb3fff4..9d0c24d`)

`config/security.php` (lista exacta de proxies y su porqué), `app/Http/Middleware/TrustProxies.php` (comentario),
`app/Models/User.php` (borrado de tokens en `updating`) y `tests/Feature/Security/Sec03RemediationTest.php` (+14
pruebas). Sin cambios de infraestructura, workflows ni migraciones.

### F. Riesgos residuales y gates (sin cambios de alcance)

- **P2:**
  - tránsito real de `CF-Connecting-IP`: NO VERIFICADO;
  - si la red de Docker se recrea con otro gateway, o en otro servidor, la IP vuelve a ser la del gateway. Es seguro, pero
    no separa a los clientes hasta fijar `TRUSTED_PROXIES`. Comprobarlo tras cada recreación de la red y en producción.
- **P4:** un escritor futuro que salte los eventos de Eloquent no estaría cubierto; hoy no existe ninguno.
- **P5:** HTTP sigue respondiendo hasta activar «Always Use HTTPS» en Cloudflare o la redirección, después de verificar
  `X-Forwarded-Proto`. Revertir HSTS tarda hasta `max-age` (1 día).
- **Gates:** revisión humana; despliegue en staging con un GET de `/admin/login` después; verificación de cabeceras;
  Cloudflare; etapas de HSTS; producción. SEC-01 no se inició.

---

## Comentario de revisión `6819392897` (íntegro)

> # CORRECTIONS_REQUIRED — TASK-0018 / SEC-03: revisión técnica acotada P2, P4 y P5
>
> **Decisión de revisión sobre la entrega** [6803731840](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6803731840): se solicita una **corrección/verificación limitada**, sin ampliar el propósito de TASK-0018. Código entregado y probado: `cb3fff4ebac09fe8faf7e656c06dbfe8de0be883`; handoff: `78e0c887ce05c37fdec4e5727882d65d49ecb4c8`. Rama `fix/sec-03-p1-p5`. La revisión previa identificó tres puntos pendientes. **Autorización explícita:** reabrir TASK-0018 de `READY_FOR_REVIEW` a `CORRECTIONS_REQUIRED` / `IN_PROGRESS` para revisión técnica y correcciones mínimas de P2/P4/P5, exclusivamente en aislamiento. No crear una tarea nueva ni cerrar TASK-0018 automáticamente. Los resultados declarados (37/37 SEC-03, 753 tests y 8030 aserciones) son antecedentes, no sustituyen esta revisión.
>
> ## 0. Arranque y gobierno
> 1. Leer `AGENTS.md`, `docs/orquestador/SESSION_RESUME.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/current_task.md` y **solo** la tarea activa `docs/orquestador/tasks/0018-sec03-p1-p5-remediation.md` y referencias directas necesarias. Leer este comentario completo una vez, sin recorrer el hilo.
> 2. Antes de editar: `git status -sb`, rama, HEAD y remoto. Preservar cambios locales (incluida §0 de SESSION_RESUME), sin descartes, pulls ni cambios de rama sobre árbol sucio; usar worktree aislado cuando corresponda. Confirmar que se trabaja sobre el candidato `fix/sec-03-p1-p5` y contrastar el estado real con los checkpoints.
> 3. Mantener TASK-0018 como **misma tarea**, registrando el ciclo de corrección sin borrar la auditoría TASK-0017 ni los antecedentes. No activar SEC-01, loops, polling o agente autónomo.
>
> ## 1. P2 — IP real, proxy y cabeceras
> Archivos pertinentes: `app/Http/Middleware/TrustProxies.php`, `config/security.php`, `app/Http/Kernel.php`, tests SEC-03.
> - Verificar que `CF-Connecting-IP` solo se acepta desde un par efectivamente confiable; revisar especialmente si el CIDR por defecto `172.16.0.0/12` confía indebidamente en otros contenedores o clientes. Preferir topología y proxies explícitos verificados, sin confiar en `*`.
> - Probar en **aislamiento** cabeceras `CF-Connecting-IP`, `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Proto`: pares confiables/no confiables, ausentes, inválidas, múltiples y suplantación. Confirmar que ninguna cabecera no confiable cambia la IP efectiva o el origen de enlaces.
> - Reconstruir documentalmente Cloudflare → cloudflared → Nginx → PHP/Laravel, distinguiendo código de cabeceras que llegan realmente a PHP. Inspección de infraestructura **solo lectura**. Si requiere pruebas activas en staging, STOP y pedir autorización específica.
> **PASS:** límites no compartidos por todos los clientes cuando la cadena entrega IP válida, ausencia de spoofing desde pares no confiables, lista de proxies precisa; si no puede verificarse el tránsito real, marcarlo `NO VERIFICADO` y conservar el gate.
>
> ## 2. P4 — cambio de correo, tokens y atomicidad
> Archivo principal `app/Models/User.php`; rutas de edición y broker relacionados.
> - Revisar semántica real de Eloquent `updating`/`updated`, `isDirty`/`wasChanged`/`getOriginal` y normalización del correo.
> - Probar que cambio real invalida `email_verified_at` y tokens del correo anterior; cambio solo de mayúsculas/espacios no altera verificación; enlaces previos no sirven.
> - Probar fallos al borrar tokens, transacciones/rollback y actualizaciones por rutas que no disparan eventos Eloquent. Evitar inconsistencias entre usuario y tokens. Corregir solo si se demuestra defecto, sin refactorización general.
> **PASS:** invariantes de verificación y tokens, incluso ante fallo/rollback, sustentadas en tests negativos y positivos.
>
> ## 3. P5 — HTTPS, HSTS y proxies
> Archivos `EnforceHttps.php`, `CanonicalUrl.php`, `config/security.php`, `TrustProxies.php`.
> - En aislamiento, verificar HTTP→HTTPS sin bucles; conservación de ruta/query; códigos de redirección para GET/HEAD y otros métodos; destino canónico no manipulable por Host.
> - Confirmar que `X-Forwarded-Proto` influye solo desde proxies confiables; revisar interacción de `isSecure()`, `isFromTrustedProxy()` y orden de middleware.
> - Probar HSTS solo en respuestas HTTPS, `max-age` esperado, `includeSubDomains` y `preload` desactivados, y reversibilidad.
> **PASS de código** no equivale a cierre operativo: F-SEC03-06 sigue parcialmente pendiente mientras no se verifiquen borde/túnel y HTTPS efectivo. No activar redirección, Cloudflare Always Use HTTPS, `includeSubDomains` ni `preload` en infraestructura.
>
> ## 4. Pruebas, documentación y resultado
> - Ejecutar pruebas enfocadas P2/P4/P5, los **37 controles SEC-03** incluidos los siete oráculos originales sin alterarlos para ocultar fallos, y la **suite completa** en runner/BD aislados. Registrar comandos, SHAs, conteos, aserciones, errores, advertencias, omisiones y resultados; corregir solo defectos demostrados y repetir regresión.
> - Entregar matriz `P2/P4/P5 = PASS / FAIL / BLOCKED`, evidencias reproducibles, diff, riesgos residuales y gates de infraestructura/staging. Mantener `F-SEC03-03` como pendiente de validación efectiva de cabeceras y `F-SEC03-06` como pendiente de HTTPS efectivo; **no marcar hallazgos como cerrados por pruebas aisladas**.
> - Actualizar `docs/security/SECURITY_STATUS.md`, `docs/orquestador/current_task.md`, `SESSION_RESUME.md` y TASK-0018 acumulativamente, preservando historia. Handoff `audit/orchestrator_handoff.json` **solo si está permitido por reglas vigentes**, sin sortear restricciones. Diferenciar BASE, TESTED_HEAD, REVIEW_HEAD y handoff SHA.
> - Publicar resultado en Issue #2 mediante mecanismo autorizado. Proponer `READY_FOR_REVIEW` para aprobación/cierre de la **implementación aislada** cuando las pruebas estén verdes y no queden defectos críticos/altos de código; si hay bloqueo, declararlo. **STOP** tras la entrega; no pasar a `APPROVED` ni `CLOSED` por cuenta propia. El cierre requiere veredicto humano separado.
>
> ## 5. Prohibiciones
> No desplegar en staging ni producción, no merge a `main`, no push a rama con deploy automático, no modificar Cloudflare/nginx/túneles/n8n, secretos, datos reales, taxonomía ni operaciones destructivas; no enviar correos a clientes ni pruebas de cabeceras manipuladas a staging. No iniciar SEC-01. Si se descubre riesgo crítico, detener las pruebas afectadas y reportar evidencia saneada por canal privado.
>
> **Respuesta inicial:** estado de TASK-0018, rama, HEAD y bloqueos. **Respuesta final:** `READY_FOR_REVIEW / Issue #2 / HEAD <sha>`, con matriz P2/P4/P5 y pruebas enlazadas.
