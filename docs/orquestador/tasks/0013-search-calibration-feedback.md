# TASK-0013 — Calibración del buscador CIRA: lista de referencia, medición y registro de búsquedas y votos

| Campo | Valor |
|---|---|
| Identificador | **TASK-0013** (verificado libre el 2026-11-06: no aparece en los 210 comentarios de Issue #2, ni en `docs/orquestador/tasks/`, ni en `current_task.md` / `SESSION_RESUME.md`) |
| Fuente | Issue #2, comentario [`6684941317`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6684941317): registro publicado por el agente a pedido explícito del propietario en el chat del 2026-11-06 («registralo y crea la tabla requerida»). Texto íntegro al final |
| Rama | `fix/sec-01-r4-01-role-editor-preserve` (rama canónica del estado vigente) |
| BASE | `8e22d0c81c1d63da4467310ae210a28d6ea76a9f` |
| Estado | **IN_PROGRESS** |
| Gates | migración escrita y probada, **no aplicada** en la base compartida; sin editar n8n; sin cambios de ranking; sin merge a `main`; sin producción |

Cambios locales del propietario en `AGENTS.md`, `CLAUDE.md`, `docs/orquestador/PROTOCOL.md` y
`docs/orquestador/SESSION_RESUME.md`: se conservan sin commit y no se restauran ni se descartan.

## Decisiones del propietario (chat, 2026-11-06)

- **Tablas:** «Búsquedas + votos» → `cira_search_log` y `cira_search_feedback` en la base `pgsql` del Worker.
- **Alcance:** «Migración + tests, sin aplicar». Aplicarla y conectar n8n son pasos siguientes con autorización propia.
- **Rotación de credenciales:** se deja para la planificación del paso a producción (ver «Pendientes antes de producción» en el comentario fuente).

## Entregables

| Pieza | Ruta |
|---|---|
| Lista de referencia y su nota | `docs/uat/golden_set_lote001.json`, `docs/uat/GOLDEN_SET_LOTE001.md` |
| Generador de la lista | `docs/uat/tools/build_golden_set_lote001.js` |
| Script de medición y su guía | `docs/uat/tools/evaluate_search.mjs`, `docs/uat/tools/README.md` |
| Medición base | `docs/uat/runs/2026-11-06_base.json` |
| Migración | `database/migrations/2026_11_06_120000_create_cira_search_log_and_feedback_tables.php` |
| Pruebas de la migración | `tests/Feature/Database/CiraSearchLogMigrationTest.php` |
| Cargador de credenciales sin eco en `-WhatIf` | `docs/orquestador/bootstrap_access.ps1` |
| Mapa de accesos (solo nombres) | `docs/orquestador/ACCESS_BOOTSTRAP.md`, `docs/orquestador/access_map.json` |

## Evidencia

(Se completa al cerrar: commit, corrida del runner aislado y estado de staging antes y después.)

---

## Comentario fuente `6684941317` (íntegro)
[EXECUTOR — TASK-0013 ASSIGNMENT RECORD] Calibración del buscador CIRA: lista de referencia, medición y registro de búsquedas y votos

> **Autoría.** Este comentario lo publica Claude Code (agente ejecutor) con el token del propietario, que firma como su cuenta. **No lo escribió el propietario en GitHub.** Registra una instrucción que el propietario dio en el chat de desarrollo el 2026-11-06: «registralo y crea la tabla requerida». En ese mismo chat eligió dos opciones: «Búsquedas + votos» y «Migración + tests, sin aplicar».

## Origen

Primer lote de pruebas del cliente sobre el buscador (`docs/uat/RESULTADOS_BUSCADOR_LOTE001.xlsx`, 3 y 4 de noviembre de 2026). Sus observaciones fueron:
- con más de ~10 resultados aparece mucho ruido;
- «similar a» (semántico) rara vez acierta y «coincidió con» (literal) es lo que mejor funciona;
- no pueden indicar qué empresas faltan entre las más de 400 afiliadas.

El propietario espera que el cliente no haga más pruebas en planilla. Por eso la calibración tiene que apoyarse en señales baratas: una medición reproducible y votos de los usuarios.

## Ya hecho en la sesión (sin commit al momento de este registro)

| Pieza | Ruta |
|---|---|
| Lista de referencia: 35 consultas, 49 juicios positivos y 66 negativos, con `empresas.id` | `docs/uat/golden_set_lote001.json`, `docs/uat/GOLDEN_SET_LOTE001.md` |
| Generador de la lista | `docs/uat/tools/build_golden_set_lote001.js` |
| Script de medición (`/mcp` o `/debug-search`, `--save`/`--compare`) | `docs/uat/tools/evaluate_search.mjs`, `docs/uat/tools/README.md` |
| Medición base | `docs/uat/runs/2026-11-06_base.json` |
| Mapa de accesos con nombres nuevos (sin valores): `N8N_BASE_URL`, `N8N_API_KEY`, `CPV_MCP_TOKEN` | `docs/orquestador/ACCESS_BOOTSTRAP.md`, `docs/orquestador/access_map.json` |

**Medición base (2026-11-06, modo `mcp`):**
- ruido conocido en el top 5: 48 de 66;
- MRR: 0,742;
- relevantes conocidas en el top 10: 36 de 49;
- empresas devueltas por consulta: 17,7 de media.

Repetí la medición con lo que el cliente vio realmente el 3 de noviembre, sacado de las ejecuciones de n8n: el ruido conocido da 50 de 57 en el top 5. La medición reproduce su experiencia.

**Rotación:** el propietario rotó `MCP_TOKEN` el 2026-11-06. Lo cambió a la vez en el secreto del Worker y en la credencial de n8n, y CIRA responde. El valor no está en el repo.

## Alcance de esta tarea

1. Migración `pgsql` con dos tablas nuevas:
   - **`cira_search_log`**: una fila por búsqueda, con lo que escribió el usuario, los parámetros que mandó el agente, los ids devueltos en orden, la evidencia por posición y la versión del motor;
   - **`cira_search_feedback`**: voto 👍/👎 por empresa o por resultado completo, con motivo, empresa faltante y tipo de votante (`staff`/`affiliate`/`anonymous`/`evaluator`).

   Las dos con restricciones CHECK y claves foráneas, RLS activado y sin datos personales: no se guardan IP ni user agent.
2. Pruebas de la migración en el runner aislado (`cpv_test`): `up`/`down`/`up`, idempotencia y restricciones.
3. Registro de la tarea, puntero y handoff.

## Fuera de alcance (cada punto necesita autorización propia)

- **Aplicar la migración** en la base compartida (Supabase, la misma de staging).
- **Editar n8n.** Incluye conectar el nodo «Call 'CIRA_log'», que hoy está desconectado en «Chat CIRA V5 - MCP», escribir en las tablas nuevas y la interfaz 👍/👎.
- Cambios de ranking o pesos en el Worker. Cuando se hagan, cada uno se acepta solo con `evaluate_search.mjs --compare` contra la base y la regresión congelada.
- Merge a `main` y producción.

## Hallazgos registrados, sin actuar

- En «Chat CIRA V5 - MCP», «Build Log» no tiene salida: las búsquedas del flujo MCP nunca llegaron al log. CIRA_log escribe en otro proyecto de Supabase.
- Dos hallazgos de seguridad en el flujo de n8n se reportaron al propietario por el canal privado de la sesión. No se detallan aquí porque este hilo es público.
- **Incidente local, ya corregido.** Un ensayo con `-WhatIf` de `post_issue_comment.ps1` hizo que `bootstrap_access.ps1` imprimiera, en la salida de la sesión del agente, el valor de las credenciales del almacén local. No se publicó nada ni se escribió nada en el repo. El cargador ahora usa `[Environment]::SetEnvironmentVariable` y lo verifiqué con un almacén falso. La corrección va en el commit de esta tarea.

## Pendientes antes de pasar a producción

El propietario decidió dejarlos para la planificación del paso a producción.
- **Rotar las tres credenciales** expuestas en la sesión: `N8N_API_KEY`, `CPV_GITHUB_TOKEN` y `CPV_MCP_TOKEN` (este último en el Worker y en n8n a la vez).
- Corregir los dos hallazgos de seguridad del flujo de n8n.

## Plan propuesto (cada paso con su propia asignación o autorización)

Orden de trabajo acordado con el propietario. Ningún paso cambia el ranking sin medir: cada cambio se acepta con `evaluate_search.mjs --compare` contra la base de 2026-11-06 y la regresión congelada del Worker.

1. **Estabilizar la consulta que manda el agente de n8n.** Hoy la misma frase produce búsquedas distintas, y la «REGLA ÚNICA» del prompt pide raíces recortadas. Propuesta: que la frase del usuario llegue igual al buscador y que el agente solo agregue frases y filtros. Requiere editar n8n.
2. **Ranking en el Worker, con cambios generales y nunca para una consulta aislada:**
   - corte por relevancia en vez de 20 o 150 fijos, con los resultados directos aparte de un bloque corto de sugerencias;
   - «similar a» solo como desempate;
   - no mostrar «relacionado con – no es coincidencia directa»;
   - menos peso a los términos genéricos (mantenimiento, alquiler, transporte…).
3. **Glosario regional validado por el cliente** solo con ✓/✗ (cabria, guaya, balancín, mechurrio, chupón, arbolito, mecha, caballito…). Se carga por el flujo de gobierno de taxonomía, sin aprobaciones masivas.
4. **Juicio asistido por IA sobre los perfiles** para encontrar empresas relevantes que faltan. El cliente solo confirma una muestra.
5. **Votos 👍/👎 en CIRA.** Primero aplicar la migración de esta tarea (autorización aparte). Después conectar en n8n el registro de búsquedas y la interfaz de votos, con mayor peso para los votos del personal de la Cámara. Nada se aplica automáticamente al ranking: los votos van a una cola de revisión.
6. **Análisis de datos:** empresas que aparecen como ruido en muchas consultas (GEOHIDRA, LUKIVEN, TUBOACERO, SERVI COMPRESORES) y experiencias donde el cliente del proyecto se confunde con lo que la empresa sabe hacer («refinería»).
- El agente de intención no es determinista: la misma frase produjo búsquedas distintas, por ejemplo «transporte de gandolas» → `transporte`, que devolvió 32 resultados.
