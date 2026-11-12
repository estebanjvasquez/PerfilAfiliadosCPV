# TASK-0019 — SEC-03: integración y despliegue de P1–P5 solo en staging, con verificación de cabeceras y HSTS gradual

| Campo | Valor |
|---|---|
| ID | `TASK-0019` (siguiente libre: sin archivo, rama ni commit con 0019 en ninguna rama remota) |
| Fuente | Issue #2, comentario `6820224859` (propietario, 2026-11-12 09:45Z), íntegro al final |
| Antecedente | TASK-0018 **APPROVED** como implementación aislada (`6820214362`); no se modifica su propósito |
| Estado | **IN_PROGRESS** (materializada 2026-11-12, antes del preflight de escritura y del despliegue) |
| Código aprobado | `fix/sec-03-p1-p5` @ `9d0c24d613fb32d9a0ff91c5cc9d0e4b7d036823` |
| Staging vivo al empezar | `feature/upgrade-filament-v3` = `b048df09b345bfefc8bbfe88ed0ca8e4ea09df33` (remoto y servidor, árbol limpio) |
| Límites | solo staging; sin producción, `main`, SEC-01, n8n, taxonomía, secretos ni cambios de Cloudflare de alcance de zona |

## Progreso

(Se completa durante la ejecución: preflight, respaldo y restauración, candidato, despliegue, verificaciones, HSTS.)

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
