# Estado del despliegue de staging — `pruebas.camarapetrolera.app`

**Fecha de verificación:** 2026-09-22, vía acceso SSH directo al servidor (`66.94.121.98`,
Contabo) y ejecución de PHP dentro del contenedor `perfilafiliados-app-1`. No es una inferencia
de docs — son valores leídos en vivo del servidor real.

**Veredicto: el despliegue documentado en `docs/migracion.md` (2026-09-02) SÍ se completó.**
Ese documento dejaba la pregunta abierta explícitamente ("no se pudo confirmar si el despliegue de
prueba llegó a completarse"); queda resuelta acá.

---

## Verificado en vivo

| Ítem | Esperado (`docs/migracion.md`) | Real (verificado hoy) | Coincide |
|---|---|---|---|
| Rama desplegada | `feature/upgrade-filament-v3` | `feature/upgrade-filament-v3`, commit `46fb0a5` | ✅ |
| Laravel | 12.x | `12.69.0` | ✅ |
| Filament | v3.x | `3.3.55.0` | ✅ |
| Livewire | v3.x | `3.8.7.0` | ✅ |
| Conexión de BD activa | `pgsql` (Supabase) | `pgsql` (confirmado via `config('database.default')`) | ✅ |
| Migraciones pendientes | — | `php artisan migrate:status --pending` → **"No pending migrations"** | ✅ |
| Total migraciones aplicadas | — | 120 | — |
| PHP + extensiones | 8.2, `intl` habilitado | Contenedor corre en PHP 8.2 (mismo Dockerfile que producción) | ✅ |

## Estado del stack de infraestructura (Docker, mismo servidor)

Verificado como parte del incidente de infraestructura de hoy (ver `docs/task.md`, sección de
incidente 2026-09-22): los 3 stacks Docker del servidor (`perfilafiliados`, `camarapetrolera-wp`,
`barriles-wp`) están arriba y respondiendo 200 desde afuera del servidor (no solo localhost).

## Lo que NO se verificó en esta pasada

- **No se corrió el checklist funcional completo de `docs/migracion.md`** (login/Turnstile, alta de
  empresa, exports xlsx/csv/pdf, perfil de Breezy) — solo se confirmaron versiones, conexión de BD
  y estado de migraciones. Si se quiere dar por cerrado el QA funcional de ese despliegue, falta
  correr esa batería.
- **`shield:generate` contra pgsql**: `docs/migracion.md` documentaba una brecha de permisos de
  Filament Shield (163 permisos en pgsql vs 285 esperados). No se volvió a contar permisos hoy —
  pendiente de verificar si se corrigió.
- **Regresión del buscador CIRA** contra el Worker desplegado — ver `audit/regression_2026-09-22.md`
  (o la nota de bloqueo correspondiente) para el estado de eso.

## Qué falta antes de producción (sigue vigente de `docs/migracion.md`)

La lista de "Qué falta antes de llevar esto a producción" de `docs/migracion.md` (QA del cliente,
decisión de mysql vs pgsql en producción, fusión con `feature/supplhi-postgres-buscador`, ajuste de
`.cpanel.yml`, confirmación de PHP 8.2+/`intl` en producción, `shield:generate` en producción,
backup) **sigue sin marcarse como resuelta** — nada en el historial de commits ni en esta
verificación indica que se haya avanzado en esos puntos desde el 2026-09-02. Se mantiene como
pendiente real.
