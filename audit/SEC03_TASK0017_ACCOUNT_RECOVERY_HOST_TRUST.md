# SEC-03 / TASK-0017 — Recuperación de cuentas, tokens y confianza en Host/proxies

**Fecha:** 2026-11-11. **Asignación:** Issue #2, comentario `6797800863` (propietario, 13:58Z).
**Tipo:** auditoría aislada. **No** se implementó ninguna corrección, no se desplegó nada y no se cambió configuración.
**Objetivo auditado:** `origin/feature/upgrade-filament-v3` @ `b048df09b345bfefc8bbfe88ed0ca8e4ea09df33` (staging; verificado
con `git ls-remote` y con `git rev-parse HEAD` en `<LIVE_APP_DIR>`, solo lectura).
**Pruebas:** rama `audit/sec-03-account-recovery` (solo pruebas, sobre `b048df0`), TESTED `8a4ed35df5aafc853ac9e79b33d340a46ea6613c`.
**Versiones fijadas (`composer.lock`, confirmadas en el contenedor):** Laravel `v12.69.0`, Filament `v3.3.55`,
Livewire `v3.8.7`, symfony/http-foundation `v7.4.18`; PHP 8.2.35 en el runner.

Este repositorio es público: el informe describe cada hallazgo sin pasos de explotación. No contiene tokens, correos
reales, PII ni secretos. Ninguna prueba se dirigió a cuentas reales ni a producción.

## 1. Resumen

- **No se confirmó toma de cuentas.** El token de recuperación queda ligado a su cuenta por cuatro controles que se
  probaron uno a uno: firma del enlace, propiedades bloqueadas de Livewire, registro por cuenta en el *broker* y hash.
  Un token de A no cambia la contraseña de B por ninguna vía probada.
- **Confirmado en la aplicación (F-SEC03-01/02):** el host de los enlaces de recuperación y verificación sale de la
  petición. No hay `TrustHosts`, ni `FILAMENT_DOMAIN`, ni raíz de URL forzada. **No es explotable por la ruta pública
  observada**: el túnel de Cloudflare solo entrega a la aplicación el hostname de staging, y el origen no expone HTTP. Es
  una defensa en profundidad pendiente, y un riesgo real si cambia la infraestructura o en producción, que no se verificó.
- **Confirmado en staging (F-SEC03-03):** la aplicación ve a **todos** los clientes con una sola IP, la del gateway de
  Docker. Los límites «por IP» de login, recuperación y restablecimiento son, en la práctica, límites globales.
- **Confirmado en aislamiento (F-SEC03-04):** las solicitudes que no pasan Turnstile consumen igual el cupo por correo y
  por RIF de la víctima.
- **Confirmado en aislamiento (F-SEC03-05):** cambiar el correo de una cuenta conserva `email_verified_at`. El buzón
  nuevo hereda la verificación y la elegibilidad por RIF.
- **Confirmado en staging (F-SEC03-06):** la aplicación responde por HTTP sin redirigir a HTTPS y sin HSTS.

## 2. Matriz de amenazas

Severidad estimada y cualitativa, con vector CVSS 3.1 orientativo. No es una puntuación oficial. Una configuración
peligrosa no equivale a una explotación demostrada.

| ID | Superficie | Actor / precondiciones | Severidad estimada | Probabilidad hoy | Impacto | Estado | Evidencia |
|---|---|---|---|---|---|---|---|
| F-SEC03-01 | Host de los enlaces de recuperación y verificación | Quien logre que la aplicación reciba otro `Host`, más un clic de la víctima | **Media** como defensa en profundidad. Si el borde lo permitiera: Alta (≈ AV:N/AC:H/PR:N/UI:R/C:H/I:H ≈ 6.8) | Baja en staging: el túnel enruta solo el hostname exacto | Toma de cuenta si el enlace llega con otro dominio | **CONFIRMADO en la aplicación**; explotación externa **NO VERIFICADA** (mitigada por el ingreso, verificado en la config) | `OpenFindings::f_sec03_01_*` (2 oráculos); sonda de config §4 |
| F-SEC03-02 | Aceptación de cualquier `Host` | Igual que 01 | Media (misma causa que 01) | Baja en staging | Base de 01; caché o enlaces envenenados | **CONFIRMADO en la aplicación** (200 con host ajeno) | `OpenFindings::f_sec03_02_*`; `Kernel.php:17`; `FILAMENT_DOMAIN` sin definir |
| F-SEC03-03 | IP del cliente detrás de cloudflared y nginx | Cualquier anónimo | **Media**, disponibilidad (≈ AV:N/AC:L/PR:N/UI:N/A:L ≈ 5.3) | Alta: sin requisitos | Pocas peticiones bloquean el login (5/min), la recuperación (5/15 min por pantalla) y el restablecimiento (2/min) **para todos** | **CONFIRMADO** (config + logs agregados + oráculo); el bloqueo no se ejerció contra staging | `OpenFindings::f_sec03_03_*`; 503/503 peticiones de 24 h desde una sola dirección privada |
| F-SEC03-04 | Cupos por correo y por RIF | Anónimo que conoce el correo o el RIF | **Baja–media**, disponibilidad dirigida (≈ A:L ≈ 4.3–5.3) | Media | Impide 1 h, renovable, que esa persona recupere su acceso | **CONFIRMADO en aislamiento** | `OpenFindings::f_sec03_04_*` (correo y RIF) |
| F-SEC03-05 | Cambio de correo de una cuenta | Actor con gestión de usuarios (privilegiado), o un error al tipear | **Baja** | Baja | El buzón nuevo, sin verificar, cuenta como verificado y recibe enlaces por RIF | **CONFIRMADO en aislamiento** (modelo; el escritor es `UserResource`) | `OpenFindings::f_sec03_05_*`; relacionado con F-SEC01-07 |
| F-SEC03-06 | Transporte | Atacante en la red de la víctima | **Baja–media** (≈ AV:A/AC:H/UI:R) | Baja | Página de login servida en claro; posible *SSL stripping*. Las cookies ya llevan `Secure` | **CONFIRMADO en staging** (GET inocuo) | §4, observaciones HTTP |

## 3. Hipótesis descartadas en el alcance probado (controles que se mantienen)

Todos son oráculos en `tests/Feature/Security/Sec03AccountRecoveryControlsTest.php`, que falla si el control se rompe.

| Hipótesis | Resultado | Prueba |
|---|---|---|
| A. El token de A cambia la contraseña de B al enviar el correo de B, con mayúsculas o espacios | DESCARTADO: el *broker* valida contra el registro de B | `a_token_issued_for_a_never_changes_the_password_of_b_*` |
| A. Alterar `email` o `token` desde el navegador (Livewire) | DESCARTADO: `#[Locked]` en `ResetPassword` (Filament 3.3.55) | `the_email_and_token_of_the_reset_page_cannot_be_changed_*` |
| A. Enlace con otro correo o sin firma | DESCARTADO: middleware `signed`, 403 | `the_reset_link_is_signed_*` |
| A. Token en claro en la base, reutilizable, no reemplazado o sin vencimiento | DESCARTADO: hash, un solo uso (se borra), un token vivo por cuenta, vence a los 60 min | `the_token_is_stored_only_as_a_hash_*`, `a_used_token_cannot_be_replayed` |
| D. Sesiones abiertas con la clave vieja o «recordarme» siguen vivas | DESCARTADO: `remember_token` rotado; `AuthenticateSession` cierra la sesión vieja | `a_reset_rotates_the_remember_token_*` |
| D. Cuentas con el mismo correo en otras mayúsculas | DESCARTADO: ninguna recibe enlace; el token de una no sirve a la otra | `accounts_whose_emails_differ_only_by_case_*` |
| C. Un destinatario por RIF usa el enlace de otro; se incluyen cuentas privilegiadas | DESCARTADO: enlace por cuenta; super_admin excluido. El resto de R3 ya lo cubre TASK-0012 | `each_rif_recipient_gets_a_link_bound_*` |
| B. `X-Forwarded-Host`, `-Proto` o `-Port` de un cliente llegan al enlace | DESCARTADO hoy: `TrustProxies` sin proxies confiables, y la sonda en el contenedor da lo mismo. **Ojo:** ver §5, P2 | `a_forged_x_forwarded_host_*` |
| D. Un enlace de verificación verifica otra cuenta, o sigue valiendo tras cambiar el correo | DESCARTADO: 403 | `a_verification_link_only_verifies_its_own_account_*` |
| D. CSRF o sesión ausentes en login, recuperación y reset; excepciones de CSRF | DESCARTADO | `the_recovery_and_reset_routes_keep_csrf_*` |
| D. El enlace o el correo terminan en el log al fallar el envío | DESCARTADO: solo se registra la clase de la excepción | `a_failed_send_logs_only_the_exception_class_*` |
| D. La página con token es cacheable o amplía el Referer | DESCARTADO: `no-cache, private`; sin `Referrer-Policy` laxa | `the_reset_page_with_a_token_is_not_cacheable_*` |
| D. Cookie de sesión sin `Secure` | DESCARTADO en staging: `secure; httponly; samesite=lax` (GET inocuo) | §4 |
| D. Enumeración por respuesta o por tiempo | Cubierto por TASK-0012: respuesta fija, envío diferido. **Tiempo real en staging: NO VERIFICADO** (no se midió) | `tests/Feature/Auth/AccountRecoveryTest.php` |

## 4. Configuración real (solo lectura, 2026-11-11 ~14:05Z)

Se leyó por SSH en `<LIVE_APP_DIR>` y con un script PHP en el contenedor `app`, que imprime solo hosts,
indicadores y nombres de clase; sin secretos, sin base y sin correo.
- **Código:** `HEAD` `b048df0`, archivos versionados limpios (solo assets compilados sin seguimiento). `APP_ENV=staging`,
  `debug` apagado, configuración cacheada.
- **Host:** `APP_URL` = `https://pruebas.camarapetrolera.app`; `FILAMENT_DOMAIN` sin definir (las rutas del panel no
  tienen dominio); `TrustHosts` comentado en `app/Http/Kernel.php:17`; sin `URL::forceRootUrl`.
- **Proxies:** `TrustProxies::$proxies` sin valor, sin `config/trustedproxy.php` y sin `TRUSTED_PROXIES`. En memoria, una
  petición desde el gateway con `X-Forwarded-Host`/`-For` y `CF-Connecting-IP` falsos conserva el host de `APP_URL`, y su
  `ip()` es el gateway.
- **Borde:** solo SSH escucha en público. Cloudflare llega por `cloudflared` (127.0.0.1) y nginx está en `127.0.0.1:8080`.
  El ingreso del túnel mapea **solo** `pruebas.camarapetrolera.app` a este nginx, con *catch-all* `http_status:404`.
  nginx: `server_name _`, `fastcgi_param HTTPS on`, sin `real_ip`.
- **IP observada:** en 24 h, 503 de 503 peticiones del log de nginx vinieron de **una** dirección privada (conteo
  agregado, sin imprimir direcciones).
- **Sesión, caché y correo:** sesión `file`, `same_site=lax`, `http_only`; caché `file` (el *rate limiter*); correo `smtp`;
  cola `sync`; *broker* `expire=60`, `throttle=60`; Turnstile configurado.
- **Observaciones HTTP inocuas** (GET sin cabeceras manipuladas): `http://…/` y `http://…/admin/login` → **200**, sin
  redirección; sin `Strict-Transport-Security` en HTTPS; cookies con `secure; httponly; samesite=lax`.

## 5. Propuesta de remediación (NO implementada; requiere autorización)

| Prioridad | Hallazgo | Cambio mínimo propuesto | Regresión |
|---|---|---|---|
| P1 | F-SEC03-01/02 | Forzar la raíz y el esquema de URL desde `APP_URL` fuera de `local`/`testing` (`URL::forceRootUrl`, `forceScheme('https')`) y activar `TrustHosts` (`allSubdomainsOfApplicationUrl`). Opcional, en infraestructura: `server_name` explícito en nginx con un *default server* que rechace el resto | Los oráculos `f_sec03_01_*` y `f_sec03_02_*` pasan a verde; los controles y `AccountRecoveryTest` siguen verdes |
| P2 | F-SEC03-03 | Recuperar la IP real: confiar solo en el gateway de cloudflared y nginx (red Docker, 127.0.0.1) y tomar `CF-Connecting-IP`/`X-Forwarded-For`, en nginx (`real_ip`) o en `TrustProxies`. **Advertencia:** al confiar en proxies hay que **quitar `HEADER_X_FORWARDED_HOST`** de `$headers`, o aplicar P1 antes. Si no, `X-Forwarded-Host` pasaría el borde y F-SEC03-01 se volvería explotable | `f_sec03_03_*` en verde; el control `a_forged_x_forwarded_host_*` debe **seguir** verde |
| P3 | F-SEC03-04 | Verificar Turnstile antes de contar los cupos por correo y por RIF (el de IP puede seguir antes), con la misma respuesta visible | `f_sec03_04_*` en verde; las pruebas de límite y de respuesta igual de TASK-0012, verdes |
| P4 | F-SEC03-05 | Al cambiar `email`, poner `email_verified_at = null` y borrar los tokens pendientes del correo anterior; opcionalmente, enviar la verificación al buzón nuevo | `f_sec03_05_*` en verde |
| P5 | F-SEC03-06 | En Cloudflare, «Always Use HTTPS» y HSTS (decisión de infraestructura del propietario); alternativa en la aplicación: redirigir HTTP a HTTPS | Comprobación GET inocua después del cambio |

## 6. Pruebas y resultados (runner aislado de Contabo, PostgreSQL desechable)

| Corrida | SHA | Resultado |
|---|---|---|
| Enfocada, 1.ª | `7f197a9` | 21 tests, 8 fallos. Uno era un control: su paso positivo final chocó con el límite de 2/min por IP de la página de reset (artefacto de la prueba; las aserciones de seguridad pasaron). Además, un oráculo con `Livewire::withHeaders(['Host'])` pasó, pero no podía fallar: el arnés reemplaza `HTTP_HOST` por el host de `APP_URL`. Se retiró por no ser oráculo |
| Enfocada | `8a4ed35` | run `20261111T141320Z-8a4ed35df5aa`: **20 tests, 79 aserciones; 13 controles OK; 7 fallos, todos oráculos de hallazgos abiertos**. Staging 200 antes y después; sin contenedores `cpvtest` restantes |
| Suite completa | `8a4ed35` | run `20261111T141441Z-8a4ed35df5aa`: **736 tests, 7939 aserciones; 7 fallos, exactamente los 7 oráculos de hallazgos abiertos**; los 729 restantes (las 716 pruebas previas y los 13 controles) OK, sin errores; staging 200 antes y después; sin contenedores `cpvtest` restantes |

### 6.1 Lectura de la suite completa

Las pruebas añadidas no cambian código funcional. El único rojo es el de los oráculos, que documentan los hallazgos y
pasan a verde con la corrección autorizada correspondiente. Ninguna prueba previa cambió de estado.

## 7. Riesgos residuales y controles por validar (NO VERIFICADO)

- **Borde de Cloudflare:** que ninguna combinación de SNI, `Host` o URI absoluta entregue a la aplicación otro host. Se
  infiere del ingreso del túnel, pero no se probó activamente: requiere autorización y ventana de pruebas.
- **Producción:** no se inspeccionó. Si `APP_URL`, dominio, proxies o borde difieren, F-SEC03-01 puede ser explotable
  allí. Verificar **antes** de llevar la recuperación a producción.
- **Correo real:** el host del enlace en un mensaje entregado no se observó, porque no se envió correo.
- **Enumeración por tiempo en staging:** no se midió.
- **Turnstile:** la verificación no comprueba `hostname`/`action` de la respuesta (bajo; informativo).
- **Cambio de correo:** no cierra las otras sesiones abiertas (diseño; bajo, informativo).
- **Ramas públicas:** las pruebas de `audit/sec-03-account-recovery` son genéricas, sin rutas completas contra staging,
  pero describen el mecanismo de F-SEC03-04. El propietario decide si esa rama debe seguir pública hasta la corrección.
