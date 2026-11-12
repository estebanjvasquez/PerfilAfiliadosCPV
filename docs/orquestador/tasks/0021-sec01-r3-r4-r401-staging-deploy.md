# TASK-0021 — SEC-01: revisión final de R4-01 e integración/despliegue de R3+R4+R4-01 solo en staging

| Campo | Valor |
|---|---|
| Fuente | Issue #2, comentario [`6823247114`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6823247114) (2026-11-12 12:11Z), asignación explícita del propietario. Respuesta inicial: `6823719298` |
| Estado | ~~IN_PROGRESS~~ → **READY_FOR_REVIEW** (2026-11-12 ~12:55Z) |
| Alcance | Revisar F-SEC01-R4-01; integrar **solo** R3+R4+R4-01 sobre lo que corre en staging; preflight (respaldo, ensayo de restauración, rollback, regresión); desplegar **solo** en staging si todo pasa |
| Fuera de alcance | producción, `main`, rollback o retiro de SEC-03, Cloudflare/HSTS, n8n, taxonomía, secretos, datos masivos, restaurar permisos perdidos, cambiar roles reales |
| Rama de trabajo | `integrate/sec-01-r3-r4-r401-staging` (worktree aparte), desde `9d0c24d` |
| Rama documental | `fix/sec-01-r4-01-role-editor-preserve` (no es lo desplegado) |
| **BASE** (staging antes) | `9d0c24d613fb32d9a0ff91c5cc9d0e4b7d036823` (verificado en el servidor, ~12:15Z; HSTS `max-age=86400`) |
| **TESTED_HEAD = REVIEW_HEAD = HEAD desplegado** | `71e8a6d2f4922253174c4ac4bd6fd39074c00555` |
| Workflow | `43220318614` **success** (12:42:08–12:42:36Z), push *fast-forward* `9d0c24d..71e8a6d` a `feature/upgrade-filament-v3` |
| Evidencia fails-before de la revisión | `audit/task-0021-r401-fails-before` @ `c5a9f8e` (prueba de revisión sobre `b92dfb8`, base de R4-01 cuyas páginas solo heredan de Shield) |

## Aceptación (del comentario fuente)

1. Revisión de R4-01 con pruebas aisladas: ocultos conservados, solo cambian los visibles modificados, `super_admin` protegido, gestión de roles exclusiva de `super_admin`, fallos y transacciones. Si no pasa, STOP.
2. Candidato con solo R3+R4+R4-01, comparado contra `9d0c24d`: no revierte SEC-03 ni otras funciones.
3. Preflight: respaldo verificado, restauración ensayada en aislamiento (con la FK preexistente), plan de rollback, suite completa sobre el candidato integrado. Si algo falla, STOP sin desplegar.
4. Despliegue solo en staging por el mecanismo existente; validación con cuentas sintéticas; SEC-03 sigue efectiva.
5. Matriz PASS/FAIL/BLOCKED, SHAs, respaldo, restauración, pruebas, rollback; entrega READY_FOR_REVIEW o BLOCKED; STOP.

## 1. Fuentes identificadas en el repositorio

`cf01e9c` (lo desplegado en octubre) es el ancestro común de staging y de la rama documental. La rama documental trae además
TASK-0011/0012/0013 y documentos, así que **no** se fusiona entera. Se tomaron solo estos 12 commits de código y pruebas
(`git cherry-pick -x`):

| Ronda | Commits | Aceptación previa |
|---|---|---|
| R3 | `3a3ab56`, `b45fd83`, `7cd796e`, `c6e99ae`, `e95169c` (REVIEW_HEAD `66f5ce8`, solo documentos encima) | `6318588005` |
| R4 | `1b47f1a`, `4953be6`, `7896bbf`, `b51fa3e` (REVIEW_HEAD `97737ba`, solo documentos encima) | `6319407886` |
| R4-01 | `2ec1c6c`, `44da9a3`, `24413d5` (TESTED) | lista para revisión (`6320241495`); revisada aquí |

Commits propios de esta tarea, solo pruebas: `77f5e46` (prueba de revisión de R4-01) y `71e8a6d` (oráculo de servicios de
R3 alineado con la regla vigente; ver §3).

**Hallazgo de integración (registro):** staging ya tenía **parte** de R3 desde `7831e01` («apply TASK-0011 UX fixes to
staging baseline», 2026-10-27), sin que se registrara como despliegue de R3. Tenía el trait `ReauthorizesEmpresaAccess` y
`EmpresaAccess`, pero solo `ServicesRelationManager` lo usaba: `EditEmpresa` y los otros 9 gestores de relación **no**
se re-autorizaban. Con este despliegue quedan **11/11** (verificado en vivo).

## 2. Gate 1 — revisión de R4-01: **PASS**

- **Código revisado** contra el *vendor* fijado (Shield 3.9.10, Filament 3.3.55):
  - `EditRecord::save()` abre la transacción antes de validar; `afterSave` corre dentro, y cualquier `Throwable` revierte;
  - `getFlatComponents()` excluye por defecto los componentes ocultos, y la pestaña de permisos personalizados es `visible()`
    solo con `custom_permissions` (aquí `false`);
  - final = (enviado ∩ casillas que la página renderiza, calculadas en el servidor) ∪ (permisos actuales fuera de ese
    conjunto), con la fila del rol bloqueada; solo se crea un nombre renderizable, con el guard guardado del rol;
  - `RolePolicy`: cada habilidad exige `super_admin` vigente (consulta fresca) **y** el permiso; `PrivilegedRoleDefinitionGuard`
    impide renombrar, mover de guard, borrar o tomar el nombre del rol privilegiado (límite: eventos de Eloquent).
- **Pruebas aisladas** (runner, PostgreSQL desechable): las 7 de R4-01 y las 5 de R4, más 2 de revisión de esta tarea
  (`Sec01Task0021ReviewTest`) a la escala observada:
  - rol **sin usuarios** con 106 permisos ocultos con forma real (`export_*`, `taxonomy_*`, páginas y widgets antiguos,
    recurso no registrado): guardado sin cambios, se conservan todos; desmarcar una casilla visible quita **solo esa**;
  - `super_admin` con 106 ocultos: los conserva todos, incluidos los de administración de roles, y sus membresías.
  - **Fails-before:** las 2 fallan sobre `b92dfb8` (páginas que solo heredan de Shield; se pierden los ocultos) y pasan sobre
    el candidato. Reproduce el defecto histórico (109 ocultos en el rol sin usuarios) y lo distingue de las bajas de casillas
    **visibles** (6/1), que el editor debe seguir aplicando.
- R3 y R4 contra los hallazgos vigentes: F-SEC01-R3-01 queda corregido en todos los componentes; F-SEC01-R3-04 queda
  cubierto por el código de R4 (además de la contención en datos, que sigue: 0 permisos de roles fuera de `super_admin`).

## 3. Gate 2 — candidato acotado y preservación de SEC-03: **PASS**

- `git diff 9d0c24d 71e8a6d`: 31 archivos de `app/` y `tests/` más 1 de prueba nueva. Todo el código de R4 y R4-01 es **idéntico
  byte a byte** a `24413d5`. En los archivos que staging había cambiado después (TASK-0011/0013/0014/0016/SEC-03), el
  candidato solo **añade** líneas: el `use` del trait de R3 en 10 componentes y el registro de la guarda en `AppServiceProvider`.
- Archivos de SEC-03 (`config/security.php`, `app/Support/Http`, `app/Http`, `User.php`, páginas de autenticación,
  `tests/TestCase.php`, pruebas Sec03), `resources/`, `config/`, `routes/`, `database/`, `composer.*` y `docker/`: **sin cambios**.
  Sin migraciones; el workflow no ejecuta seeders ni `shield:generate`.
- **Un conflicto**, trivial: `ServicesRelationManager` ya tenía el trait de R3 (de `7831e01`); se conservó la versión de staging.
- **Conflicto semántico resuelto solo en pruebas:** el oráculo de R3 `services_tab_is_read_only…` esperaba la pestaña de servicios
  de solo lectura. La decisión del propietario del 2026-11-07 (TASK-0013, ya en staging) volvió a hacer editables los servicios
  propios. Se ajustó el oráculo a la regla vigente, sin bajar la exigencia de R3: siguen probándose las acciones de catálogo
  inexistentes y forzadas, el vínculo de otra empresa inalcanzable y el catálogo sin cambios. La regla nueva ya la cubre
  `CompanyServicesAndSectorsTest` (TASK-0011/0013).

## 4. Gate 3 — preflight: **PASS**

| Control | Resultado |
|---|---|
| Mecanismo y estado vivo | push a `feature/upgrade-filament-v3` → `deploy-contabo.yml`. Servidor en `9d0c24d`, árbol limpio (solo assets generados), contenedores arriba, `HSTS_MAX_AGE=86400` |
| Respaldo | `/root/backups/sec01-t21-20261112T123419Z` (700/600, fuera de git y del webroot): `.env`, imagen `perfilafiliados-app:pre-sec01-9d0c24d` (`f2998c8e2d86`), `pg_dump -Fc -n public` (exit 0, 0 errores; las líneas de `pg_dump.err` son solo la descarga de la imagen), sha256 verificado (`4313a1b2…`), 1109 entradas de TOC, conteos de 88 tablas (41 499 filas), línea base de permisos por rol (conteos y md5 de nombres con `COLLATE "C"`) |
| Ensayo de restauración (aislado) | PostgreSQL 17 + pgvector desechable, red `--internal`, sin puertos; extensiones en su esquema original. **88 tablas, 0 diferencias de conteo**; 133 migraciones; 0 índices inválidos; línea base de permisos **idéntica** por rol (permisos, miembros, md5). Contenedores eliminados |
| FK preexistente | `contact_empresa_contact_id_foreign` no se crea en la restauración: **2 filas huérfanas** (dato vivo, sin modificar). **Procedimiento probado:** recrearla con `NOT VALID` (la definición sale del propio volcado): queda `validated=false`, **rechaza filas nuevas** inválidas y conserva las 2 antiguas. Con ese paso, la restauración es viable; **no** es íntegra en sentido estricto mientras existan las 2 filas (su limpieza es una decisión aparte) |
| Suite completa sobre el candidato | **794 OK** (8460 aserciones) en el primer intento, run `20261112T122348Z-71e8a6d2f492`; línea base `9d0c24d` 767/767; staging 200 antes y después; sin contenedores remanentes. Incluye SEC-03 (recuperación, Host/proxies, Turnstile, HTTPS/HSTS), autorización y roles |
| Plan de rollback | **R1 (inmediato):** en el servidor, `git reset --hard 9d0c24d`, retag `pre-sec01-9d0c24d` → `latest`, `up -d`, `config:cache` y `view:cache`, reload de nginx; `.env` no cambió (copia en el respaldo). **R2 (persistente):** `git revert` de `9d0c24d..71e8a6d` empujado a `feature/upgrade-filament-v3`, sin *force-push*. Sin migraciones que revertir. **Umbrales:** pérdida de permisos, elevación, regresión de SEC-03, pérdida de acceso de `super_admin`, 5xx sostenidos, rutas distintas de 200 o bucle https. Responsable: el ejecutor, con aviso al propietario |

## 5. Gate 4 — despliegue y validación en staging: **PASS** (con un hallazgo residual, §6)

- **Despliegue:** 12:42:04Z, push *fast-forward*; workflow `43220318614` success. Servidor: `git rev-parse HEAD` = `71e8a6d`,
  árbol limpio, imagen nueva (`270bb64ece9a`), debug y mantenimiento apagados.
- **Salud:** `/admin/login`, `/admin/password-reset/request`, `/admin/recuperar-acceso` y `/` en 200; `/admin/shield/roles`
  sin sesión → 302 a login; 0 respuestas 5xx en nginx desde el despliegue; 0 errores nuevos en el log de Laravel (81 antes y
  después, todos previos).
- **SEC-03 sigue efectiva:** `Strict-Transport-Security: max-age=86400` (sin `includeSubDomains` ni `preload`); http → 301 a
  https en el host canónico; Host ajeno tras el borde → 400. Sin cambios de `.env`, Cloudflare, nginx ni túnel.
- **Validación del editor con cuentas sintéticas** (script en el contenedor por el mismo camino que `/livewire/update`:
  `HandleComponents::mount` + `update`; **una transacción que siempre se revierte**, caché, sesión y correo en memoria, de modo
  que nada persiste ni se escribe la caché compartida; roles reales solo **leídos**):

| Comprobación | Antes (`9d0c24d`, editor de Shield) | Después (`71e8a6d`, editor de la app) |
|---|---|---|
| Casillas renderizadas = opciones de Shield | 386 = 386 | 386 = 386 |
| Rol sintético (60 ocultos reales + 5 visibles), guardado sin cambios | **pierde 60/60 ocultos** (defecto reproducido en vivo) | **conserva 60/60**, conjunto idéntico |
| Desmarcar una casilla visible | — | quita exactamente esa |
| Nombre oculto real, nombre forjado e id por un campo visible | — | nada concedido; ningún permiso creado |
| No `super_admin` con **todos** los permisos de roles como directos | — | política: no; página: rechazada |
| Tomar el nombre del rol privilegiado / crearlo en otro guard (rol sintético) | — | rechazados; `super_admin` intacto |
| R3: componentes que se re-autorizan en cada petición | 1/11 | **11/11** |
| Transacción revertida: totales idénticos y 0 filas sintéticas | sí | sí |

- **Acceso real (solo lectura, agregados):** 4/4 miembros de `super_admin` administran roles; **0/364** otros usuarios;
  0 permisos de roles fuera de `super_admin`; 0 permisos directos.
- **Exposición que esto cierra:** en vivo, los permisos que el editor anterior quitaría al guardar eran 116 en `super_admin`,
  **35 en el rol de afiliados (324 usuarios)** y 1 en `reportes`. Ningún rol real se guardó en esta tarea.

## 6. Hallazgo residual: F-SEC01-T21-01 (Baja, funcional/higiene; sin pérdida ni elevación)

Al publicar el recurso en `App\Filament\Resources\Shield`, Shield le asigna la clave **`shield::role`**. La sección «Role»
del editor muestra ahora `view_shield::role`, `update_shield::role`, etc. (6 casillas), que **no existen** y que
`RolePolicy` no usa. Los 6 permisos reales `*_role` de `super_admin` pasan a ser ocultos (por eso el editor muestra 122 sin
casilla en lugar de 116) y **se conservan** en cada guardado.

- **Efecto:** las casillas de esa sección no hacen nada y aparecen desmarcadas incluso en `super_admin`; marcarlas crearía
  nombres inútiles (hoy 0). No hay elevación: el editor ya no puede conceder `*_role` a nadie, lo que refuerza la contención de R4.
- **No detectado en aislamiento:** las pruebas de R4-01 usan los campos de Empresa y User, no el de Role.
- **No cumple ningún umbral de rollback.** Corregirlo exige un cambio de código nuevo, fuera de esta asignación; no se tocó.
- **Propuesta:** fijar la clave de entidad del recurso publicado en `role` (o el ajuste equivalente de Shield), con una prueba
  que compare los nombres renderizados con los que usa `RolePolicy`. Hasta entonces, no marcar las casillas de la sección «Role».

## 7. Matriz

| Componente | Aislado | Staging |
|---|---|---|
| R3 (F-SEC01-R3-01, re-autorización) | PASS (suite; oráculo de servicios alineado con la regla del 2026-11-07) | PASS (11/11 componentes); revocación con sesiones reales no ejercida |
| R4 (administración solo `super_admin`, identidad protegida) | PASS | PASS (política, página, guarda; 4/4 y 0/364) |
| R4-01 (conservar ocultos; solo cambian los visibles) | PASS (incluido fails-before a escala) | PASS (antes 0/60 → después 60/60) |
| SEC-03 (recuperación, Host/proxies, Turnstile, HTTPS/HSTS) | PASS (suite completa) | PASS (cabeceras, redirección, Host ajeno, rutas) |
| Respaldo / restauración / rollback | — | PASS / PASS con `NOT VALID` para la FK / preparado, no usado |
| F-SEC01-T21-01 | no cubierto | **abierto** (Baja), §6 |

## 8. Riesgos residuales y UAT pendiente

- F-SEC01-T21-01 (§6).
- La guarda del rol privilegiado no cubre SQL directo, actualizaciones masivas, `saveQuietly` ni `withoutEvents` (límite ya registrado en R4).
- **Recuperación de permisos perdidos en octubre: no tocada** (decisión independiente). Ahora el editor ya no los volvería a borrar.
- 2 filas huérfanas en `contact_empresa` (sin modificar).
- **UAT del propietario pendiente:** abrir en el navegador la lista y la edición de roles como `super_admin` y comprobar
  las secciones; guardar solo un rol de prueba, no los reales. Gestión de usuarios con sus guardas habituales.
- SEC-01 sigue abierto (R3-02, R3-03, relation managers y búsquedas restantes, F-03/F-06). Producción y `main`, sin autorización.
