# Tarea actual (checkpoint 2026-10-19 ~11:10Z, rama `fix/export-access`)

El estado general y el arranque están en [`SESSION_RESUME.md`](SESSION_RESUME.md). La historia de este archivo
hasta hoy está en [`history/current_task_hasta_2026-10-16.md`](history/current_task_hasta_2026-10-16.md).

## CPV-INTEGRATED-EXPORT-ACCESS-DEPLOY — BLOCKED — MANUAL (`6265502896`)

| Campo | Valor |
|---|---|
| Autorización | `6265502896` (propietario, 2026-10-19 10:51Z): desplegar `ce70b7f` a staging (aceptado en `6265458863`) |
| Verificado antes | staging `0f8bdad`, checkout limpio, contenedores arriba, **sin migraciones pendientes**, sin workflow en curso (último run `38873194879` success); `ce70b7f` es fast-forward de `0f8bdad`; el diff no toca `database/` |
| Recuperación | `perfilafiliados-app:pre-exportaccess-0f8bdad` (= imagen en ejecución `37e023fd6830`), creada antes de promover |
| Bloqueo | el clasificador de permisos rechazó el push a `feature/upgrade-filament-v3` (`Production Deploy`). No se rodeó |
| Comando del propietario | `git push origin ce70b7f3345f5d196e2e0190356ed8a6e2e091b4:refs/heads/feature/upgrade-filament-v3` (fast-forward, sin `--force`); el workflow despliega solo, porque el diff toca `app/`, `routes/`, `resources/views` |
| Estado vivo | sin cambios: `0f8bdad`, login 200; las descargas siguen abiertas en staging hasta desplegar |
| Siguiente acción | el propietario ejecuta el push (o autoriza la regla); después, una ronda acotada de verificación de solo lectura posdespliegue |

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
