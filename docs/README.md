# Documentación del proyecto

Toda la documentación de PerfilAfiliadosCPV vive en esta carpeta (salvo `README.md`, que se deja
en la raíz por convención de GitHub). Los comandos `/cerrar-proyecto` y `/retomar-proyecto` solo
consultan esta carpeta.

## Vigentes (consultar primero)

- **[task.md](task.md)** — fuente de verdad de en qué quedó el trabajo, tarea por tarea, QA
  pendiente y notas técnicas. No se sube al repo (`.gitignore`), vive solo local. La actualiza
  `/cerrar-proyecto` al cierre de cada jornada.
- **[PLAN_DESPLIEGUE_PRODUCCION.md](PLAN_DESPLIEGUE_PRODUCCION.md)** — guía operativa para el
  despliegue `staging` → `main`: tabla de fase-por-fase de QA, migraciones/SQL manuales, pasos de
  despliegue y rollback. Sí se sube al repo.
- **[implementation_plan.md](implementation_plan.md)** — plan de la fase en curso más reciente.
  No se sube al repo. Se reescribe entre fases (ver notas de reciclado en `task.md`).
- **[informe_migracion_mysql_supabase_camara_petrolera_v2.md](informe_migracion_mysql_supabase_camara_petrolera_v2.md)**
  — informe vigente sobre el módulo de categorización (SupplHi) y el buscador de afiliados,
  evaluado a partir del estado real del repo. Propone una Fase A sin migrar motor (MySQL
  `FULLTEXT`) y deja la migración a Supabase/PostgreSQL + `pgvector` (v1) como Fase B opcional,
  condicionada a que la búsqueda léxica no alcance. No en curso todavía.

## Históricos / de referencia

Superados por `PLAN_DESPLIEGUE_PRODUCCION.md` y `task.md`, se conservan por si hace falta el
historial:

- `LECTURA_PRIMERO.md`, `ESTADO_ANALISIS_JUNIO_2026.md`, `PROXIMO_PASOS.md`,
  `SNAPSHOT_SISTEMA_JUNIO_2026.md` — estado del proyecto de junio-julio 2026.
- `RESUMEN_DESARROLLOS.md`, `ESTIMADO_IMPLEMENTACION.md` — resumen y estimación de una fase previa.
- `ANÁLISIS_MERCADO_VENEZUELA.md` — análisis de mercado, sin relación directa con el desarrollo.
- `CONTEXTO-logincaptcha.md`, `logincaptcha.md` — contexto de la implementación de login/CAPTCHA
  (ya mergeada a `main`).
- `informe_migracion_mysql_supabase_camara_petrolera.md` — versión v1 del informe de migración,
  escrita solo a partir del volcado SQL antes de revisar el repo. Superada por la v2 de arriba,
  se conserva por el detalle del análisis del volcado (inventario de vistas, funciones JSON, etc.).
