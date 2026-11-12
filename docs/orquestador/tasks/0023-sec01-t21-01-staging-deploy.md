# TASK-0023 — SEC-01: desplegar en staging la corrección de F-SEC01-T21-01 (TASK-0022)

| Campo | Valor |
|---|---|
| Fuente | Issue #2, comentario [`6831152066`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6831152066) (2026-11-12 17:36Z), asignación del propietario; veredicto [`6831144754`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6831144754) (TASK-0022 APPROVED con alcance limitado) |
| ID | TASK-0023: siguiente libre verificado (`tasks/`, ramas remotas, `git log --all`) |
| Estado | **IN_PROGRESS** (2026-11-12 ~17:45Z) |
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
