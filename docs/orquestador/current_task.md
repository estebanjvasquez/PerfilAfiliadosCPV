# Tarea actual (checkpoint 2026-10-20 ~07:50Z, rama `fix/complete-company-pdf`)

El estado general y el arranque están en [`SESSION_RESUME.md`](SESSION_RESUME.md). La historia de este archivo
hasta hoy está en [`history/current_task_hasta_2026-10-16.md`](history/current_task_hasta_2026-10-16.md).

## CPV-COMPLETE-COMPANY-PDF — ACEPTADO PARA PREPARAR DESPLIEGUE (`6286080370`), candidato `514fa6e09c666a6eefa8178bf56ea58da48b1a20`, DEPLOYED: NO

| Campo | Valor |
|---|---|
| Autorización | `6270558322` (propietario, 2026-10-19 15:11Z): perfil completo visible y por defecto, secciones opcionales; **no** despliegue |
| Rama / BASE | `fix/complete-company-pdf` / `37503c8` (checkpoint sobre el runtime desplegado `ce70b7f`) |
| Causa | la columna «PDF» era `IconColumn::make('')`: estado null, **ningún ícono visible**. Lo único visible era el exportador de la tabla (listado). Además, `->spa()` convertía el enlace directo en `wire:navigate` y no descargaba |
| Arreglo | acciones de fila «Perfil completo (PDF)» y «Elegir secciones» (antes de las columnas), «Descargar perfil completo (PDF)» en la ficha, «Exportar listado» rotulado aparte; `pdf.blade.php` reparado (mismo diseño, sin consultas, «Sí/No», No aplica por sub-tipo, Categorías CPV, contactos, experiencia y presencia leídas de los modelos); `?secciones=` con lista blanca |
| TESTED_HEAD | `88713a70171ca80903fedac74cd4507498ad8718`: suite completa **582 OK**, enfocadas 109 OK, navegador 16/16 |
| Informe | `audit/complete_company_pdf_2026-10-19.md`; evidencia `audit/runs/complete_company_pdf/` (PDF sintéticos, texto, páginas PNG) |
| PDF-01 (`6272035810`) | encabezado por página. La evidencia publicada en `034cf11` tenía el encabezado en las 7 páginas (bytes, texto por página, logos, píxeles): no se reprodujo. Defecto real hallado: nombre de 100 caracteres → la franja crecía hasta ~104 pt; y una descripción más larga que una página dejaba la página 1 casi en blanco. Ambos corregidos en `pdf.blade.php`. Prueba nueva por página. TESTED_HEAD `0392a14`, enfocadas 34 OK (1.288 aserciones). Evidencia `audit/runs/complete_company_pdf/pdf01/` |
| Siguiente acción | esperar la instrucción de despliegue del propietario; no tocar el candidato. La observación original de PDF-01 fue retirada por el revisor (no es un defecto) |

## CPV-INTEGRATED-EXPORT-ACCESS-DEPLOY — DEPLOYED_FOR_OWNER_REVIEW — MANUAL (`6265502896`)

| Campo | Valor |
|---|---|
| Promoción | el **propietario** hizo el fast-forward de `feature/upgrade-filament-v3` a `ce70b7f` |
| Por qué no el agente | su push fue **rechazado por el clasificador de permisos** (`Production Deploy`). No hubo pérdida de acceso y no se rodeó (sin SSH, dispatch ni otra vía); credenciales y permisos sin cambios |
| Workflow | run `39249485948`, `success`, head `ce70b7f`, 12:06Z |
| Estado vivo (~12:20Z, solo lectura) | checkout `ce70b7f` limpio; `app` (imagen `aa44b1884b93`) y `nginx` arriba; mantenimiento apagado; sin migraciones pendientes; `app.debug=false`; `mail.default=log` |
| Smoke | `docs/deploy/smoke_export_access_readonly.php` **25/25**: rutas, middleware del panel + `EnsureEmailIsVerified`, `EmpresaPolicy::export`, autoload de modelos, invitado → login, `/pdf` y `/xls` solo redirigen, URL desconocida → 404 «Página no encontrada», sin verificar → pantalla de verificación 200, verificado sin acceso → mismo 404 «Archivo no disponible» (HTML y JSON), 0 consultas a vistas de reporte, 0 correos. Usuarios en memoria, ids falsos (`999999999`, `abc`), transacción READ ONLY. `smoke_task_0010c_readonly.php` **18/18** |
| HTTP público | `/admin/login` 200; `/no-existe` 404; `/pdf/…` y `/xls/…` → 302 a la ruta protegida → 302 al login |
| Recuperación | `perfilafiliados-app:pre-exportaccess-0f8bdad` (imagen de `0f8bdad`) + `git reset --hard 0f8bdad` |
| CDN | reglas de Cloudflare **no** inspeccionadas; las cabeceras nuevas no purgan copias viejas. Revisar y, si corresponde, purgar `/pdf/*` y `/xls/*` es acción del propietario |

**Guía de UAT del propietario:**
1. Entrar al panel, ir a Empresas y usar «PDF» de **su** empresa: abre `perfil-<RIF>.pdf`.
2. En una ventana privada, abrir `/admin/descargas/empresas/abc/pdf`: lleva al login. Ya con sesión, la misma dirección muestra «Archivo no disponible»; `/no-existe` muestra «Página no encontrada».
3. Una cuenta sin verificar (solo una de prueba designada) ve «Verifique su correo electrónico».
4. «No aplica» y el primer guardado: solo sobre datos de prueba designados, nunca sobre registros de clientes.
5. **Sin correo:** un afiliado nuevo no puede verificarse solo hasta activar el correo, en una ronda aparte.

## Bloqueo previo en la misma tarea (resuelto por el propietario)

| Campo | Valor |
|---|---|
| Autorización | `6265502896` (propietario, 2026-10-19 10:51Z): desplegar `ce70b7f` a staging (aceptado en `6265458863`) |
| Verificado antes | staging `0f8bdad`, checkout limpio, contenedores arriba, **sin migraciones pendientes**, sin workflow en curso (último run `38873194879` success); `ce70b7f` es fast-forward de `0f8bdad`; el diff no toca `database/` |
| Recuperación | `perfilafiliados-app:pre-exportaccess-0f8bdad` (= imagen en ejecución `37e023fd6830`), creada antes de promover |
| Bloqueo | el clasificador de permisos rechazó el push a `feature/upgrade-filament-v3` (`Production Deploy`). No se rodeó; informado en `6266745548` |
| Comando del propietario | `git push origin ce70b7f3345f5d196e2e0190356ed8a6e2e091b4:refs/heads/feature/upgrade-filament-v3` (fast-forward, sin `--force`); ejecutado por el propietario |

## CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES — aceptado (`6265458863`), sin desplegar

| Campo | Valor |
|---|---|
| Autorización | `6264837159` (propietario, 2026-10-19 10:12Z): reparación + 404 útil + comprobar la pantalla de verificación; **no** despliegue |
| Rama / BASE | `fix/export-access` / `dc7972821ad9d826a7d3922f67c63aea1019bec6` (revisión sobre el runtime aceptado `edf20ed`) |
| Runtime observado | staging `0f8bdad`, todavía con las rutas abiertas; `app.debug=false`; detrás de Cloudflare |
| TESTED_HEAD | `8e01cacf0a9be978244bd32daf08f7355a70caaf` |
| Reglas | invitado → login; sin verificar → verificación; afiliado con `view_empresa` y vinculado → su empresa; lo demás → el mismo 404. No hay excepción para administradores ni `export_any_empresa` |
| Pruebas | `ExportAccessTest` (8) + enfocadas 147 OK + suite completa 576 OK + navegador 42/42, todo en `8e01cac` |
| Informe | `audit/export_access_repair_2026-10-19.md`; evidencia `audit/runs/export_access_repair/` |
| Manual | `#descargar-perfil` (nuevo), errores frecuentes; nota `docs/deploy/DESCARGAS_PERFIL.md` |
| Siguiente acción | revisión manual; desplegar requiere autorización. Después de desplegar, el propietario evalúa la purga de Cloudflare |

## Pendientes fuera de esta ronda

- correo/SMTP (sin envío, un afiliado nuevo no puede verificarse solo);
- `JoinViews`: su vista falla con empresas incompletas, y su acción Pdf no existe;
- datos históricos de `assets` (solo propuesta);
- el backlog de `SESSION_RESUME.md` §5.
