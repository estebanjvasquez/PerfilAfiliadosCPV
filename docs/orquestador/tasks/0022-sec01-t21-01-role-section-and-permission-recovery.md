# TASK-0022 — SEC-01: corregir F-SEC01-T21-01 (sección «Role» del editor) y evaluar la recuperación de permisos de octubre

| Campo | Valor |
|---|---|
| Fuente | Issue #2, comentario [`6829151868`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6829151868) (2026-11-12 16:17Z), asignación del propietario; decisiones en el veredicto [`6829143372`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6829143372) (TASK-0021 APPROVED con reservas) |
| ID | TASK-0022: siguiente libre verificado (archivos de `tasks/`, `current_task.md`, ramas locales y remotas, `git log --all`) |
| Estado | **IN_PROGRESS** (2026-11-12 ~16:30Z) |
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
