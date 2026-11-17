# TASK-0033 — Preparación pre-UAT institucional de CPV (READY_FOR_REVIEW — SIN DEPLOY)

| Campo | Valor |
|---|---|
| Fuente | Issue #2 `6945631895` (asignación del propietario, 2026-11-17T10:37:07Z); referencia de UAT `6945403230` |
| Rama | `fix/sec-01-r4-01-role-editor-preserve` |
| BASE | `2cdb53dd199b0c2e3a783754b46a54da4f56668b` |
| Materialización | `539cdf0` |
| Alcance | Análisis, lecturas de solo lectura, pruebas aisladas inocuas y documentación. **Sin despliegues ni cambios en runtime, BD, n8n, Worker, modelo, taxonomía, roles, empresas, credenciales ni caché** |
| Entregables | [`docs/preuat/00_INDICE.md`](../docs/preuat/00_INDICE.md) → E1–E6 |

## Resultado

| Entregable | Conclusión principal |
|---|---|
| E1, inventario | Matriz por área, contrastada con el corte del 11/11. Hallazgos de hoy: el código vivo del Worker (`4599217`) no está en `master` del Worker (`29de993`, nunca desplegado); `main` sigue en `73853cd` (2026-09-09); faltan las lecturas de 24 h de TASK-0026/0027 |
| E2, seguridad | Sin bloqueos técnicos para la UAT institucional, con 5 condiciones (C1–C5). Para producción: orden de cierre P1–P6. Verificado hoy: 13/13 vistas con `security_invoker` y `anon` denegado; RLS en 92/92 tablas con 0 políticas; `delete_user` solo en `super_admin`; `*_shield::role` sin vínculos; 2 huérfanas de `contact_empresa`; `/pdf` y `/xls` sin sesión con `cf-cache-status: DYNAMIC`; HSTS de 1 día; sin `X-Frame-Options`/CSP/`nosniff` (OBS-T33-01) |
| E3, UAT | Paquete para el personal (A-01..A-34) y para el propietario (B-01..B-11); severidad S1–S4; criterios de aceptación y suspensión; acta |
| E4, latencia | Navegador = n8n + 0,5–0,85 s. UAT del 17/11: total n8n p50 10,0 s; modelo 4,2 s; Worker 3,8 s (consultas a la BD sin caché; la caché de Hyperdrive, ~60 s, acelera solo las repeticiones y sesga los benchmarks). Prompt estable (~4.950 tokens). Opciones L0–L8 sin implementar |
| E5, decisiones de la Cámara | 191 empresas sin sector principal (antes 194), 103 solo con «X»; «X»: 249 vínculos / 235 empresas (antes 250 / 236), prioridad alta 119 / 109; I.O.E.D./Geohidra explicado (ejecución 23163); la Cámara figura como empresa 451; 3 solicitudes pendientes (aviso de la n.º 1 fallido); alias de balancín ausentes; formatos de respuesta |
| E6, ejecutivo | **NO-GO hoy para iniciar la UAT institucional**, por G4–G9: preparativos y decisiones del propietario, sin trabajo técnico. Estimación: 1–2 días hábiles. Clasificación A/B/C/D, secuencia y riesgos |

## Lecturas realizadas (todas de solo lectura)

- **SQL:** sondas en transacción `READ ONLY` con rollback ([`docs/cira/task0033/bd/`](../docs/cira/task0033/bd/)).
- **Servidor:** `monitor_check.sh` una vez y `git rev-parse` en el servidor.
- **n8n:** `GET` del flujo y de 22 ejecuciones (más tokens y grupos).
- **Cloudflare:** `wrangler deployments status` y `hyperdrive get`.
- **Worker:** 51 llamadas de solo lectura.
- **HTTP:** 7 `GET` sin sesión.

**Antes y después** (11:01Z frente a 11:20Z): `cira_search_log` 142, votos 13, evaluaciones 0, empresas 406,
`chamber_empresa` 173, migraciones 135, última ejecución n8n 23163, flujo `2d0a1ee8`. Sin cambios.

## Incidencias

- **Consulta fallida propia:** una consulta pedía una columna inexistente. No hubo escrituras (`READ ONLY`), pero dejó
  **1 línea de error en el log de Laravel de staging** (2026-11-17 11:12:05Z), como en TASK-0032.
- **Consulta de términos:** la primera falló por el nombre de la columna (`term`) y se repitió.

## Bloqueos y límites

- **BLOQ:**
  - rotación de credenciales (no autorizada);
  - prueba de descarga **con sesión** (requiere una cuenta de prueba y una asignación);
  - ampliar la muestra de latencia con tráfico real (escribiría `cira_search_log`) o con una copia nueva en n8n (edición
    de n8n no autorizada).
- **PEND:**
  - inventario de la clave anónima de la Data API;
  - confirmación directa del acierto de caché de Hyperdrive;
  - si los dos catálogos de F-03 son `area` y `country`.

## Datos privados

Los listados con nombres de empresas y el texto de las solicitudes están solo en `docs/uat/privado/2026-11-17_preuat/`
(excluida por `.gitignore`), para que el propietario los envíe a la Cámara.
