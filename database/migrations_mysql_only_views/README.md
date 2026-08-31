# Vistas SQL nativas de MySQL — excluidas temporalmente de `database/migrations`

Estos 15 archivos crean las 13 vistas de reporting del sistema (`catalogoView`, `capacityView`,
`ManagementView`, `SustainabilityView`, `FinanceView`, `ClientsView`, `ExperienceView`,
`ResourceView`, `MachineryView`, `FacilityView`, `InventoryView`, `ManagementDetView`,
`PresenceView`) usando SQL específico de MySQL (`JSON_TABLE`, `GROUP_CONCAT`, `IF()`, etc.).

Se movieron fuera de `database/migrations` (Fase 1 del plan, rama
`feature/supplhi-postgres-buscador`) para poder correr `php artisan migrate --database=pgsql`
limpio contra Postgres/Supabase sin que Laravel intente crear estas vistas ahí con sintaxis
incompatible.

**No afecta a producción**: estas migraciones ya corrieron hace tiempo contra la base de datos
`mysql` real (están registradas en su tabla `migrations`); moverlas de carpeta en esta rama no las
"deshace" ni las vuelve a ejecutar ahí.

**Plan de reincorporación (Fase 2)**: cada vista se reescribe en un archivo de migración nuevo
dentro de `database/migrations/`, con `protected $connection = 'pgsql';` explícito (para que corra
siempre contra Postgres sin importar el flag `--database` usado), traduciendo `JSON_TABLE`→
`jsonb_array_elements`/`LATERAL`, `GROUP_CONCAT`→`string_agg`, `IF()`→`CASE WHEN`. Ver
`docs/task.md` sección 3 y el plan de fases para el detalle vista por vista y el orden recomendado
(de menor a mayor riesgo).

Los archivos originales de esta carpeta se conservan tal cual (no se editan) como referencia de la
definición MySQL original mientras dure la Fase 2.
