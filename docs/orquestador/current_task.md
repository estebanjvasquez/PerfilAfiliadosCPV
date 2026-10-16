# Tarea actual (checkpoint 2026-10-16 ~08:40Z)

El estado general y las reglas de arranque están en [`SESSION_RESUME.md`](SESSION_RESUME.md). La historia
completa de este archivo hasta hoy está en
[`history/current_task_hasta_2026-10-16.md`](history/current_task_hasta_2026-10-16.md).

## Producto: CPV-HOME-BRANDING-AND-LAYOUT

| Campo | Valor |
|---|---|
| Autorización | Issue #2 `6210321522` (propietario, 2026-10-16 07:23Z): implementación; despliegue no incluido |
| Rama | `feature/home-branding-layout` |
| BASE_SHA | `6e263021bdeb7af9d93ab8292a1a176127dc05bd` (= `c02d4ec` desplegado más audit/docs) |
| TESTED_HEAD | `17e2b076f221f36037a193b8fcab58a11d243009` |
| REVIEW_HEAD | `0f8bdad97590676c966048e18afa35f76110ff1f` |
| Entrega | `6210758420` (READY_FOR_REVIEW — MANUAL) |
| Evidencia | `audit/home_branding_layout_2026-10-16.md`, `audit/runs/home_branding/` |
| Despliegue | aprobado por el propietario en el chat el 2026-10-16. **No ejecutado:** el clasificador de permisos rechazó el push a la rama de despliegue. Precondiciones en `audit/runs/home_branding_deploy_2026-10-16/` |

**Siguiente acción exacta:**
1. El propietario hace el fast-forward con
   `git push origin 0f8bdad97590676c966048e18afa35f76110ff1f:refs/heads/feature/upgrade-filament-v3`.
2. Con una instrucción, un agente captura el estado posterior y corre los smokes de solo lectura
   `docs/deploy/smoke_home_branding_readonly.php` y `docs/deploy/smoke_task_0010c_readonly.php`.

## Continuidad: CPV-SESSION-CONTINUITY

| Campo | Valor |
|---|---|
| Autorización | Issue #2 `6210552318` (propietario, 2026-10-16 07:38Z): solo documentación y arranque |
| Rama | `docs/session-continuity` (sale de `0f8bdad`); no se despliega |
| Informe | `audit/session_continuity_2026-10-16.md` |
| Siguiente acción | revisión del orquestador o del propietario |

## Fuera de alcance hasta una instrucción nueva

- Activar el correo.
- Reintentar la solicitud #1.
- Reactivar el ejecutor autónomo.
- Hacer merge a `main`.
- Cualquier mutación de la taxonomía.
