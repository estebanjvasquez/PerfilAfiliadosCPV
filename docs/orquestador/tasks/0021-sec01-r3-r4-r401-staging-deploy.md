# TASK-0021 — SEC-01: revisión final de R4-01 e integración/despliegue de R3+R4+R4-01 solo en staging

| Campo | Valor |
|---|---|
| Fuente | Issue #2, comentario [`6823247114`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6823247114) (2026-11-12 12:11Z), asignación explícita del propietario |
| Estado | IN_PROGRESS |
| Alcance | Revisar F-SEC01-R4-01; integrar **solo** R3+R4+R4-01 sobre lo que corre en staging; preflight (respaldo, ensayo de restauración, rollback, regresión); desplegar **solo** en staging si todo pasa |
| Fuera de alcance | producción, `main`, rollback o retiro de SEC-03, Cloudflare/HSTS, n8n, taxonomía, secretos, datos masivos, restaurar permisos perdidos, cambiar roles reales |
| Rama de trabajo | `integrate/sec-01-r3-r4-r401-staging` (worktree aparte), desde `9d0c24d` |
| Rama documental | `fix/sec-01-r4-01-role-editor-preserve` (no es lo desplegado) |
| BASE (staging vivo) | `9d0c24d613fb32d9a0ff91c5cc9d0e4b7d036823` (verificado en el servidor, 2026-11-12 ~12:15Z; HSTS `max-age=86400`) |

## Aceptación (del comentario fuente)

1. Revisión de R4-01 con pruebas aisladas: ocultos conservados, solo cambian los visibles modificados, `super_admin` protegido, gestión de roles exclusiva de `super_admin`, fallos y transacciones. Si no pasa, STOP.
2. Candidato con solo R3+R4+R4-01, comparado contra `9d0c24d`: no revierte SEC-03 ni otras funciones.
3. Preflight: respaldo verificado, restauración ensayada en aislamiento (con la FK preexistente), plan de rollback, suite completa sobre el candidato integrado. Si algo falla, STOP sin desplegar.
4. Despliegue solo en staging por el mecanismo existente; validación con cuentas sintéticas; SEC-03 sigue efectiva.
5. Matriz PASS/FAIL/BLOCKED, SHAs, respaldo, restauración, pruebas, rollback; entrega READY_FOR_REVIEW o BLOCKED; STOP.

## Fuentes identificadas en el repositorio

`cf01e9c` (lo desplegado en octubre) es el ancestro común de staging y de la rama documental. La rama documental trae además
TASK-0011/0012/0013 y documentos, así que **no** se fusiona entera. Se toman solo estos 12 commits de código y pruebas
(`git cherry-pick -x`):

| Ronda | Commits | Aceptación previa |
|---|---|---|
| R3 | `3a3ab56`, `b45fd83`, `7cd796e`, `c6e99ae`, `e95169c` (REVIEW_HEAD `66f5ce8`, solo documentos encima) | `6318588005` |
| R4 | `1b47f1a`, `4953be6`, `7896bbf`, `b51fa3e` (REVIEW_HEAD `97737ba`, solo documentos encima) | `6319407886` |
| R4-01 | `2ec1c6c`, `44da9a3`, `24413d5` (TESTED) | lista para revisión (`6320241495`); se revisa aquí |

**Hallazgo de integración:** staging ya tenía **parte** de R3 desde `7831e01` («apply TASK-0011 UX fixes to staging
baseline», 2026-10-27, sin registro como despliegue de R3). Tenía el trait `ReauthorizesEmpresaAccess` y `EmpresaAccess`,
pero solo `ServicesRelationManager` lo usaba: `EditEmpresa` y los otros 9 gestores de relación **no** se
re-autorizaban. R4 y R4-01 no estaban en staging.

## Estado

En curso. Ver las secciones siguientes, que se completan durante la tarea.
