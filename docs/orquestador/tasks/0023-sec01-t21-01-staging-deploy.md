# TASK-0023 — SEC-01: desplegar en staging la corrección de F-SEC01-T21-01 (TASK-0022)

| Campo | Valor |
|---|---|
| Fuente | Issue #2, comentario [`6831152066`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6831152066) (2026-11-12 17:36Z), asignación del propietario; veredicto [`6831144754`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6831144754) (TASK-0022 APPROVED con alcance limitado) |
| ID | TASK-0023: siguiente libre verificado (`tasks/`, ramas remotas, `git log --all`) |
| Estado | ~~IN_PROGRESS~~ (2026-11-12 ~17:45Z) → **READY_FOR_REVIEW** (~18:00Z). Desplegado **solo en staging** |
| **TESTED_HEAD = REVIEW_HEAD = DEPLOYED_HEAD** | `270e07dcda8c61840c569b5c85cbad471ce9bf82` |
| Workflow | `43274064073` «Deploy a Contabo» **success** (17:52:59–17:53:32Z); push *fast-forward* `71e8a6d..270e07d` a las 17:52:58Z, sin *force* |
| No reabre | TASK-0022 (APPROVED; su texto no cambia) |
| Alcance | Integrar y desplegar **solo en staging** (`feature/upgrade-filament-v3`, mecanismo existente, sin *force-push*) el candidato `270e07dcda8c61840c569b5c85cbad471ce9bf82`, con respaldo, ensayo de restauración, regresión completa, validación sintética en vivo y rollback |
| Prohibido | recuperar `view_any_user` u otro permiso de R1/R2; escrituras en roles, permisos o membresías reales; borrar las 6 filas `*_shield::role` u otras; producción, `main`, HSTS, Cloudflare, nginx, túnel, n8n, taxonomía, secretos, datos masivos |
| BASE (runtime) | `71e8a6d2f4922253174c4ac4bd6fd39074c00555` (servidor y remoto, verificado ~17:42Z) |
| Candidato | TESTED `4bdec36`, REVIEW `270e07d` (`fix/sec-01-t22-role-permission-key`): *fast-forward* limpio de `71e8a6d` (4 commits, 6 archivos: corrección, prueba y ayuda) |

## Criterios (del comentario fuente)

1. Diff y ancestros: solo la corrección de las seis casillas, su prueba y su ayuda; `RolePolicy`, R4/R4-01 y SEC-03 intactos; `*_role` en solo lectura. Si no es FF limpio, STOP.
2. Respaldo consistente (BD, imagen, configuración, checksum, línea base de roles y permisos), restauración ensayada en aislamiento con la FK `contact_empresa_contact_id_foreign` (`NOT VALID`) y rollback practicable. Si no, STOP.
3. Regresión enfocada, suite completa y SEC-03 sobre el candidato. Si falla, STOP.
4. Despliegue solo en staging; registrar SHA, workflow, salud, 5xx, rutas, logs, imagen y configuración.
5. Validación en vivo con roles y usuarios sintéticos (transacción revertida): 6 `*_role` visibles en solo lectura, ningún `*_shield::role`, ocultos conservados, sin concesión a no `super_admin`, acceso de `super_admin`; SEC-03 y HSTS `max-age=86400`. Rollback ante pérdida, elevación, regresión o errores sostenidos.
6. Registro y READY_FOR_REVIEW o BLOCKED; STOP.

## 1. Alcance y ancestros: **PASS**

- `git merge-base --is-ancestor 71e8a6d 270e07d` → sí; 4 commits (`0bc7fbc`, `6fc3907`, `4bdec36`, `270e07d`).
- `git diff --stat 71e8a6d 270e07d`: 6 archivos, 277 inserciones y 2 borrados. Son la corrección (`RoleResource`,
  `WritesRenderedPermissionsOnly`, `AppServiceProvider`, `RolePermissionIdentifier`), la prueba
  `Sec01Task0022RoleSectionTest` y la ayuda de administración.
- Sin cambios en `RolePolicy`, SEC-03, configuración, rutas, migraciones ni dependencias. Sin conflicto semántico: es el mismo
  candidato aprobado en `6831144754`.

## 2. Respaldo, restauración y rollback: **PASS**

| Control | Resultado |
|---|---|
| Respaldo | `/root/backups/sec01-t23-20261112T174040Z` (700/600, fuera de git y del webroot): `.env` (sha256 `d5b5b7b9…`), imagen `perfilafiliados-app:pre-t23-71e8a6d` (`270bb64ece9a`), `pg_dump -Fc -n public` (exit 0, `pg_dump.err` vacío), sha256 `c93851da…` verificado, 1109 entradas de TOC; 88 tablas, 41 524 filas; línea base por rol (permisos, miembros, md5 de nombres con `COLLATE "C"`); totales 508 permisos, 0 directos, 330 membresías, 368 usuarios |
| Ensayo de restauración (aislado) | PostgreSQL 17 + pgvector desechable, red `--internal`, sin puertos. **88 tablas, 0 diferencias**; línea base de roles **idéntica**; 133 migraciones, 101 FK, 0 índices inválidos. Errores esperados de `pg_restore`: `schema public` ya existente y la FK de las 2 huérfanas. Contenedores eliminados |
| FK `contact_empresa_contact_id_foreign` | 2 filas huérfanas preexistentes, **sin modificar**. Recreada `NOT VALID` desde el volcado: `validated=false`, rechaza filas nuevas inválidas. Restauración viable; **no** íntegra en sentido estricto mientras existan las 2 filas |
| Rollback | **R1 (inmediato):** en el servidor, `git reset --hard 71e8a6d`, retag `pre-t23-71e8a6d` → `latest`, `up -d`, `config:cache` y `view:cache`, reload de nginx; `.env` sin cambios. **R2 (persistente):** `git revert` de `71e8a6d..270e07d` empujado a `feature/upgrade-filament-v3`, sin *force-push*. Sin migraciones ni datos que revertir. Umbrales: pérdida de acceso o permisos, elevación, regresión de SEC-03, 5xx sostenidos. **No hizo falta** |

**Incidencias de ejecución (scripts del ejecutor, sin efecto en staging):**
1. El primer respaldo abortó tras el volcado, porque el `COLLATE "C"` perdió las comillas dentro de la cadena de bash.
   Se borró el directorio parcial que creó ese intento (`sec01-t23-20261112T173946Z`) y se repitió con el escape corregido.
2. El primer ensayo de restauración consultó Postgres durante su reinicio de inicialización y abortó. Se eliminaron el
   contenedor y la red desechables que dejó, y se repitió esperando la segunda señal de «ready» y una consulta real.
3. La primera validación «antes» mezcló `*_role` (sin casilla en `71e8a6d`) en la muestra de ocultos, así que el control
   «sin concesión de `*_role`» no era concluyente. Se excluyeron de la muestra y se repitió. Todo en transacción revertida.
4. Una comprobación de Host ajeno contra el puerto 80 del host devolvió `000`: nginx publica en `127.0.0.1:8080`. Se repitió
   dentro del contenedor de nginx, que es el camino real del túnel.

## 3. Regresión sobre el candidato: **PASS**

| Run | Alcance | Resultado |
|---|---|---|
| `20261112T174149Z-270e07dcda8c` | enfocada: seis casillas, R4, R4-01, TASK-0021/0022, matriz de acceso, suplantación, usuarios, SEC-03 (recuperación, Turnstile, HSTS, Host/proxies) | **OK 93 pruebas, 674 aserciones** |
| `20261112T174424Z-270e07dcda8c` | **suite completa** | **OK 798 pruebas, 8514 aserciones** (igual que TASK-0022) |

Staging respondió 200 antes y después de cada run; 0 contenedores remanentes.

## 4. Despliegue y verificación en staging: **PASS**

| Control | Antes (`71e8a6d`) | Después (`270e07d`) |
|---|---|---|
| `git rev-parse HEAD` en el servidor / árbol | `71e8a6d`, limpio | **`270e07d`**, limpio |
| Imagen de la app | `270bb64ece9a` | `a36b4647435b` |
| `.env` (sha256) | `d5b5b7b9…` | `d5b5b7b9…` (sin cambios) |
| Errores de Laravel en log | 81 (previos) | 81 (0 nuevos) |
| Respuestas 5xx de nginx (30 min) | 0 | 0 |
| `/`, `/admin/login`, `/admin/password-reset/request`, `/admin/recuperar-acceso` | 200 | 200 |
| `/admin/shield/roles` sin sesión | 302 | 302 |
| HSTS | `max-age=86400` | `max-age=86400` (sin `includeSubDomains` ni `preload`) |
| http → https | — | 301 |
| Host ajeno (nginx) / canónico | — | 400 / 200 |
| Debug / mantenimiento | — | apagados |

**Validación del editor en vivo** (script en el contenedor por el camino de `/livewire/update`, `HandleComponents::mount` +
`update`, con usuarios y rol **sintéticos**). Todo corre en **una transacción que siempre se revierte**, con la caché en
memoria. La página de `super_admin` solo se monta, nunca se guarda.

| Comprobación | Antes (`71e8a6d`) | Después (`270e07d`) |
|---|---|---|
| Clave de entidad del recurso de roles | `shield::role` | **`role`** |
| Casillas renderizadas / con `shield::` | 386 / **6** | 386 / **0** |
| Sección «Role» = los 6 `*_role` reales | no | **sí** |
| Sección «Role» deshabilitada; demás casillas habilitadas | no / sí | **sí / sí** |
| `super_admin` ve sus 6 `*_role` marcados | no | **sí** |
| Rol sintético (40 ocultos reales + 4 visibles), guardado sin cambios | conserva todo | conserva todo |
| Desmarcar 1 visible | exacto | exacto |
| Enviar los 6 `*_role`, `update_shield::role` y nombres forjados (por «Role» y por otro campo) | nada concedido ni creado | nada concedido ni creado |
| `super_admin` administra; no `super_admin` con todos los `*_role` directos | sí; política no, página rechazada | sí; política no, página rechazada |
| Transacción revertida: totales y roles reales idénticos; restos sintéticos | sí; 0 | sí; 0 |

**Integridad de roles reales** (solo lectura, contra la línea base del respaldo): 5 roles **idénticos** (permisos, miembros,
md5). Totales 508 / 0 / 330 / 368 **sin cambios**. Las 6 filas `*_shield::role` siguen presentes, sin vínculos, y no se
borraron.

## 5. Matriz

| Criterio | Resultado |
|---|---|
| Alcance FF limpio, sin cambios ajenos | PASS |
| Respaldo / restauración aislada (FK `NOT VALID`) / rollback | PASS / PASS / preparado, no usado |
| Regresión enfocada y suite completa | PASS (93/93; 798/798) |
| Seis casillas `*_role` en solo lectura en staging; ningún `*_shield::role` | PASS |
| Ocultos conservados, sin concesión a no `super_admin`, sin forjados, acceso de `super_admin` | PASS |
| SEC-03 (HSTS 86400, 301, Host ajeno 400) | PASS |
| Escrituras en roles, permisos, membresías o datos reales | **0** (R1/R2 sin tocar; `view_any_user` no restaurado; filas inertes no borradas) |
| Producción, `main`, HSTS, Cloudflare, nginx, túnel | sin cambios |

## 6. Riesgos residuales

- Las 6 filas `*_shield::role` (0 vínculos) quedan inertes. Borrarlas requiere autorización aparte.
- Recuperación de octubre: sin cambios. R1 `view_any_user` queda a decisión manual del propietario. R2, intacto.
- La guarda del rol privilegiado no cubre SQL directo, actualizaciones masivas, `saveQuietly` ni `withoutEvents` (límite de R4).
- 2 filas huérfanas en `contact_empresa` (sin modificar).
- Sin UAT de navegador de esta versión: el propietario puede abrir Roles → editar y comprobar la sección «Role», sin guardar
  roles reales.
- SEC-01 sigue abierto (R3-02, R3-03, relation managers y búsquedas restantes, F-03/F-06). Producción y `main`, sin autorización.
