# TASK-0019 — SEC-03: integración y despliegue de P1–P5 solo en staging, con verificación de cabeceras y HSTS gradual

| Campo | Valor |
|---|---|
| ID | `TASK-0019` (siguiente libre: sin archivo, rama ni commit con 0019 en ninguna rama remota) |
| Fuente | Issue #2, comentario `6820224859` (propietario, 2026-11-12 09:45Z), íntegro al final |
| Antecedente | TASK-0018 **APPROVED** como implementación aislada (`6820214362`); no se modifica su propósito |
| Estado | IN_PROGRESS (materializada 2026-11-12, antes del preflight de escritura y del despliegue) → **READY_FOR_REVIEW** (2026-11-12 ~10:05Z; desplegado en staging, HSTS 1 día) |
| Código aprobado | `fix/sec-03-p1-p5` @ `9d0c24d613fb32d9a0ff91c5cc9d0e4b7d036823` |
| Staging vivo al empezar | `feature/upgrade-filament-v3` = `b048df09b345bfefc8bbfe88ed0ca8e4ea09df33` (remoto y servidor, árbol limpio) |
| Límites | solo staging; sin producción, `main`, SEC-01, n8n, taxonomía, secretos ni cambios de Cloudflare de alcance de zona |

## Resultado (2026-11-12) — READY_FOR_REVIEW

| Campo | Valor |
|---|---|
| Estado | **READY_FOR_REVIEW**: desplegado en staging; HSTS de 1 día activo solo en el hostname de staging |
| BASE (staging anterior) | `b048df09b345bfefc8bbfe88ed0ca8e4ea09df33` |
| Candidato integrado = TESTED_HEAD = REVIEW_HEAD = HEAD desplegado | `9d0c24d613fb32d9a0ff91c5cc9d0e4b7d036823` (*fast-forward* de `b048df0`; 6 commits SEC-03, 15 archivos; sin migraciones, Docker, workflows, composer ni SEC-01) |
| Despliegue | push *fast-forward* `b048df0..9d0c24d` a `feature/upgrade-filament-v3`; workflow `43196418453` **success** (10:00:08–10:00:49Z); verificado en el servidor (`git rev-parse HEAD` = `9d0c24d`, árbol limpio, imagen nueva) |
| Cambios de configuración en staging | `.env`: `HSTS_MAX_AGE=0` antes del despliegue (para no emitir HSTS antes de los gates), y `HSTS_MAX_AGE=86400` tras los gates A y B (`config:cache` + recreación del contenedor `app`, 10:02Z). Ninguna otra variable; Cloudflare, nginx y el túnel **sin cambios** |

### 1. Preflight (todo PASS antes de desplegar)

| Control | Resultado |
|---|---|
| Estado vivo | remoto y servidor en `b048df0`, árbol limpio; `app` y `nginx` arriba |
| Runtime y configuración | PHP 8.2.35, Laravel 12.69.0, `APP_ENV=staging`, `APP_URL` = host https de staging, `FILAMENT_DOMAIN` sin definir. Sin overrides `TRUSTED_*`, `APP_TRUSTED_HOSTS*`, `APP_CANONICAL_URL`, `HTTPS_REDIRECT` ni `HSTS_*` (rigen los valores por defecto del código) |
| Gateway Docker efectivo | `perfilafiliados_default`, 2 contenedores (app, nginx); el par que ve nginx es el gateway, igual al valor por defecto de `TRUSTED_PROXIES` |
| Borde | «Always Use HTTPS» **activo**: HTTP → 301 de Cloudflare a https, con ruta y *query* conservadas, 1 salto; TLS válido (Google Trust Services, vence el 2026-12-14); sin HSTS en el borde |
| Respaldo | `/root/backups/sec03-20261112T095103Z` en el servidor (700/600, fuera de git y del webroot): copia de `.env`; imagen `perfilafiliados-app:pre-sec03-b048df0`; `pg_dump -Fc -n public` del Supabase de staging (PostgreSQL 17.6): exit 0, 0 líneas de error, 21 MB, 906 entradas de TOC, sha256 registrado; conteos de 88 tablas (41 499 filas) |
| Ensayo de restauración (aislado) | PostgreSQL 17 + pgvector desechable en red `--internal`, sin puertos; extensiones en su esquema original (`vector`, `pg_trgm`, `unaccent` en `public`). **88 tablas y 41 499 filas, 0 diferencias de conteo**; 133 migraciones; 100 claves foráneas; 0 índices inválidos. Dos avisos: «schema public already exists» (inocuo) y la FK `contact_empresa_contact_id_foreign` no se pudo crear porque **la base viva ya tiene 2 filas huérfanas** (preexistente, sin acción: las operaciones de datos no están autorizadas). En una restauración real, crear esa FK como `NOT VALID`. Contenedores de ensayo eliminados |
| Regresión del candidato integrado `9d0c24d` | `Sec03` run `20261112T095028Z-9d0c24d613fb`: **51/51** (242 aserciones); enfocada run `20261112T095144Z-9d0c24d613fb`: **111/111** (620); suite completa run `20261112T095314Z-9d0c24d613fb`: **767/767** (8102). Staging 200/200; sin contenedores restantes |
| Plan de rollback (listo antes del despliegue) | **R1, inmediato en el servidor:** `git reset --hard b048df0`, retag `pre-sec03-b048df0` → `latest`, restaurar `.env` del respaldo, `config:cache` + `view:cache`, `up -d` y reload de nginx. **R2, persistente:** `git revert` del rango `b048df0..9d0c24d` empujado a `feature/upgrade-filament-v3` (sin *force-push*). **Umbrales:** cualquier 5xx sostenido, `/admin/login` distinto de 200, 404 en el host de staging, bucle https o errores nuevos en el log. HSTS: con `HSTS_MAX_AGE=0` deja de enviarse; los navegadores que ya lo recibieron lo conservan ≤1 día |

### 2. Cadena real de cabeceras (observación de solo lectura con peticiones inocuas marcadas)

Una petición https del propio servidor a la URL pública (por Cloudflare y el túnel), capturada en el bridge de la app; se
imprimieron solo nombres de cabecera y comparaciones booleanas, y la captura se borró.

| Punto | Antes del despliegue | Después |
|---|---|---|
| nginx recibe | `Cf-Connecting-Ip` = **una** IP, igual al cliente; `X-Forwarded-For` = 1 entrada, igual al cliente; `X-Forwarded-Proto: https`; `Host` = staging; **sin** `X-Forwarded-Host`; par = gateway | (nginx sin cambios) |
| php-fpm recibe (FastCGI) | `HTTP_CF_CONNECTING_IP` = cliente; `HTTP_X_FORWARDED_PROTO=https`; `REMOTE_ADDR` = gateway; `HTTPS=on`; `HTTP_HOST` = staging; sin `HTTP_X_FORWARDED_HOST` | igual |
| Laravel (código desplegado, en el contenedor vivo, con esa misma entrada) | — | `ip()` = cliente (`p2_effective_ip_is_client=true`) |
| HTTP | Cloudflare responde 301 y **no** llega al origen | igual |

### 3. Gates de HTTPS y HSTS

| Gate | Resultado |
|---|---|
| A (antes de HSTS) | **PASS.** HTTP → HTTPS en 1 salto, ruta y *query* (incluida `ñ`) conservadas; TLS válido; sin bucles (`/`, `/admin/login`, `/admin/password-reset/request` y `/admin/recuperar-acceso` dan 200 con 0 redirecciones; `/admin` → 302 a login https); 0 referencias `http://` en la página de login (13 absolutas, todas https); la aplicación no redirige (redirección apagada); cookies `secure` |
| B (origen de la cabecera) | **PASS.** HSTS de Cloudflare es un ajuste **de zona** (afectaría a otros hostnames de `camarapetrolera.app`): **no se tocó**. La cabecera la emite la aplicación, que solo atiende el host de staging (`TrustHosts`); sin `includeSubDomains`, el navegador la aplica solo a `pruebas.camarapetrolera.app`. El borde no emite HSTS, así que no hay políticas contradictorias. Producción no ejecuta este código |
| C (activación) | **PASS.** `Strict-Transport-Security: max-age=86400` en las 4 rutas https; sin `includeSubDomains` ni `preload`; la respuesta HTTP (301 de Cloudflare) no la lleva |
| D (observación) | **PASS** (10:02Z y antes de entregar, ver §5): servidor en `9d0c24d`, app arriba, mantenimiento apagado, 0 errores nuevos en el log, 0 respuestas 5xx en nginx, rutas en 200. Sin rollback |

### 4. Matriz P1–P5 / F-SEC03-01..06

| Hallazgo | Aislamiento | Staging (efectivo) | Estado | Riesgo residual |
|---|---|---|---|---|
| F-SEC03-01 (P1) | PASS | **PASS**: enlace de recuperación generado en el contenedor vivo con el host de staging y https | Desplegado y verificado | Producción sin inspeccionar |
| F-SEC03-02 (P1) | PASS | `TrustHosts` activo en el middleware global. El rechazo de un Host ajeno **no se probó en staging** (exige una cabecera manipulada, no autorizada); el túnel solo enruta el host exacto | Desplegado; rechazo verificado solo en aislamiento | — |
| F-SEC03-03 (P2) | PASS | **PASS**: tránsito de `CF-Connecting-IP` hasta PHP observado; par = gateway exacto; `ip()` efectiva = cliente | Desplegado y **verificado** (tránsito y cálculo) | La separación de cupos con dos clientes no se ejerció en staging (consumiría límites reales). Que Cloudflare reescribe un `CF-Connecting-IP` enviado por el cliente: documentado, **no probado** activamente (sería una prueba de suplantación) |
| F-SEC03-04 (P3) | PASS | No ejercido: exige Turnstile real y correo; no hay buzones sintéticos aprobados | Desplegado; verificado solo en aislamiento | Prueba de correo pendiente de autorización |
| F-SEC03-05 (P4) | PASS | No ejercido: exigiría escribir cuentas reales | Desplegado; verificado solo en aislamiento | — |
| F-SEC03-06 (P5) | PASS | **PASS**: «Always Use HTTPS» verificado; HSTS `max-age=86400` efectivo solo en staging; sin bucles | Desplegado y **verificado**, 1.ª etapa de HSTS | Subir `max-age` y cualquier `includeSubDomains`/`preload` requieren aprobación nueva |

SEC-03 no se cierra globalmente; producción sin autorización.

### 5. Observación final antes de entregar

10:04Z: servidor en `9d0c24d`; `app` arriba; 55 peticiones en nginx en 15 min con **0 respuestas 5xx**; **0 errores** en el log de Laravel desde las 10:00Z; las 4 rutas en 200 con `max-age=86400`. Sin rollback.

### 6. Pendientes y gates (sin ejecutar)

- Revisión humana de esta entrega.
- Pruebas de correo (P3, flujos por correo y RIF) con buzones sintéticos aprobados.
- Prueba activa del borde (rechazo de Host ajeno, `CF-Connecting-IP` suplantado por un cliente): requiere autorización específica.
- Etapas siguientes de HSTS (1 semana → 6 meses), cada una con aprobación; `includeSubDomains` y `preload` con aprobación específica.
- Dato preexistente: 2 filas huérfanas en `contact_empresa` (sin acción).
- Producción, `main` y SEC-01: sin autorización.

---

## Comentario fuente `6820224859` (íntegro)

> # ASIGNACIÓN EXPLÍCITA — NUEVA TAREA INDEPENDIENTE: integración y despliegue SEC-03 SOLO EN STAGING
>
> **Autorización del propietario:** crear una tarea nueva, consecutiva y versionada, independiente de TASK-0018, para integrar exclusivamente SEC-03 P1–P5 en staging, respaldar, probar, verificar cabeceras/HTTPS y aplicar HSTS gradualmente **solo tras superar los gates descritos**. TASK-0018 aprobado **solo como implementación aislada** en [6820214362](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6820214362). **No activar SEC-01, no producción, no merge a main.**
>
> ## 0. Arranque y tarea nueva
> 1. Seguir `AGENTS.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/SESSION_RESUME.md` y `docs/orquestador/current_task.md`. Leer este comentario completo una vez y solo referencias directas: `docs/orquestador/tasks/0018-sec03-p1-p5-remediation.md`, `docs/security/SECURITY_STATUS.md` y plan de despliegue pertinente. Evitar recorridos del hilo, auditorías generales, loops o polling.
> 2. Antes de editar, verificar `git status -sb`, rama, HEAD, remoto y cambios locales. Preservar todo cambio del propietario (incluida §0 de SESSION_RESUME). No sobrescribir worktrees ni cambios ajenos.
> 3. Consultar IDs versionados y **materializar una tarea NUEVA consecutiva**, sin inventar ID, sin reutilizar TASK-0018 y sin cambiar su propósito. Registrar aceptación, estado, evidencias, gates, rollback y handoff en las fuentes persistentes autorizadas.
> 4. Fuente de código SEC-03 aprobada: rama `fix/sec-03-p1-p5`, REVIEW_HEAD `9d0c24d613fb32d9a0ff91c5cc9d0e4b7d036823`. Staging anterior declarado: `feature/upgrade-filament-v3` @ `b048df0`; **verificar estado vivo** antes de integrar. Preparar candidato con **solo** los commits/archivos SEC-03 necesarios; comprobar diff, dependencias, conflictos y que no incorpora SEC-01 R3/R4/R4-01 u otros cambios no autorizados.
>
> ## 1. Preflight obligatorio (STOP si falla)
> - Confirmar acceso autorizado a staging, SHA/runtime, workflow y ruta de despliegue, versión PHP/Laravel, configuración `APP_URL` y host exacto, `TRUSTED_PROXIES`, gateway Docker efectivo, `CF-Connecting-IP`, `X-Forwarded-Proto`, `TRUSTED_PROXY_CLIENT_IP_HEADER`, `APP_TRUSTED_HOSTS` y toggles HTTPS/HSTS. No publicar valores sensibles.
> - Inventariar rutas de autenticación/recuperación y configuración del borde de staging. **El propietario declara activado Cloudflare Always Use HTTPS**; verificarlo con peticiones inocuas al dominio de staging, sin asumir que la captura de HSTS lo demuestra.
> - Hacer respaldo consistente de la BD de staging y configuración/artefactos necesarios; comprobar integridad y **ensayar restauración en aislamiento**, sin sobrescribir staging. Registrar ubicación protegida, hora y evidencia saneada; no copiar datos personales a repositorios públicos.
> - Preparar plan reversible con SHA anterior, SHA candidato, pasos, smoke tests, responsables y umbrales de rollback; disponer de mecanismo de reversión antes de desplegar.
> - Correr pruebas SEC-03, autenticación/recuperación y suite completa en runner/BD aislados sobre **candidato integrado**, no solo el SHA original. Si falla una prueba crítica, un respaldo, la restauración o la compatibilidad, **STOP sin desplegar**.
>
> ## 2. Despliegue limitado a staging
> - Autorizado únicamente si TODOS los preflights anteriores pasan. Desplegar por el mecanismo habitual autorizado de staging, con SHA verificable y sin tocar producción ni `main`. Si el mecanismo habitual arrastra cambios fuera de SEC-03, **STOP** y solicitar aprobación específica.
> - Verificar SHA realmente ejecutado, salud de contenedores, logs y rutas GET inocuas (`/admin/login`, recuperación y verificación donde proceda). No realizar pruebas agresivas ni enviar correos a clientes; para flujos de correo usar solo cuentas/buzones sintéticos aprobados.
> - Probar funcionalmente P1–P4 y P5 con cuentas sintéticas y solicitudes de bajo impacto. Si se detecta pérdida de acceso, error crítico, filtración de host, elevación de permisos, bucle HTTPS, errores sostenidos o fallo de rollback, **revertir al SHA previo** siguiendo plan, documentar y STOP.
>
> ## 3. Verificación operativa F-SEC03-03 / P2
> - Comprobar por observación de solo lectura y pruebas inocuas que `CF-Connecting-IP` y `X-Forwarded-Proto` llegan a PHP como se espera; confirmar par `REMOTE_ADDR` y gateway Docker, y que la IP real se usa en los límites. Evitar publicar IPs reales completas o PII.
> - Probar con dos clientes sintéticos autorizados, si están disponibles, que no comparten cuota indebidamente; nunca enviar cabeceras de suplantación o pruebas adversariales contra staging sin autorización adicional.
> - Si falta cabecera, el gateway cambió, la cadena es ambigua o hay riesgo de spoofing, mantener F-SEC03-03 **abierto**, STOP para la modificación correspondiente y presentar propuesta concreta. No ampliar confianza a `*` o CIDR amplios.
>
> ## 4. Verificación HTTPS y habilitación gradual de HSTS (F-SEC03-06)
> **Gate A — antes de HSTS:** comprobar externamente en staging HTTP→HTTPS con redirección correcta, certificado TLS válido, ausencia de bucles, rutas y parámetros preservados, ausencia de mixed content relevante, y HTTPS hasta la capa apropiada de la cadena Cloudflare → túnel → Nginx → Laravel. Confirmar `Always Use HTTPS` activo y que la aplicación no produce redirecciones duplicadas o bucles; verificar cabeceras efectivas.
> **Gate B — origen de la cabecera:** determinar si HSTS lo emitirá Cloudflare o la aplicación, evitando políticas contradictorias. Verificar alcance del ajuste de Cloudflare: **si se aplica al dominio/zona compartida o afecta a producción u otros subdominios, NO modificarlo**; STOP y solicitar autorización específica para ese alcance. Solo aplicar HSTS al hostname de staging mediante una regla/ajuste efectivamente limitado a staging y reversible, si está disponible y autorizado.
> **Gate C — activación gradual:** tras superar A y B, activar solo en staging `Strict-Transport-Security: max-age=86400` (1 día), **sin `includeSubDomains` ni `preload`**. Verificar respuesta HTTPS real, no emitir HSTS por HTTP, y repetir smoke tests. No aumentar `max-age` durante esta tarea sin nueva aprobación.
> **Gate D — observación/rollback:** comprobar que no aparecen bucles, fallos TLS, errores de acceso ni regresiones; ante fallo, revertir configuración y despliegue cuando corresponda, recordando que clientes que ya recibieron HSTS pueden retenerlo hasta expirar. No declarar F-SEC03-06 cerrado sin evidencia del borde y del alcance efectivo.
>
> ## 5. Evidencia, estados y STOP
> - Matriz P1–P5 y F-SEC03-01..06: **PASS/FAIL/BLOCKED**, evidencia aislada vs staging, riesgo residual, dependencias; F-SEC03-03 y F-SEC03-06 solo podrán cambiar a verificados cuando exista evidencia efectiva. No dar por cerrado SEC-03 global ni autorizar producción.
> - Registrar BASE, TESTED_HEAD, REVIEW_HEAD, HEAD desplegado, fecha, workflow, backups y prueba de restauración, resultados antes/después, cabeceras HTTPS/HSTS saneadas, reversión y cambios documentales. No publicar secretos, tokens, PII o volcados.
> - Actualizar `docs/orquestador/tasks/<ID-nuevo>.md`, `current_task.md`, `SESSION_RESUME.md`, `docs/security/SECURITY_STATUS.md`; handoff en `audit/**` solo si las reglas actuales permiten escribirlo. Preservar historia de TASK-0018 y hallazgos.
> - Publicar `READY_FOR_REVIEW` con pruebas y riesgos en Issue #2 y **STOP**. No iniciar SEC-01, no producción, no rotación de secretos, no n8n, no taxonomía, no cambios masivos de BD. Cualquier gate no superado se reporta `BLOCKED` con acción propuesta, sin eludirlo.
>
> **Respuesta inicial:** nuevo ID real materializado, rama, HEAD, staging SHA vivo, alcance de Cloudflare y bloqueos. **Respuesta final:** `READY_FOR_REVIEW / Issue #2 / HEAD <sha>` o `BLOCKED`, con evidencia.
