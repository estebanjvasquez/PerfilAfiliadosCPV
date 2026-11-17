# E2 — Matriz de seguridad y bloqueos preproducción

**TASK-0033** · 2026-11-17 · Para: el propietario y el orquestador · Índice: [`00_INDICE.md`](00_INDICE.md)

**Qué es:** la conciliación del registro canónico [`docs/security/SECURITY_STATUS.md`](../security/SECURITY_STATUS.md) con
lecturas de solo lectura hechas hoy. **No es una certificación.** Ningún hallazgo se da aquí por cerrado: el registro
canónico no cambia, y esta matriz solo añade la verificación de hoy y su gate. No se probó ningún ataque, no se tocó
producción y no se rotó ninguna credencial.

Etiquetas: **VIVO** = verificado hoy (evidencia en [`evidencia/`](evidencia/)) · **DOC** = documentado antes (fuente) ·
**PEND** = pendiente de comprobar · **BLOQ** = bloqueado por acceso o gate · **DEC** = decisión del propietario o de la
Cámara.

Columnas de gate:
- **UAT-I:** ¿impide iniciar la UAT institucional en staging?
- **PROD:** ¿impide producción?
  - Producción (`main`) es otra base de código (Filament v2 + MySQL). Ninguna corrección de staging está allí.
  - Hasta migrar, cada fila «desplegado en staging» sigue **abierta para producción**.

## 1. Resumen

| Grupo | IDs |
|---|---|
| Desplegados y verificados **en staging**, todavía abiertos para producción | F-SEC01-01, 02a, 04, R3-01, R3-04 (vía R4), R4-01, T21-01, T24-02; F-SEC03-01 a 06; RT-PHP-2026-10; RT-MAIL-2026-10 |
| Abiertos sin corrección desplegada | F-SEC01-R3-02, R3-03, T24-01; RT-BUILD-2026-10 (candidato sin desplegar); F-SEC01-08 (SEC-04); R-SEC01-01 |
| Decisiones pendientes del propietario | F-02 (editor y configuración de roles), F-03, F-06, recuperación de R4-01, R-04, R-06, Q-SEC01-R3-05 |
| Cerrados | RT-MAIL-TLS-2026-10 |
| Rondas sin empezar | SEC-02 (exportaciones y caché), SEC-04 (dependencias e integraciones), SEC-05 (cierre) |
| Observaciones nuevas de hoy (no son hallazgos confirmados) | OBS-T33-01, 02 y 03 (§4) |
| **Bloqueos de UAT-I** | **Ninguno técnico**; 5 condiciones operativas (§5) |
| **Bloqueos de PROD** | Todas las filas de §2–§4 con «Sí»; orden de cierre en §6 |

## 2. SEC-01 (autorización, roles, cuentas, vistas)

| ID | Sev. | Estado en el registro (DOC) | Verificación de hoy | Falta | UAT-I | PROD | Orden |
|---|---|---|---|---|---|---|---|
| F-SEC01-01 | Alta | Desplegado y verificado en `cf01e9c` (2026-10-21) | — | Equivalente en producción | No | Sí | P3 |
| F-SEC01-02 / R3-04 | Alta | R4 (solo `super_admin` administra roles) desplegado en staging `71e8a6d`; contención en datos verificada (DOC 2026-11-12). F-02 sigue **parcial**: editor y configuración de roles, DEC | **VIVO:** cada permiso `*_role` está en **1 solo rol**; `super_admin` tiene 4 usuarios | Revisión formal y DEC sobre F-02 | No | Sí | P2 |
| F-SEC01-02a | Alta | Desplegado y verificado (`cf01e9c`) | — | Producción | No | Sí | P3 |
| F-SEC01-03 | Baja–media | Confirmado en aislamiento: el rol de afiliado puede abrir el alta de dos catálogos. DEC (quitar 2 permisos) | **VIVO:** el rol `afiliados` (324 usuarios) tiene `create_area` y `create_country`, catálogos globales, además de altas de módulos propios de la empresa. Que sean los dos catálogos de F-03 es PEND (las notas de R3 están restringidas) | DEC y cambio de datos de roles (gate) | **Condición C3** | Sí | P4 |
| F-SEC01-04 | Baja | `/form` retirado y verificado | — | — | No | Sí (producción) | P3 |
| F-SEC01-05 | Media prov. | No reproducido en el alcance | — | — | No | No | — |
| F-SEC01-06 | Regla de negocio | DEC: quién desvincula usuarios de una empresa | — | DEC | No | Sí | P4 |
| F-SEC01-07 | Indicio | Su faceta de correo pasó a F-SEC03-05 (corregida) | — | Resto de escritores de cuenta: SEC-03 | No | PEND | — |
| F-SEC01-08 | Informativa | Archivos de diagnóstico en el repo (público) | — | SEC-04 | No | Sí | P5 |
| R-SEC01-01 | Fiabilidad | Formularios de empresa fallan sin ciudades | — | Reparación | No | No | — |
| F-SEC01-R3-01 | Alta | Desplegado en staging (`71e8a6d`), 11/11 componentes | — | Producción | No | Sí | P3 |
| **F-SEC01-R3-02** | Media | **Abierto**: acciones de módulos sin el permiso de escritura del módulo | — | Corrección + regresión (asignación) | No | Sí | P2 |
| **F-SEC01-R3-03** | Baja | **Abierto**: categoría inactiva por id forjado | — | Corrección (asignación) | No | Sí | P4 |
| F-SEC01-R4-01 | Media | Desplegado en staging; **109 permisos perdidos sin restaurar** (DEC) | — | DEC de recuperación (≤1 vínculo propuesto) | **Condición C2** | Sí | P4 |
| F-SEC01-T21-01 | Baja | Desplegado (`270e07d`); 6 filas `*_shield::role` inertes, riesgo aceptado (`6849259532`) | **VIVO:** 6 filas, **0 vínculos** | — | No | No | — |
| **F-SEC01-T24-01** | Media latente | **Abierto**: «Eliminar» de la lista no protege la cuenta privilegiada | **VIVO:** ningún rol fuera de `super_admin` tiene `delete_user`, `delete_any_user` ni `force_delete_*` | Corrección (asignación); mientras tanto, no conceder esos permisos | **Condición C2** | Sí | P2 |
| F-SEC01-T24-02 | Alta potencial | Desplegado en staging (TASK-0026, lote 63). **Ventana de 24 h interrumpida sin PASS**; la de TASK-0027 no tiene registradas las lecturas T+4/12/24 h | **VIVO** (`monitor_check.sh` 11:04Z, 92 h): 13/13 vistas con `security_invoker`, 13/13 sin `anon`/`authenticated`, sesión `anon` → *permission denied*; RLS en 92/92 tablas con **0 políticas** | Cierre formal con decisión del propietario sobre la ventana; **inventario de la clave anónima de la Data API** (PEND, P0 si salió del equipo); producción | No | Sí | P1 |
| R-04 (TASK-0024) | Integridad de datos | 2 filas huérfanas de `contact_empresa`, con su propio gate | **VIVO:** 2 | DEC y gate de escritura | No | Sí (antes de migrar datos) | P4 |
| R-06 (TASK-0024) | — | En espera (`SESSION_RESUME.md` §3) | — | DEC | No | PEND | — |
| Q-SEC01-R3-05 / R3-06 | Gobierno / higiene | DEC sobre «Confirmada»; `error_log` versionado | — | DEC; ronda de higiene | No | Sí (R3-06) | P5 |
| Cobertura | — | Relation managers: **14 de 24 sin probar**; búsqueda global: **9 de 12**; otras superficies (§6 del registro) | — | Ronda de cobertura | No | Sí | P3 |

## 3. SEC-03, runtime, correo y recuperación

| ID | Sev. | Estado en el registro (DOC) | Verificación de hoy | Falta | UAT-I | PROD | Orden |
|---|---|---|---|---|---|---|---|
| F-SEC03-01 | Media (Alta si el borde lo permitiera) | Desplegado (`9d0c24d`) y verificado con correo real (TASK-0020) | — | **Verificar en producción antes de llevar allí la recuperación** | No | Sí | P2 |
| F-SEC03-02 | Media | Desplegado; Host ajeno → 400 | **VIVO:** `foreign_host=400` | Producción | No | Sí | P3 |
| F-SEC03-03 | Media, disponibilidad | Desplegado; IP real del cliente; probado de forma adversarial (TASK-0020) | — | Producción | No | Sí | P3 |
| F-SEC03-04 | Baja–media | Desplegado y verificado (TASK-0020) | — | Producción | No | Sí | P3 |
| F-SEC03-05 | Baja | Desplegado; abrir el enlace del buzón nuevo **no se ejecutó** | — | Esa prueba (cuenta sintética) | No | Sí | P4 |
| F-SEC03-06 | Baja–media | Desplegado: «Always Use HTTPS» + HSTS de 1 día, solo en staging | **VIVO:** `max-age=86400`, HTTP → 301 | Subir HSTS por etapas (DEC); producción | No | Sí | P4 |
| Turnstile en móvil | UX / acceso | Fallos intermitentes en Samsung Galaxy + Chrome; funcionó al refrescar (`current_task.md`); sin tarea | — | Diagnóstico (versión, modo, red) | **Condición C4** | Sí | P4 |
| TASK-0012 (recuperación por correo y RIF) | Funcional y seguridad | En staging desde el 2026-11-03; el propietario recibió correos (`6802438608`); falta **evidencia formal de cada flujo, incluido RIF** | **VIVO:** `mail.default=smtp`; Turnstile configurado | Pruebas con cuentas y buzones de prueba (autorizadas en alcance por `6802438608`, sin tarea) | No (fuera de la UAT-I si no se prueba recuperación con personal) | Sí | P2 |
| RT-PHP-2026-10 | Crítica | Desplegado y aceptado (2026-10-26) | — | Producción: PHP 8.2+ con parche | No | Sí | P3 |
| **RT-BUILD-2026-10** | Alta (configuración embebida) | **Candidato sin desplegar**; las imágenes antiguas conservan `.env` en sus capas | PEND (no se inspeccionaron capas hoy) | Desplegar la imagen limpia y decidir el ciclo de vida de las imágenes antiguas | No | Sí | P1 |
| RT-MAIL-TLS-2026-10 | Media | **Cerrado** (6/6 envíos con verificación) | — | — | No | No | — |
| RT-MAIL-2026-10 | Funcional | `smtp` activo en staging | **VIVO:** `smtp` | Producción | No | Sí | P3 |

## 4. Exportaciones, caché, n8n, credenciales y observaciones nuevas

| ID | Tema | Estado (DOC) | Verificación de hoy | Falta | UAT-I | PROD | Orden |
|---|---|---|---|---|---|---|---|
| Export-access (`ce70b7f`) | Descargas con control de acceso | UAT declarada (`6802438608`) | **VIVO:** sin sesión, `/pdf/177` y `/xls/177` → 302 a `/admin/descargas/...` → 302 a login, todo con `cf-cache-status: DYNAMIC` | **PEND:** respuesta **autenticada** de una descarga (caché de Cloudflare con sesión), con una cuenta de prueba | No | Sí | P2 |
| SEC-02 (ronda) | Informes, exportaciones, temporales, caché, renderizadores | PLANNED, sin empezar | — | Ronda completa | No | Sí | P3 |
| n8n: dos hallazgos privados | Exposición del flujo de CIRA | Informados por canal privado (`tasks/0013`); sin corregir | Sin detalle aquí (repo público) | Corrección (asignación y gate de n8n) | **Condición C5** | Sí | P1 |
| Credenciales | Rotar `N8N_API_KEY`, `CPV_GITHUB_TOKEN`, `CPV_MCP_TOKEN` (este último a la vez en el Worker y en n8n); confirmar la revocación de los tokens de Cloudflare de septiembre; vencimiento del PAT | Solo planificar (`6802438608`) | **BLOQ:** rotar no está autorizado | Plan y ventana de rotación | No | Sí | P1 |
| SEC-04 (ronda) | Dependencias, integraciones, configuración | PLANNED | — | Ronda | No | Sí | P3 |
| SEC-05 (ronda) | Regresión consolidada y cierre | PLANNED | — | Ronda | No | Sí | P6 |
| **OBS-T33-01** | Cabeceras de endurecimiento | Nuevo | **VIVO:** en `/`, `/admin/login` y `/pdf/*` no aparecen `X-Frame-Options`, `Content-Security-Policy`, `X-Content-Type-Options`, `Referrer-Policy` ni `Permissions-Policy` | Evaluar en SEC-02/SEC-04 (clickjacking del panel, etc.). Es una **observación de configuración, no un hallazgo confirmado** | No | Sí (evaluar) | P3 |
| **OBS-T33-02** | Monitorización de 24 h sin cierre registrado | Las lecturas T+4/12/24 h de TASK-0026/0027 no constan | **VIVO:** instantánea actual PASS (§2, T24-02). Los 3 errores de Laravel desde el 13/11 tienen origen identificado: 2 sondas por stdin (TASK-0027 y TASK-0032) y el defecto conocido de `join-views` | DEC del propietario: aceptar la instantánea de hoy o pedir una ventana nueva; **no** es un PASS retroactivo | No | Sí (proceso) | P1 |
| **OBS-T33-03** | Datos reales en staging | La BD de staging es la **compartida** (desarrollo + staging) y tiene las 406 empresas reales | **VIVO:** 406 empresas con `status_id = 1`, 368 usuarios (324 con el rol `afiliados`) | Reglas de UAT: no modificar empresas reales (E3) | **Condición C1** | — | — |
| `JoinViews` | Funcional (página del personal) | La vista falla con empresas incompletas (500); el PDF apunta a un método inexistente | **VIVO:** error de `join-views` en el log del 13/11 | Reparación (deuda técnica de preproducción, `6802438608` punto 8a) | Excluir del guion | Sí | P4 |

## 5. Condiciones para la UAT institucional (no son correcciones)

| # | Condición | Motivo |
|---|---|---|
| C1 | El personal **no modifica** empresas, contactos ni usuarios reales. Los casos de perfil son de lectura, o se hacen sobre una empresa de prueba creada y borrada por el propietario | OBS-T33-03 |
| C2 | Nadie guarda roles en el editor ni concede `delete_user`/`*_role`; las cuentas de evaluador no tienen roles privilegiados | R4-01, T24-01, F-02 |
| C3 | El guion no pide crear áreas, países ni otros catálogos. Uno de los 2 invitados de la campaña tiene el rol `afiliados` y el otro el rol `lectura` (205 permisos). El propietario confirma que esos roles son los que quiere para evaluar, o decide antes F-SEC01-03 | F-SEC01-03; [`evidencia/vivo_2026-11-17.md`](evidencia/vivo_2026-11-17.md) §4 |
| C4 | Se avisa de la intermitencia de Turnstile en móvil (refrescar) y se registra el dispositivo si ocurre | Turnstile |
| C5 | La URL de CIRA Test se comparte solo con los invitados. Al cerrar la campaña, el propietario revisa el volumen de ejecuciones de n8n | Hallazgos privados de n8n |

## 6. Orden de cierre propuesto para producción (no asigna trabajo)

1. **P1:**
   - credenciales y los dos hallazgos privados de n8n;
   - inventario de la clave anónima de la Data API;
   - RT-BUILD (imagen sin `.env`);
   - cierre formal de la monitorización (OBS-T33-02) y de F-SEC01-T24-02.
2. **P2:**
   - F-SEC01-R3-02 y T24-01;
   - revisión formal de R4 y F-02;
   - F-SEC03-01 en producción;
   - evidencia de TASK-0012 (correo y RIF);
   - descarga autenticada sin caché.
3. **P3:**
   - cobertura (14 relation managers, 9 búsquedas);
   - SEC-02 y SEC-04, más OBS-T33-01;
   - el equivalente en producción de todo lo desplegado en staging.
4. **P4:**
   - R3-03, F-03, F-06;
   - recuperación de R4-01, R-04, HSTS por etapas;
   - Turnstile, prueba restante de F-SEC03-05, `JoinViews`.
5. **P5:** F-SEC01-08 y R3-06 (higiene del repo público).
6. **P6:** SEC-05 (regresión consolidada y disposición del propietario).

Esto respeta el gate declarado por el propietario: seguridad crítica y alta cerrada, UAT completa, aceptación de la Cámara y
autorización explícita.

## 7. Consultas y comandos de hoy (todos de solo lectura)

- [`sonda_solo_lectura.php`](../cira/task0033/bd/sonda_solo_lectura.php) → [`evidencia/bd_sonda_1.json`](evidencia/bd_sonda_1.json):
  roles, permisos, vistas, RLS, huérfanas.
- [`sonda_solo_lectura_2.php`](../cira/task0033/bd/sonda_solo_lectura_2.php) → [`evidencia/bd_sonda_2.json`](evidencia/bd_sonda_2.json):
  políticas, permisos `*_role`.
- `monitor_check.sh` y cabeceras HTTP → [`evidencia/vivo_2026-11-17.md`](evidencia/vivo_2026-11-17.md).
- Transacción `READ ONLY` confirmada en cada sonda (`transaction_read_only = on`), terminada en `ROLLBACK`.
