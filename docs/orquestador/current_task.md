# Tarea actual (checkpoint 2026-10-21 ~14:00Z, rama `audit/sec-01-r3-company-boundaries`)

El estado general y el arranque están en [`SESSION_RESUME.md`](SESSION_RESUME.md). La historia de este archivo
hasta hoy está en [`history/current_task_hasta_2026-10-16.md`](history/current_task_hasta_2026-10-16.md).

## CPV-SEC-01-R3 — READY_FOR_REVIEW — MANUAL (`6316775771`), sin desplegar

| Campo | Valor |
|---|---|
| Rama / BASE / TESTED | `audit/sec-01-r3-company-boundaries` / `eb03b4c` / `e95169c` (suite completa **624 OK**) |
| Fails-before | `audit/sec-01-r3-fails-before` @ `9fb0469` (solo evidencia): fallan las 3 pruebas de re-autorización |
| Corregido | F-SEC01-R3-01: `EditEmpresa` y sus 10 gestores re-autorizan en cada petición (`EmpresaAccess`, `ReauthorizesEmpresaAccess`) |
| Registrado | R3-02 (permisos de módulo en acciones propias), R3-03 (categoría inactiva por id forjado), **R3-04 (Alta, alerta: editor de roles)**, Q-05, Q-06 |
| Matrices | 7 gestores; búsquedas en Customer, TaxonomyCandidateTerm y Role (no buscable) |
| Cobertura restante | 14 relation managers (recuento; 13 según el denominador anterior); 9 búsquedas |
| Restringido | sonda y salida en `%USERPROFILE%\.cpv-restricted\sec-01\r3\` (fuera de git) |
| Informe | `audit/security_sec01_r3_2026-10-21.md`; registro `docs/security/SECURITY_STATUS.md` |

## CPV-SEC-01-R1-R2-DEPLOY — desplegado, **UAT del propietario PASS** (`6316265832`, registrado en `6316775771`)

| Campo | Valor |
|---|---|
| Aceptado / desplegado | `cf01e9c3e30415a34ccda60b312a993c1388167d` (aceptación `6310331211`, FINAL R2 REVIEW) por push normal fast-forward desde `87dd997` |
| Workflow | run `39602544527` success, 12:48–12:50Z |
| Runtime | checkout `cf01e9c` (versionados limpios), imagen `081c35c5715d`, debug y mantenimiento apagados, sin migraciones pendientes |
| Recuperación | `perfilafiliados-app:pre-sec01-87dd997` (`f91e97322189`) → `latest`, `git reset --hard 87dd997`, `docker compose up -d app` (no hizo falta) |
| Misma conexión | cuenta y rol/pivote → `pgsql` (antes y después) |
| Solo lectura | `docs/deploy/smoke_sec01_readonly.php` 17/17 (antes 3/17); export 25/25, 0010C 18/18, perfil 13/13; HTTP: login 200, `/form` 404 |
| Sin probar en staging | escrituras de cuentas, rollback, clic de suplantación; evidencia = suite aislada `f4dd8fb` (611 OK) |

**UAT del propietario (solo con sus cuentas habituales; no intentar escalar privilegios en cuentas reales):**
1. Como super admin: **Usuarios** muestra la lista y la acción «Impersonate» sigue disponible.
2. Con una cuenta de staff que no es super admin (si la tiene): la acción «Impersonate» ya no aparece.
3. Editar el nombre de una cuenta de prueba designada y guardar: el cambio queda.
4. `https://pruebas.camarapetrolera.app/form` → «Página no encontrada».

## CPV-SEC-01-R2-ACCOUNT-PATH — aceptada (`6310331211` FINAL), desplegada arriba (corrección de atomicidad R2-REV-05)

| Campo | Valor |
|---|---|
| Fuente | sección «SECOND REVIEW UPDATE — NARROW ATOMICITY CORRECTION» de `6310331211` |
| Arreglo | `UsesAccountWriteTransaction` en `CreateUser` y `EditUser`: `hasDatabaseTransactions()` = true solo en esas páginas; begin/commit/rollback en las conexiones de `User` y del modelo de rol (pivote). Guarda previa a la sincronización intacta; comentarios «ya atómico» corregidos |
| Pruebas | `AccountWriteAtomicityTest` (5). Antes `309146b`: 3/5 fallan. Después `f4dd8fb`: enfocadas **45 OK** (1.058); suite completa **611 OK** (7.042), staging 200 antes/después |
| REVIEW_HEAD | el commit de docs sobre `f4dd8fb` (ver el comentario de entrega) |

### Pausa anterior (`6313400617`), ya superada

Pausa por cuota. Entrega previa `6311569828`.

| Campo | Valor |
|---|---|
| REVIEW_HEAD | `51a923ee0e8ea3fb8c9b0566bd53de4ca6e0f078` (runtime = `e7ba59d`; encima solo docs) |
| Probado | suite completa `6bf8582aa8e7155c10ca560fb6089d7b5bef73ef` (606 OK); enfocadas `e7ba59d9deb35198bc9fb3fac857255ebaa9e0e2` (22 OK). Los informes son evidencia de ejecución del desarrollador, no una revisión independiente |
| Estado | segunda revisión: **CAMBIOS SOLICITADOS**, no aceptada, sin desplegar |
| Próxima corrección (acotada) | activar explícitamente las transacciones en `CreateUser` **y** `EditUser` sobre la conexión real de cuentas/pivote; pruebas de inyección de fallo con rollback comprobado antes del teardown; conservar la autorización previa a la sincronización |
| Fuente | **solo** la sección «SECOND REVIEW UPDATE — NARROW ATOMICITY CORRECTION» de `6310331211` |
| Por qué | Filament v3.3.55 (fijado) deja las transacciones del panel desactivadas por defecto y este candidato no las activó; la afirmación «ya atómico» era incorrecta (retirada en el registro) |
| Progreso revisado que se mantiene | guarda previa a la sincronización; pruebas de rutas con canario y texto de PDF; matriz independiente de alto valor con fallo ante errores inesperados; registro acumulativo |
| Cobertura y decisiones | sin re-inventariar: conteos existentes; F-02 editor de roles / F-03 / F-06 del propietario (ver registro) |

| Campo (entrega anterior) | Valor |
|---|---|
| Base / rama | `0139e95` (F-01 congelado) / `fix/sec-01-account-path-boundaries` |
| Fix (R2-REV-01) | concesión de roles autorizada ANTES de sincronizar (`PrivilegedRoleGuard` en `saveRelationshipsUsing`); apertura de cuenta privilegiada bloqueada en `authorizeAccess`; `/form` removido (F-04); método redundante quitado (F-05 no reproducido) |
| Pruebas | antes (`3e311f8`, enfoque viejo): la prueba de frontera de persistencia falla (el rol se escribía y se retiraba); después (`e7ba59d`): enfocadas 22 OK; suite completa `6bf8582` **606 OK** (7.016) |
| Traversal (R2-REV-02) | canario fuera de raíz; `{empresa}` todo 404 con capa registrada, `secciones` perfil completo con texto de PDF decodificado; sin hallazgos |
| Matriz (R2-REV-03) | oráculo de negocio allow/deny en entradas de alto valor; 5xx inesperado falla salvo JoinViews |
| Registro | `docs/security/SECURITY_STATUS.md` (R2-REV-04) |
| F-01 | **sigue SIN desplegar** (`87dd997`); riesgo vivo persiste |
| Decisiones | F-02 editor de roles / F-03 / F-06 del propietario; lote de relation-managers/búsqueda pendiente |
| Informe | `audit/security_sec01_r2_2026-10-21.md` |

## CPV-SEC-01 R1 — F-01 aceptado (`6293112309` / `6293203444`), sin desplegar

| Campo | Valor |
|---|---|
| Base | `4a56faf` (runtime `87dd997` + docs del plan) |
| Ramas | `audit/security-access-01` (pruebas, informe, checkpoint) ⊃ `fix/sec-01-impersonation-gate` (`613c359`: solo un super admin puede suplantar) |
| Pruebas | antes del arreglo (`ab0464b`): 1 falla esperada; después (`7d8531e`): suite completa **593 OK**; enfocadas `4819877`: 25 OK |
| Cobertura | 131 rutas, 30 recursos, 29 páginas, 151 componentes; matriz de 9 actores × 88 entradas (792 pedidos, 0 violaciones) |
| Registro | F-01 corregido; F-02/F-03 (datos de roles) y F-06 son decisiones del propietario; F-04/F-05 para la próxima ronda (`audit/security_sec01_2026-10-20.md`) |
| Restringido | evidencia cruda y mecanismos fuera de git, en el almacenamiento local del ejecutor; entrega a pedido por canal privado |

Gerencia (`planning/management-taxonomy`) en espera hasta aceptar la seguridad; no se promueve esa rama.

## CPV-PDF-OPERATION-SECTION — DEPLOYED_FOR_OWNER_REVIEW — MANUAL (`6288459314`)

| Despliegue | Valor |
|---|---|
| Desplegado | `87dd997` por push normal (fast-forward desde `514fa6e`); workflow `39405700502` success |
| Runtime | checkout `87dd997`, imagen `f91e97322189`, mantenimiento apagado, `app.debug=false`, `mail.default=log`, sin migraciones pendientes |
| Recuperación | `perfilafiliados-app:pre-opsection-514fa6e` (`7a557f27edff`) → `latest`, `git reset --hard 514fa6e`, `docker compose up -d app` (no hizo falta) |
| Smokes | perfil 13/13 (11 secciones), descargas 25/25, 0010C 18/18, selector y manual OK; HTTP público OK (`audit/runs/pdf_operation_section/deploy/`) |

**UAT del propietario (solo su empresa):**
1. **Empresas** → **Elegir secciones** → **Solo las secciones que elija**: 11 secciones marcadas, cada una con su descripción (Recursos, Presencia y Gestión nombran sus bloques).
2. Deje solo **Datos generales y contactos** → **Descargar PDF**: trae datos generales y contactos, **sin** «Operación en Venezuela».
3. Deje solo **Operación en Venezuela**: trae solo facturación, empleados (rango y total) y estatus, sin datos generales ni contactos; el encabezado sigue con nombre y RIF.
4. Marque las dos: cada bloque una vez. **Perfil completo (PDF)**: todo, con Operación después de Contactos.
5. Manual → **Descargar el perfil**: la tabla de las 11 secciones coincide con el selector.

### Revisión (`6286644396`, aceptada en `6288445203`)

| Campo | Valor |
|---|---|
| Rama / BASE | `fix/pdf-operation-section` / `9253968` (runtime idéntico a `514fa6e`) |
| Cambio | `operacion` es una sección propia, tras `generales` (11 en total); `SECTION_DESCRIPTIONS` en el selector y en el manual; límite de texto derivado de las claves |
| TESTED_HEAD | `3ffa85d`: enfocadas **35 OK** (2.615 aserciones); las 11 opciones solas, ambas, las 11 y por defecto (`audit/runs/pdf_operation_section/`) |
| Informe | `audit/pdf_operation_section_2026-10-20.md` |

## CPV-COMPLETE-COMPANY-PDF — DEPLOYED_FOR_OWNER_REVIEW — MANUAL (`6286221372`)

| Despliegue | Valor |
|---|---|
| Desplegado | `514fa6e` por push normal (fast-forward desde `ce70b7f`); workflow `39390032508` success |
| Runtime | checkout `514fa6e`, imagen `7a557f27edff`, mantenimiento apagado, `app.debug=false`, `mail.default=log`, sin migraciones pendientes |
| Recuperación | `perfilafiliados-app:pre-dossier-ce70b7f` (`aa44b1884b93`) + `git reset --hard ce70b7f` (no hizo falta) |
| Smokes | perfil completo 13/13, descargas 25/25, 0010C 18/18; HTTP público OK. Evidencia `audit/runs/complete_company_pdf/deploy/` |

**UAT del propietario (solo su empresa):**
1. **Empresas** → en su fila, **Perfil completo (PDF)** → se descarga `perfil-<RIF>.pdf` con todas las secciones.
2. Revise que **cada página** tenga la franja amarilla con logos, el título, el nombre y el RIF, y abajo «Página N de M». Revise que sus secciones (datos, recursos, experiencia, presencia, gestión, categorías CPV) muestren lo registrado, «Marcado no aplica por el afiliado» o «No hay información registrada».
3. **Elegir secciones** (ícono junto al botón) → **Solo las secciones que elija** → deje dos → **Descargar PDF** → `perfil-<RIF>-parcial.pdf`, que lista las secciones incluidas y omitidas.
4. Marque su fila → arriba, **Exportar listado**: es solo la tabla, no el perfil.
5. La ficha de la empresa → **Descargar perfil completo (PDF)**.

## Antes del despliegue (aceptado en `6286080370`)

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
