# TASK-0022 — SEC-01: corregir F-SEC01-T21-01 (sección «Role» del editor) y evaluar la recuperación de permisos de octubre

| Campo | Valor |
|---|---|
| Fuente | Issue #2, comentario [`6829151868`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6829151868) (2026-11-12 16:17Z), asignación del propietario; decisiones en el veredicto [`6829143372`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6829143372) (TASK-0021 APPROVED con reservas) |
| ID | TASK-0022: siguiente libre verificado (archivos de `tasks/`, `current_task.md`, ramas locales y remotas, `git log --all`) |
| Estado | ~~IN_PROGRESS~~ (2026-11-12 ~16:30Z) → **READY_FOR_REVIEW** (~17:05Z). **Sin desplegar**; sin escrituras reales |
| **TESTED_HEAD** (código y pruebas) | `4bdec36a7828486e80840a47fab1212c7f813599` |
| **REVIEW_HEAD** | `270e07dcda8c61840c569b5c85cbad471ce9bf82` (= TESTED + solo la ayuda de administración) |
| Fails-before | `audit/task-0022-role-section-fails-before` @ `23b5c0d` (la prueba final sobre `71e8a6d`, sin la corrección) |
| No reabre | TASK-0021 (sigue APPROVED con reservas; su texto no cambia) |
| Parte A | Corregir solo las seis casillas falsas `*_shield::role` para que la sección «Role» muestre nombres que `RolePolicy` usa. Candidato y pruebas en rama aislada; **STOP antes de push a la rama runtime o deploy** |
| Parte B | Análisis y propuesta de recuperación de permisos perdidos en octubre, **sin escrituras reales**: lista exacta por rol y permiso, contraste con la política vigente, alternativas automatizada mínima y manual, con coste, riesgo, respaldo y rollback |
| Prohibido | producción, `main`, HSTS/Cloudflare/nginx/túnel, n8n, taxonomía, secretos, escrituras de permisos reales, membresías o datos, recuperación masiva, migraciones, deploy sin gate nuevo |
| BASE código | `71e8a6d2f4922253174c4ac4bd6fd39074c00555` (staging, TASK-0021); documentación `caa9fe4fbd457c47c7f0e14034b5723209b8df74` |
| Rama de trabajo | `fix/sec-01-t22-role-permission-key` (worktree aparte, desde `71e8a6d`) |

## Aceptación (del comentario fuente)

1. Seis casillas correctas sin regresión de permisos.
2. Gate de seguridad: ningún rol distinto de `super_admin` administra roles; no se pierden ocultos; nada se concede por payload forjado; no se restauran ni conceden permisos reales.
3. Pruebas: fails-before de las seis casillas; regresión de R4/R4-01 (visibles, ocultos, `super_admin`), SEC-03 y suite completa sobre el candidato; antes/después con roles sintéticos y rollback transaccional.
4. Evaluación cuantificada de la recuperación y lista exacta segura/manual.
5. Cero escrituras en roles reales y cero despliegues. READY_FOR_REVIEW o BLOCKED; STOP.

## 1. Verificaciones previas

- Árbol principal: solo los cambios locales del propietario (`AGENTS.md`, `CLAUDE.md`, `PROTOCOL.md`, el §0 de
  `SESSION_RESUME.md` y el `.xlsx` sin seguimiento), **sin tocar**. Trabajo en worktree aparte.
- Staging, solo lectura: `git rev-parse HEAD` en el servidor = `71e8a6d2f4922253174c4ac4bd6fd39074c00555` = HEAD remoto de
  `feature/upgrade-filament-v3`; `/admin/login` 200. Vendor fijado: Shield 3.9.10 (igual en `71e8a6d`).

## 2. Parte A — F-SEC01-T21-01

**Causa (vendor, sin editarlo).** `FilamentShield::getDefaultPermissionIdentifier()` deriva la clave del recurso de su clase,
después del último `Resources\`. El recurso de Shield (`BezhanSalleh\FilamentShield\Resources\RoleResource`) da `role`; el
publicado por R4-01 vive en `App\Filament\Resources\Shield\` y da `shield::role`. La sección «Role» renderizaba entonces
`view_shield::role` … `delete_any_shield::role`, nombres que `RolePolicy` no consulta, y los seis `*_role` reales quedaban
ocultos (conservados por R4-01).

**Corrección** (rama `fix/sec-01-t22-role-permission-key`, desde `71e8a6d`; 4 archivos de `app/`):
- `App\Support\Security\RolePermissionIdentifier`: fija la clave del recurso de roles en `role` con el *hook* de Shield
  (`configurePermissionIdentifierUsing`). Los demás recursos conservan la clave por defecto de Shield, mismos pasos. Shield
  registra su servicio como *scoped*, así que el *hook* se aplica en cada resolución (`afterResolving`), no una sola vez. Se
  registra en `AppServiceProvider::register()` (una llamada).
- `RoleResource`: la sección «Role» muestra los seis `*_role` reales **en solo lectura** (campo deshabilitado, con ayuda).
- `WritesRenderedPermissionsOnly`: un campo deshabilitado no entra en el conjunto editable. Por eso los `*_role` siempre se
  conservan tal como están y cualquier valor enviado para ellos se ignora.
- **Por qué solo lectura y no editable.** Si fueran editables, un `super_admin` podría:
  - darlos a otro rol, lo que deshace la contención de R4 (0 permisos de roles fuera de `super_admin`), aunque `RolePolicy`
    siga negando;
  - quitárselos a `super_admin` y dejar a todos los administradores sin gestión de roles.

  Hasta TASK-0021 estaban ocultos, y por tanto ya eran inmutables desde el editor; esto lo conserva y además los muestra. Si
  el propietario prefiere que sean editables solo en `super_admin`, es un cambio de una línea, pero reabre el riesgo de
  bloqueo.
- Ayuda: `docs/manual_usuario/manual_administrador.md` §9 (REVIEW_HEAD).

**Pruebas** (`Sec01Task0022RoleSectionTest`, runner aislado con PostgreSQL desechable; cada prueba en una transacción que se
revierte; roles y permisos sintéticos):

| Prueba | Fails-before `23b5c0d` (= `71e8a6d` + prueba) | Candidato `4bdec36` |
|---|---|---|
| La sección «Role» renderiza exactamente los 6 `*_role` de los prefijos de Shield, todos usados por `RolePolicy` (leído de su código), ningún `shield::` en todo el editor; `super_admin` los ve marcados; campo deshabilitado | **FAIL**: renderiza los 6 `*_shield::role` | PASS |
| Los demás recursos registrados conservan la clave por defecto de Shield (comparada por reflexión con el método del vendor) | PASS | PASS |
| `super_admin`: guardar sin cambios conserva todo (ocultos incluidos); vaciar las casillas «Role» enviadas no quita nada (sin bloqueo); sigue administrando roles | PASS (ocultos) | PASS |
| Otro rol: enviar los 6 `*_role`, `*_shield::role`, nombres forjados y `update_role` por otro campo → solo se añade la casilla visible editable; ocultos conservados; 0 `Permission` creados; un rol nuevo no recibe `*_role` | PASS (los `*_role` no se renderizaban) | PASS |

Runs: fails-before `20261112T164101Z-23b5c0d0450a` (4 pruebas, 1 fallo, el de las seis casillas); enfocadas
`20261112T164210Z-4bdec36a7828` (R4, R4-01, TASK-0021, matriz de acceso, suplantación, usuarios): **OK 28 pruebas, 341
aserciones**; **suite completa** `20261112T164425Z-4bdec36a7828`: **OK 798 pruebas, 8514 aserciones** (línea base `71e8a6d`
794), que incluye SEC-03. Staging 200 antes y después de cada run; 0 contenedores remanentes. Un primer intento enfocado
falló por un error de la prueba (permiso sintético sin crear); se corrigió en la prueba (`4bdec36`) y el fails-before se rehízo
con la prueba final.

**Antes/después.** En staging (solo lectura, `71e8a6d`): el editor renderiza 386 casillas y los `*_role` no están entre ellas.
En aislamiento: antes, la prueba de las seis casillas falla con los `*_shield::role`; después pasa, y las guardas de R4/R4-01
siguen en verde. Sin despliegue: el antes/después en vivo queda para el gate de despliegue.

**Gate de seguridad: PASS.** Ningún rol distinto de `super_admin` administra roles (política sin cambios, y el editor ya no
puede conceder `*_role`); no se pierden ocultos; nada se concede ni se crea por payload forjado; ningún permiso real tocado.

## 3. Parte B — permisos perdidos en octubre (solo análisis; **ninguna escritura**)

Fuentes:
- la evidencia restringida de octubre (línea base del 2026-10-20 y estado del 2026-10-21, sobre `cf01e9c`);
- una lectura en vivo `READ ONLY` del 2026-11-12, sobre `71e8a6d`, con caché en memoria y solo conteos y nombres de permisos;
- las referencias literales en el código del candidato.

La lista exacta por rol está en las notas restringidas (`T22_RECOVERY_PROPOSAL.md`). Aquí, **R1** es el rol ocupado (1 usuario)
y **R2** el rol sin usuarios.

| Rol | Perdidos | Origen | Clase | Hoy |
|---|---|---|---|---|
| R1 (1 usuario) | 6 | visibles desmarcados (contención de R4) | administración de roles | faltan; **nunca** se restauran |
| R1 | 1 | visible desmarcado en el mismo guardado | `view_any_user` (lista de cuentas) | falta; candidato |
| R2 (0 usuarios) | **109** | **ocultos, retirados por el defecto** | 66 activos (módulos de empresa, catálogos geográficos y de infraestructura) + 42 sin referencia + `export_role` | faltan; sin casilla en el editor actual |
| R2 | 12 | visibles desmarcados | 6 de administración de roles + 6 de gestión de usuarios | faltan |

- **128 vínculos**, todos siguen faltando en vivo y los 128 permisos existen. Los 109 ocultos y los 19 visibles (7 en R1, 12
  en R2) se distinguen por si el editor de entonces los mostraba.
- **Impacto actual:** `super_admin`, el rol de afiliados (324 usuarios) y `reportes` **no perdieron nada**, así que las
  pruebas de la Cámara con esas cuentas no dependen de esto. Solo una cuenta (la de R1) nota hoy la falta (`view_any_user`). R2
  no tiene usuarios.
- **Contra la política vigente:**
  - los 13 de administración de roles (6 de R1, 6 de R2 y `export_role`) **nunca** se restauran;
  - los 42 sin referencia no se restauran;
  - la escritura de usuarios en R2 (4) permite asignar roles no privilegiados;
  - los 66 activos incluyen escritura en catálogos globales (clase F-03).
  - R2 sin usuarios: **0 ahora**; si se reactiva, se decide por bloques.

**Recomendación: restaurar como máximo 1 vínculo** (R1 ← `view_any_user`), y solo si el propietario confirma que quitarlo no
fue intencional.

| Alternativa | Qué | Coste | Riesgo | Respaldo y rollback |
|---|---|---|---|---|
| (i) Automatizada mínima | El ejecutor corre un script revisado y transaccional: modo `check` de solo lectura (ya ejecutado: `to_insert=1`), luego `apply` con el id del comentario de autorización. 1 INSERT bajo bloqueo de fila; rechaza `*_role`, el rol privilegiado y permisos inexistentes (no crea); comprueba el total (765 → 766) y 0 permisos de roles fuera de `super_admin`; vacía la caché | ~25–30 min con respaldo y registros | Bajo | `pg_dump` de `roles`, `permissions` y `role_has_permissions` con sha256 + línea base; rollback: modo `rollback` que borra exactamente el par insertado, o restaurar el volcado |
| (ii) Manual (propietario) | Staging → Roles → R1 → Editar → sección de usuarios → marcar `view_any_user` → Guardar. El editor vivo ya conserva los ocultos. No tocar la sección «Role» | ~2 min | Bajo | Desmarcar y guardar; verificación opcional de solo lectura |

Los 66 activos de R2 no tienen casilla en el editor actual, así que solo se podrían reponer por script. Hoy no se recomienda.

**Observación (solo lectura).** El 2026-11-12, entre las 16:10:32 y las 16:10:40Z, se crearon 25 filas de permisos:
- los 6 `*_shield::role`;
- 12 `*_taxonomy::category::request`;
- 5 páginas y 2 widgets.

Ningún rol ni cuenta las tiene, y los 5 roles reales no cambiaron. El patrón coincide con guardar un rol de prueba con todas
las casillas y borrarlo después, a la hora de la UAT del propietario; no se afirma como causa. TASK-0021 había medido 0
`*_shield::role` a las ~12:45Z. Tras desplegar esta corrección, esos 6 quedan inertes. Borrarlos (6 filas sin vínculos) sería
una escritura aparte, que requiere autorización.

## 4. Matriz

| Criterio | Resultado |
|---|---|
| Seis casillas correctas (`*_role`, usados por `RolePolicy`) | PASS (aislado); en vivo, pendiente del gate de despliegue |
| Sin regresión de permisos: ocultos, visibles y `super_admin` (R4/R4-01/TASK-0021) | PASS (enfocadas 28/28) |
| Gestión de roles exclusiva de `super_admin`; sin concesión por payload forjado | PASS |
| SEC-03 y resto de la app | PASS (suite completa 798/798) |
| Evaluación cuantificada y lista exacta segura/manual | Entregada (§3 y notas restringidas) |
| Escrituras en roles, permisos, membresías o datos reales | **0** (solo lecturas `READ ONLY`; el script de restauración solo en modo `check`) |
| Push a la rama runtime / deploy | **0** (staging sigue en `71e8a6d`) |

## 5. Pendiente (decisiones del propietario)

1. Revisar el candidato y, si procede, autorizar su despliegue a staging con gate propio: respaldo, push *fast-forward* de
   `270e07d` sobre `71e8a6d` y validación en vivo con roles sintéticos.
2. Recuperación: confirmar o descartar el vínculo de R1 y elegir entre (i), con un comentario de autorización con esa lista
   exacta, o (ii). R2 sigue en 0.
3. Opcional: autorizar el borrado de las 6 filas `*_shield::role` sin vínculos, después del despliegue.
