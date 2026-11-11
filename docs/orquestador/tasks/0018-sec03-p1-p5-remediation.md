# TASK-0018 — SEC-03: correcciones P1–P5 (origen canónico, hosts, IP real, Turnstile, cambio de correo, HTTPS/HSTS)

| Campo | Valor |
|---|---|
| ID | `TASK-0018` (siguiente libre: ningún archivo, rama ni commit usaba 0018; verificado en todas las ramas remotas) |
| Fuente | Issue #2, comentario `6802857575` (propietario, 2026-11-11 17:36Z), íntegro al final |
| Autorización de alcance | decisiones del propietario `6802438608`; revisión documental `6802648398`, commit `733d1e5` |
| Relación | implementación **independiente** de la auditoría TASK-0017 (`6797800863`), que no se modifica; TASK-0012 (recuperación) tampoco |
| Estado | **READY_FOR_REVIEW** (2026-11-11). Sin despliegue, sin merge, sin cambios de infraestructura |
| Rama / worktree | `fix/sec-03-p1-p5` (pusheada; no despliega: el workflow solo corre con `feature/upgrade-filament-v3`) / `PerfilAfiliadosCPV-wt-sec03-fix` |
| BASE | staging `b048df09b345bfefc8bbfe88ed0ca8e4ea09df33` (verificado en vivo: `ls-remote` y `git rev-parse HEAD` en el servidor, árbol limpio) + pruebas de la auditoría `8a4ed35df5aafc853ac9e79b33d340a46ea6613c` (la rama parte de ese commit, sin reescribirlo) |
| TESTED_HEAD = REVIEW_HEAD | `cb3fff4ebac09fe8faf7e656c06dbfe8de0be883` (código `93c36f7` + manual `cb3fff4`) |
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
