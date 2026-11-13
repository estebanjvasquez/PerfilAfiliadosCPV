# TASK-0026 — SEC-01: desplegar en staging la corrección de F-SEC01-T24-02 (TASK-0025), con la migración en la BD compartida

| Campo | Valor |
|---|---|
| Fuente | Issue #2, comentario [`6851565505`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6851565505) (2026-11-13 11:30Z), con la autorización expresa del propietario citada en él. Veredicto previo [`6851472706`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6851472706) (TASK-0025 APPROVED como candidato aislado) |
| ID | TASK-0026: siguiente libre verificado (`tasks/`, `current_task.md`, ramas remotas, `git log --all`, handoff). No reabre TASK-0025 |
| Estado | ~~IN_PROGRESS~~ (2026-11-13 ~11:40Z) → **DEPLOYED_AWAITING_24H** (~11:55Z). Código desplegado y migración ejecutada en staging; la monitorización de 24 h termina el **2026-11-14 11:43:38Z** |
| BASE | `270e07dcda8c61840c569b5c85cbad471ce9bf82` (remoto y servidor, verificado justo antes del push) |
| **TESTED = REVIEW = DEPLOYED** | `6e7f52546a29f223fa924c0c897592223c55360c` (*fast-forward* `270e07d..6e7f525` a las 11:43:38Z, sin *force*) |
| Workflow | `43419358951` «Deploy a Contabo» **success** (11:43:43–11:44:15Z) |
| Migración ejecutada | `2026_11_13_000001_harden_public_report_views`, lote **63**, sola (id 149); `migrations` 133 → 134 |
| Autoriza | *Fast-forward* normal de `feature/upgrade-filament-v3` a `6e7f52546a29f223fa924c0c897592223c55360c`, sin *force*; el workflow normal ejecuta `php artisan migrate --force`. Cubre de forma explícita el DDL de `2026_11_13_000001_harden_public_report_views` en la BD Supabase compartida con desarrollo |
| Condiciones | Preflight PASS: alcance FF limpio; topología de las 13 vistas sin cambios; respaldo verificado; restauración y rollback ensayados en un clon; pruebas del candidato y suite completa. El lote de `migrate` debe contener **solo** esa migración |
| No autoriza | Producción, `main`, *force-push*, otras migraciones o cambios, Data API, esquemas expuestos, tablas base, RLS, políticas, `service_role`, credenciales, n8n, taxonomía, roles reales, FK y huérfanas, HSTS (`max-age=86400`), Cloudflare, nginx, túnel; REST con claves reales o datos personales; restaurar la BD compartida completa sin autorización adicional |
| Monitorización | 24 h obligatorias. Sin mecanismo persistente autorizado, la entrega es **DEPLOYED_AWAITING_24H** con procedimiento manual; no se declara cierre antes |

## 1. Preflight: **PASS** en los 5 gates

| Gate | Resultado |
|---|---|
| 1. Alcance | Remoto y servidor en `270e07d`, árbol limpio; último workflow `43274064073` success; imagen `a36b4647435b`; PostgreSQL 17.6 (≥ 15). `migrate:status` sin pendientes, último lote 62. Las 15 migraciones que marca `db:skip-mysql-only-view-migrations` ya constaban, así que ese paso no hace nada. Candidato *fast-forward* limpio: 2 commits y 3 archivos (migración, prueba y fixture); una sola migración |
| 2. Topología | Inventario de TASK-0025 repetido: **0 líneas distintas** (13 vistas, propietarios, `reloptions`, md5 de definiciones, ACL, columnas y tablas base, dependencias, RLS 21/21 sin políticas, extensiones, esquemas). Conteos de filas idénticos. Roles de la API: `anon`/`authenticated` sin BYPASSRLS, `service_role` y `postgres` con BYPASSRLS y sin superusuario |
| 3. Respaldo | `/root/backups/sec01-t26-20261113T113455Z` (700/600, fuera de git): `pg_dump -Fc -n public`, sha256 `9608dcda…` verificado, 1109 entradas; 88 tablas, 41 524 filas; `migrations` (133); línea base de roles y totales 508/0/330/368; catálogo, DDL y huellas md5 de las 13 vistas; `.env` (sha `d5b5b7b9…`); imagen `perfilafiliados-app:pre-t26-270e07d`; HEAD de código |
| 4. Restauración y rollback (clon aislado) | Base con ICU `en-US`, como en vivo; privilegios restaurados (roles de la ACL creados). 0 diferencias de conteo; roles idénticos; 133 migraciones; 101 FK; 0 índices inválidos; RLS 88. FK `contact_empresa_contact_id_foreign` recreada `NOT VALID`, con las 2 huérfanas intactas, y rechaza filas nuevas. Catálogo de vistas y huellas **idénticos al vivo**. `UP` ×2: 13/13 con `security_invoker`, `anon`/`authenticated` sin acceso, `service_role` y huellas sin cambios. `DOWN`: catálogo y huellas idénticos al vivo, `anon` vuelve a leer |
| 5. Pruebas | Run `20261113T113423Z-6e7f52546a29`: **OK (5 tests, 48 assertions)**. Run `20261113T113533Z-6e7f52546a29`: suite completa **OK (803 tests, 8562 assertions)**. Staging 200 antes y después; 0 contenedores remanentes |

## 2. Verificación posterior: **PASS**

| Control | Antes (`270e07d`) | Después (`6e7f525`) |
|---|---|---|
| Servidor / árbol / imagen / `.env` | `270e07d`, limpio, `a36b4647435b` | `6e7f525`, limpio, `d13539844c83`; `.env` sin cambios |
| Migraciones | 133, último lote 62 | 134; el lote 63 contiene **solo** la migración autorizada |
| Otras mutaciones | — | Conteos de las 88 tablas idénticos salvo `migrations` +1; roles idénticos; totales 508/0/330/368 |
| 13 vistas: `security_invoker` / `anon` / `authenticated` / `service_role` | 0 / ALL / ALL / ALL | **13 / ninguno / ninguno / ALL** |
| Propietario y md5 de definiciones | `postgres` | idénticos |
| Huellas md5 de la salida (como propietario) | línea base | **idénticas** |
| Sesión SQL por rol (conteo, transacción revertida) en `catalogoView`, `FinanceView`, `capacityView` | `anon` 406, `authenticated` 406 | **`anon` y `authenticated`: permission denied**; `service_role` 406 = propietario |
| RLS de tablas base | 88/88, 0 políticas | sin cambios |
| Consumidores Laravel (humo de solo lectura: 13 modelos, 7 exportaciones, HTML del PDF de perfil completo, 11 páginas de informes) | todo OK; `join-views` ya daba 500 | **idéntico a la línea base** (mismos conteos y estados) |
| Logs Laravel / `42501` / 5xx nginx | 83 / 0 / 0 | 84 / **0** / **0**. El error +1 es la petición de humo a `join-views`, defecto conocido (`Undefined array key 0`) |
| Rutas, HSTS, http → https, Host ajeno, debug y mantenimiento | — | 200 (y `/admin/shield/roles` 302); `max-age=86400`; 301; 400; apagados |
| n8n (solo `GET`) | — | 9 flujos activos; 0 ejecuciones y 0 errores desde el despliegue |

## 3. Monitorización de 24 h: **en curso** (DEPLOYED_AWAITING_24H)

- **Inicio:** 2026-11-13 11:43:38Z. **Fin:** no antes del 2026-11-14 11:43:38Z.
- **Lectura t0** (11:45Z): PASS.
- **Procedimiento manual,** de solo lectura y sin vigilancia automática ni bucles:
  1. Como `root` en el servidor de staging, ejecutar `bash /root/backups/sec01-t26-20261113T113455Z/monitor_check.sh`. Muestra:
     - HEAD;
     - errores de Laravel desde el inicio, con cuántos son `42501` o *permission denied*;
     - peticiones, 5xx y 403 de nginx;
     - rutas e informes;
     - HSTS;
     - estado de las 13 vistas y la sesión `anon`;
     - filas por vista leídas como propietario;
     - lotes de migración posteriores al 63.
  2. Revisar en n8n (`GET /api/v1/executions`) las ejecuciones con error de los flujos activos desde el inicio.
  3. Hacerlo al menos hacia T+4 h, T+12 h y T+24 h, y anotar cada lectura (hora, contadores e incidencias).
- **PASS a las 24 h:** 0 errores `42501` o *permission denied*; 0 respuestas 5xx atribuibles; las 13 vistas siguen con `security_invoker` y sin acceso de `anon`; las rutas e informes responden; ningún lote nuevo sin autorización; ningún flujo activo con errores atribuibles.
- **Disparadores de rollback:** errores sostenidos de permisos en informes, exportaciones o PDF; un consumidor legítimo afectado; o salud degradada.

**Rollback** (ensayado en el clon):
- **A.** Si el último lote sigue siendo el 63, que solo contiene esta migración: ejecutar
  `docker compose exec -T app php artisan migrate:rollback --step=1 --force`.
- **B.** Si hay lotes posteriores: no usar `--step` a ciegas. Ejecutar el `DOWN_SQL` de la migración y borrar su fila de
  `migrations` (id 149).
- **Después de A o B:** comprobar el catálogo y las huellas contra `views_catalog.txt` y `views_fp.txt` del respaldo, y la
  salud. Revertir el código solo si hace falta, después de la BD, con `git revert` y sin *force*.
- **Límite:** es una reversión del DDL de las 13 vistas, no una recuperación integral de la BD compartida. Restaurar el
  `pg_dump` completo requeriría otra autorización y, además, la FK `NOT VALID` por las 2 huérfanas.

## 4. Incidencias de ejecución (sin efecto en staging)

1. El primer ensayo de restauración creó la base del clon con intercalación libc. Las huellas no coincidían con las vivas
   porque los `ORDER BY` dentro de las vistas usan la intercalación de la base. En vivo es ICU `en-US`: se repitió con ICU y
   todo coincidió.
2. Mis propias lecturas dejaron entradas en el log de Laravel: la consulta ambigua de TASK-0024 (10:10Z) y las peticiones de
   humo a `join-views` (11:40Z y 11:45Z), por el defecto conocido de esa página. No fueron escrituras ni las provocó el cambio.

**Límites:** sin cierre de F-SEC01-T24-02 hasta completar 24 h con PASS. No se probó REST con la clave real (requiere gate
aparte). Producción, `main`, Data API, RLS, `service_role`, n8n, HSTS, Cloudflare y nginx sin cambios. R-06 en espera;
F-SEC01-T24-01 abierta y contenida; R-04 con su propio gate.

## Anexo: texto íntegro de la fuente

<details><summary><code>6851565505</code></summary>

# NUEVA ASIGNACIÓN AUTORIZADA — despliegue exclusivo en staging de TASK-0025 / F-SEC01-T24-02

**Autorización expresa del propietario (2026-11-13):** «Autorizo una nueva tarea independiente para desplegar TASK-0025 exclusivamente en staging, incluyendo la migración de las 13 vistas en la base de datos Supabase compartida con desarrollo. Exijo respaldo verificado, rollback probado, pruebas posteriores y monitorización de 24 horas. No autorizo producción ni main».

**Fuente técnica:** TASK-0025 APPROVED como candidato aislado en [6851472706](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6851472706); entrega [6851263825](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6851263825). Candidato `6e7f52546a29f223fa924c0c897592223c55360c` en `fix/sec-01-t25-public-views-hardening`, BASE de staging reportada `270e07dcda8c61840c569b5c85cbad471ce9bf82`; HEAD documental `02a86518975c4c0c0d165ed2dcbb580fbd50ced2` **no** es código de despliegue. Procedimiento detallado: `docs/security/SEC01_TASK0025_VISTAS_PUBLIC.md` §7.

## 1. Materializar una tarea nueva, no reabrir TASK-0025
Leer `AGENTS.md`, `docs/orquestador/PROTOCOL.md`, `SESSION_RESUME.md`, `current_task.md`, TASK-0025 y su informe como referencias directas. Antes de editar, `git status -sb`, rama, HEAD, worktrees, remotos, divergencias y cambios preexistentes; no descartarlos. Verificar siguiente identificador consecutivo libre real en repositorio y materializar **una nueva TASK** con fuente este comentario, alcance, gates, evidencia y STOP. No reutilizar TASK-0025 ni inventar el ID. Registrar aprobación de TASK-0025 y esta asignación preservando historia.

## 2. Preflight de seguridad: STOP si falla
1. Verificar HEAD real de `feature/upgrade-filament-v3` en remoto y servidor, estado limpio, workflow, imagen desplegada, configuración de migraciones, compatibilidad PostgreSQL 15+ y dependencia de la **BD Supabase compartida entre staging y desarrollo local**. No confiar en el SHA histórico si ha cambiado. Confirmar que el candidato `6e7f525` es *fast-forward* limpio desde runtime; inspeccionar diff exacto (migración `database/migrations/2026_11_13_000001_harden_public_report_views.php`, prueba y fixture). No integrar otros cambios ni migraciones; si hay divergencia, migraciones pendientes ajenas, cambios concurrentes o conflicto, **BLOCKED/STOP**, no improvisar rebase/force.
2. Verificar las 13 vistas por nombre y esquema contra matriz de TASK-0025; ACL, `reloptions`, owners, dependencias, roles y permisos, RLS y políticas. Confirmar que no hubo alteraciones desde el análisis; si cambió la topología o hay consumidores inesperados, STOP.
3. Preparar respaldo consistente **previo a cualquier DDL**: `pg_dump` de la BD compartida con checksum SHA-256 comprobado, catálogo de 13 vistas (DDL/ACL/`reloptions`), huellas de resultados leídos como propietario, tabla `migrations`, baseline de roles/permisos, imagen/configuración actual y punto de recuperación de código. Guardar en almacenamiento restringido fuera de Git, sin secretos ni datos personales en Issue.
4. **Ensayar restauración y rollback en clon aislado** del backup nuevo: restauración verificable, integridad/consistencia y comportamiento exacto de las 13 vistas y consultas autorizadas; considerar explícitamente la FK `contact_empresa_contact_id_foreign` y las dos huérfanas preexistentes, cuya restauración puede requerir `NOT VALID` en el clon. No tocar ni borrar esas filas en vivo; documentar límites del rollback (DDL reversible vs recuperación integral de BD). Si respaldo o ensayo no es fiable: **STOP**, no desplegar.
5. Ejecutar pruebas del candidato (5/5, 48 assertions como referencia) y suite completa (referencia 803/803, 8562 assertions) sobre estado integrado aislado. Si falla, distinguir defecto real de test intermitente `CompanyDossierPdfTest`, aportar evidencia; no aprobar fallos críticos.

## 3. Despliegue autorizado, condicionado a gates PASS
6. Integrar **solo** el candidato de TASK-0025 mediante *fast-forward* normal a `feature/upgrade-filament-v3`, **sin force-push**; usar workflow normal de staging `.github/workflows/deploy-contabo.yml`, que ejecuta `php artisan migrate --force`. Esta autorización **sí cubre explícitamente el DDL de la migración nombrada en la BD Supabase compartida con desarrollo**. Verificar que el lote ejecutado contiene **únicamente** `2026_11_13_000001_harden_public_report_views`; si el pipeline ejecutaría otra migración o un cambio fuera de alcance, STOP antes del push/despliegue.
7. No cambiar Data API, esquemas expuestos, tablas base, RLS/políticas, `service_role`, credenciales, n8n, taxonomía, roles reales, FK/huérfanas, HSTS (`max-age=86400`), Cloudflare/nginx/túnel, producción o `main`. No publicar secretos ni PII.

## 4. Verificación posterior y recuperación
8. Verificar en BD real: las 13 vistas `security_invoker=true`, `anon`/`authenticated` sin SELECT, `service_role` con SELECT, owner y salidas como propietario **idénticos** al baseline; exactamente la migración autorizada aplicada, ninguna otra mutación. Validar accesos por roles mediante sesión SQL controlada sin extraer PII, y páginas Filament de vistas, informes, PDF y exportaciones **solo lectura**. Logs sin nuevos `42501`, 5xx, fallos de jobs o regresiones; runtime y árbol limpios; HSTS y SEC-03 intactos. No probar REST con claves reales ni datos personales sin gate adicional.
9. Si una verificación crítica falla, **detener promoción y activar rollback según plan ensayado**: revertir la migración concreta, comprobar ACL/reloptions/huellas y salud, revertir código sin force-push si procede. No ejecutar `migrate:rollback --step=1` a ciegas si el último lote contiene más de la migración autorizada. Si rollback pudiera afectar otras operaciones o datos concurrentes, STOP y escalar con evidencia; no restaurar toda la BD compartida sin autorización adicional.
10. **Monitorización obligatoria de 24 horas** desde el despliegue: errores 42501/403/5xx, salud de rutas e informes, consultas y exportaciones, flujos activos dependientes, anomalías de permisos y estabilidad de staging; registrar hora inicial/final, muestras, contadores, incidencias y mitigaciones. No declarar **cierre definitivo** antes de completar 24 h y verificar resultados. Si el ejecutor no dispone de mecanismo de seguimiento persistente autorizado, entregar **DEPLOYED_AWAITING_24H** con procedimiento de revisión manual y STOP; no prometer vigilancia automática inexistente ni lanzar loops sin autorización.

## 5. Entrega y STOP
11. Actualizar archivo de nueva tarea, `docs/security/SECURITY_STATUS.md`, `docs/orquestador/current_task.md`, `SESSION_RESUME.md`, `audit/orchestrator_handoff.json` con BASE/TESTED/REVIEW/DEPLOY/HANDOFF SHAs diferenciados, backups y checksums saneados, workflow/run, migración aplicada, conteos y evidencia de pruebas, restauración, rollback y monitorización. Preservar antecedentes. Distinguir **código desplegado**, **migración ejecutada** y **monitorización completada**. No declarar cerrado F-SEC01-T24-02 hasta PASS de verificación y observación de 24 h.
12. Publicar en Issue #2 `READY_FOR_REVIEW` cuando el alcance y 24 h estén completos, o `DEPLOYED_AWAITING_24H` / `BLOCKED` cuando corresponda, indicando SHA y evidencia. **STOP**: no iniciar R-06, Turnstile ni otra tarea por iniciativa propia.

**Gate de producción:** no autorizado. Esta asignación no implica merge a `main`, ni despliegue de producción, ni cambios ajenos a las 13 vistas.

</details>
