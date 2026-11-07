# TASK-0013 — Calibración del buscador CIRA: lista de referencia, medición y registro de búsquedas y votos

| Campo | Valor |
|---|---|
| Identificador | **TASK-0013** (verificado libre el 2026-11-06: no aparece en los 210 comentarios de Issue #2, ni en `docs/orquestador/tasks/`, ni en `current_task.md` / `SESSION_RESUME.md`) |
| Fuente | Issue #2, comentario [`6684941317`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6684941317): registro publicado por el agente a pedido explícito del propietario en el chat del 2026-11-06 («registralo y crea la tabla requerida»). Texto íntegro al final |
| Rama | `fix/sec-01-r4-01-role-editor-preserve` (rama canónica del estado vigente) |
| BASE | `8e22d0c81c1d63da4467310ae210a28d6ea76a9f` |
| Estado | **READY_FOR_REVIEW** (2026-11-06). Código y pruebas en `bc8212b` |
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

**Commit de entrega:** `bc8212b42e084d170c4b20b73984f6381d0fd1f6`.

**Runner aislado** (Contabo, run `20261106T123818Z-bc8212b42e08`, `--suite Feature --filter CiraSearchLogMigrationTest`):
- `OK (7 tests, 77 assertions)`, 0 omitidas: la base era `cpv_test`, así que la guarda de base desechable no saltó ninguna;
- `migrate` aplicó `2026_11_06_120000_create_cira_search_log_and_feedback_tables` en 10,72 ms;
- `staging_before=200` y `staging_after=200`, sin contenedores `cpvtest-*` restantes;
- el único «finding» del runner es informativo: «0 migration ordering violation(s) at this SHA».

**La migración no se aplicó en la base compartida.**

**Medición base del buscador** (2026-11-06, `evaluate_search.mjs`, modo `mcp`): ruido conocido 48 de 66 en el top 5 y 56 de 66 en el top 10; MRR 0,742; relevantes conocidas en el top 10: 36 de 49; empresas devueltas por consulta: 17,7 de media. Con lo que el cliente vio el 3 de noviembre, sacado de 60 ejecuciones guardadas en n8n, el ruido conocido da 50 de 57 en el top 5.

**`bootstrap_access.ps1`:** lo verifiqué con un almacén falso (`CPV_CREDENTIAL_STORE`) bajo `-WhatIf`, y el valor de prueba ya no aparece en la salida.

**Estado:** READY_FOR_REVIEW para el alcance de esta tarea. Siguiente paso propuesto: punto 1 del plan del comentario fuente, estabilizar la consulta del agente de n8n. Requiere autorización para editar n8n.

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

---

## Paso 1 — Consulta completa en el agente de n8n (asignación `6686193637`)

| Campo | Valor |
|---|---|
| Fuente | Issue #2 [`6686193637`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6686193637) (orquestador, a pedido expreso del propietario). Texto íntegro abajo. No aprueba la entrega anterior de TASK-0013 |
| Estado | **READY_FOR_REVIEW** (2026-11-06). v2 probada en una copia aislada y **promovida a staging** con la aprobación del propietario en el chat («It is approved»). La aplicó el propietario: `zbVLoCdR09IA9yQK` pasó de `e22c8dd5…` a `279690f8…`. Verificación y reversión: informe §6 |
| Informe | [`docs/uat/n8n/INFORME_TASK0013_PASO1.md`](../../uat/n8n/INFORME_TASK0013_PASO1.md): flujo y alcance, prompt exacto y diferencias, antes/después, casos que empeoran, límites y reversión |
| Flujo de la UAT | «Chat CIRA V5 - MCP» `zbVLoCdR09IA9yQK`, solo tráfico de staging (87/87 ejecuciones); sin modificar (`versionId` `e22c8dd5…`) |
| Copia de prueba | `3PtTduMeifEe2fg9` (`versionId` `171a9f6d…`), **desactivada** al terminar |
| Respaldos | fuera del repo, `%USERPROFILE%\.cpv\n8n_backups\` |
| Gates | sin producción; sin migración, registro ni votos; sin cambios de ranking ni del Worker; sin merge |
| Regresión congelada | el propietario rotó `DEBUG_TOKEN` el 2026-11-06. Hay línea base nueva de 32 consultas, y una segunda corrida contra ella no dio cambios (informe §4) |

**Resultado (3 repeticiones, 48 casos):**
- casos con variantes entre repeticiones: de 15 a 3;
- consultas recortadas: de 9 a 3 (las 3 son el filtro «lago» de un aprendizaje);
- búsquedas del agente por su cuenta: de 4 a 0;
- MRR: de 0,78-0,82 a 0,817 en las 3 repeticiones;
- relevantes conocidas en el top 10: 37 de 49 sin cambio;
- ruido conocido en el top 5: de 52-53 a 53-55, sobre todo porque «mecha» se busca siempre, una variabilidad de `resolve_search_intent`.

### Comentario fuente `6686193637` (íntegro)

[ORQUESTADOR — AUTORIZACIÓN Y ASIGNACIÓN] Continuación de TASK-0013: estabilizar la consulta de CIRA en n8n

El propietario autoriza expresamente al desarrollador a trabajar en el paso 1 descrito en 6685054227, incluida la edición necesaria de n8n para esta finalidad. Esta instrucción no constituye aprobación de la entrega TASK-0013, que continúa pendiente de revisión.

1. Verifica en solo lectura qué workflow y webhook atendieron el UAT, su estado y si comparten tráfico de producción. Documenta el identificador y el alcance del cambio. Conserva una copia recuperable del flujo fuera del repositorio y sin exponer credenciales.
2. Prepara un cambio acotado del prompt y, si hace falta, de la construcción de parámetros: preservar la consulta completa del usuario en la búsqueda de capacidad, sin reducirla a una palabra genérica; permitir filtros y frases adicionales cuando estén sustentados. Distingue búsquedas de empresa por evidencia de nombre o RIF, sin tratar una palabra en mayúsculas como nombre por defecto. Mantén la atención de consultas generales y fuera de alcance.
3. Prueba primero en un flujo aislado o de pruebas. Si el único flujo disponible atiende producción, detente antes de modificarlo y reporta el alcance; la autorización actual no incluye producción. No actives ni conectes componentes de registro o votos.
4. Mide la ruta completa de chat/n8n/MCP, capturando consulta original, parámetros enviados, resultados ordenados y versión; usa conversaciones nuevas y varias repeticiones para «transporte de gandolas», «mantenimiento de levantamiento artificial», «inspección y mantenimiento de guaya eléctrica», «GUAYAS» y el lote de referencia aplicable. Incluye búsquedas por nombre/RIF, filtros y consultas amplias o fuera de alcance. Compara con observaciones anteriores y documenta cambios, variabilidad y regresiones, sin tratar resultados no juzgados como negativos.
5. Ejecuta también `evaluate_search.mjs --compare` contra la base registrada y la regresión congelada del Worker cuando sus accesos lo permitan. Su guía indica que este script llama al MCP directamente y no reproduce la reescritura del agente: sus resultados complementan, pero no sustituyen, la medición de n8n. Si no puedes ejecutar una verificación, informa el bloqueo sin alterar credenciales.
6. Entrega el texto exacto del prompt propuesto, diferencia de configuración, evidencia antes/después, casos que empeoran, límites y reversión. Registra el trabajo conforme al protocolo, preservando TASK-0013 y los cambios locales existentes. No declares aceptación automática con una mejora aislada.

Fuera de alcance: aplicar la migración en la base compartida, conectar logs o votos, modificar ranking o pesos del Worker, publicar taxonomía, fusionar ramas o desplegar a producción. Los pendientes de rotación de tres credenciales y de los dos hallazgos de seguridad continúan vigentes para producción; no publiques secretos ni detalles sensibles en este Issue público.

Autoría: instrucción publicada por el orquestador a petición expresa del propietario en este chat.


---

## Migración aplicada en la base compartida (2026-11-06)

- **Autorización:** la dio el propietario en el chat de desarrollo («the migration to shared database is authorized»).
- **Qué se aplicó:** `php artisan migrate --database=pgsql --path=…/2026_11_06_120000_create_cira_search_log_and_feedback_tables.php --force`. Era la única migración pendiente y quedó como lote 61.
- **Verificación:**
  - `cira_search_log`: 4 CHECK;
  - `cira_search_feedback`: 7 CHECK y 3 claves foráneas;
  - 6 índices, incluido `cira_search_feedback_one_vote_idx`;
  - las dos tablas, vacías;
  - **RLS activado** sin políticas.
- **Permisos de `anon` y `authenticated`:** los roles de Supabase tienen los permisos por defecto sobre las tablas nuevas, igual que en las demás. RLS sin políticas les bloquea el acceso por la API REST.
- **Staging:** sitio y `/cira-test/` responden 200 después de aplicar.
- **Reversión:** `down()` elimina las dos tablas; está probado en el runner aislado.
- **Sin conectar todavía:** ni el registro de búsquedas en n8n ni los votos. Son pasos aparte.

## Explicación por resultado (v3), promovida a staging (2026-11-06)

- **Aprobación:** del propietario en el chat.
- **Worker:** commit `836a00b` desplegado como versión `9c99cec7`. La regresión congelada no dio cambios.
- **n8n:** el flujo de staging pasó a `0f55cd97…`.
- **Detalle, verificación y reversión:** `docs/uat/n8n/INFORME_TASK0013_PASO1.md` §7.
- **Decisión sobre los votos:** todos los usuarios de staging son personal de la Cámara, así que los votos se registran como `voter_kind = 'staff'`.

## Votos 👍/👎 en staging (2026-11-06)

- **Aprobación:** del propietario en el chat, para los tres pasos (página, Worker y n8n).
- **Página:** `feature/upgrade-filament-v3` en `60c43cf`; despliegue `42259603797` con `success`.
- **Worker:** versión `e7ad2079`. La regresión congelada no dio cambios.
- **n8n:**
  - flujo nuevo «CIRA votos (staging)», `iVDEEo0oaEHDOvlp`;
  - el flujo principal quedó en `0d0b2ac1…`.
- **Prueba real:** la búsqueda y los 4 tipos de voto quedaron registrados como `staff`, y los datos de prueba se borraron.
- **Detalle y reversión:** `docs/uat/n8n/INFORME_TASK0013_PASO1.md` §9.

## Paso 2: ranking del Worker (2026-11-07, en curso)

- **Origen:** el propietario buscó «operadores» en `cira-test`. Las empresas dedicadas al sector (Repsol, Chevron…) quedaban por debajo de empresas generalistas que solo tienen ese sector entre muchos otros. Desde el puesto 11 aparecían empresas sin sector, servicio ni categoría de operador.
- **Diagnóstico (solo lectura):**
  - El full-text en español reduce «operadores», «operaciones», «operativo» y «operado» a la misma raíz `oper`.
  - Los puestos 14 a 30 venían solo de esa coincidencia, sobre todo en experiencias y en algunos nombres de servicio.
  - Dentro del sector, el orden era arbitrario y premiaba a las empresas con más servicios.
  - `sector_principal_id` existe pero no se usaba.
- **Decisión del propietario (2026-11-07):** los sectores y servicios legados se conservan y no se reemplazan por la taxonomía nueva. La taxonomía CPV es información adicional.
- **Autorización:** del propietario en el chat, para los cambios 1 a 4 contemplando sectores y servicios legados. La promoción a staging requiere una aprobación aparte, con el antes y después.
- **Cambios en el Worker:** rama `feat/ranking-specialization`, commit `4c8f3fd`, sin desplegar.
  1. El texto libre (full-text, experiencias) ya no agrega empresas nuevas cuando la frase tiene al menos 3 empresas con coincidencia de catálogo (legado o taxonomía CPV); sigue sumando puntaje a las que ya tienen otro respaldo.
  2. Lista nueva `sector_focus`, con peso 2.0: sector principal o secundario declarado, o al menos la mitad de los servicios de la empresa en lo buscado. La lista `structured` se ordena por la misma especialización.
  3. El sector principal o secundario cuenta como coincidencia aunque la empresa no tenga servicios cargados.
  4. La explicación muestra la palabra que coincidió de verdad («operaciones» por «operadores») y el sector principal o secundario.
- **Ajuste tras la regresión:** commit `7a351f0`. La compuerta ya no se activa por `canonical_cpv` (capacidad CPV inferida). Con ella activa, «gandola», «gandolas» y «mantenimiento de gandolas» perdían a la empresa 433, la única que menciona gandolas literalmente en su experiencia.
- **Preview `fb72aa80` (sin desplegar):**
  - **«operadores»:** pasa de 30 a 13 resultados. Son las 13 empresas del sector, con las dedicadas primero (Repsol, DBS, Restoven, Shell, Chevron, Indioil, A&B, Inepetrol) y las generalistas después. Salen las 17 que solo coincidían por texto.
  - **Regresión congelada:** cambian 3 de 32 consultas (`docs/uat/runs/2026-11-07_worker_regression_paso2.txt`):
    - «reciclaje»: sale una empresa en el puesto 36, solo por una experiencia similar;
    - «construccion»: salen 6 empresas de la cola, de los puestos 66 a 90, solo por texto o experiencia similar, y entra PETROCOR por su sector declarado;
    - «guaya fina»: se intercambian los puestos 1 y 2.
  - **Golden set:** `docs/uat/runs/2026-11-07_paso2_preview.json`.
    - El MRR pasa de 0.742 a 0.775.
    - «operadores» mejora en todas las métricas.
    - «refinery» empeora frente a la línea base del 06-11, pero hoy da el mismo resultado en el Worker vivo, así que no viene de este cambio.
    - El ruido total en el top 5 pasa de 48 a 49, por esa misma consulta.
- **Promoción (aprobada por el propietario en el chat, 2026-11-07):** `fb72aa80` (`7a351f0`) al 100 % en el Worker vivo.
  - Regresión en vivo: los mismos 3 cambios esperados («reciclaje», «construccion», «guaya fina»).
  - En una de tres corridas aparecieron «caballito» o «cabria». Desaparecen al repetir: es la variabilidad conocida del intérprete de consultas del Worker.
  - `/search-log` sin token responde 401.

### Sectores y servicios editables (2026-11-07)

- **Instrucción del propietario en el chat:**
  - quitar la restricción de solo lectura de sectores y servicios;
  - quitar las notas de «legacy» y de «histórico»;
  - permitir corregir las empresas que tienen «X»;
  - después, retirar «X» del catálogo.
- **App:** rama `feat/sectores-servicios-editables` (desde staging `60c43cf`), commits `40cd5ca` y `90044f3`, sin desplegar.
  - `SectorPolicy` y `ServicePolicy` vuelven a depender de los permisos del rol. Hoy los tienen `super_admin` y `filament_user`; los afiliados no editan el catálogo.
  - La ficha de la empresa recupera «Vincular servicio» con la regla de 2 sectores de `cbc1e37` y sin duplicados, revisada en el servidor. «X» ya no se puede vincular.
  - Se quitaron «(histórico)», «clasificación anterior» y «legacy» de la ficha, de los avisos, de las pantallas de taxonomía que muestran servicios y del manual.
- **Pruebas:** suite completa en el runner aislado, 656 pruebas en verde. Staging respondió 200 antes y después.
- **Despliegue a staging (aprobado por el propietario en el chat, 2026-11-07):** `feature/upgrade-filament-v3` avanzó de `60c43cf` a `90044f3` (fast-forward).
  - **Falla:** el run `42364833420` falló en `migrate`, porque el pooler de sesión de la base compartida estaba lleno (15 de 15).
  - **Causa:** las 15 conexiones eran del Worker por Hyperdrive, inactivas desde las pruebas de la mañana. Hyperdrive tiene `origin_connection_limit` 60 contra un pool de 15. Staging quedó en mantenimiento (503).
  - **Reparación:**
    - Se terminaron con `pg_terminate_backend` las 15 conexiones inactivas `postgres.js`, por el puerto 6543. Contó con la autorización del propietario y no tocó datos.
    - Se completaron en el servidor los mismos pasos del workflow; `migrate` respondió «Nothing to migrate».
  - **Verificación:**
    - `/`, `/admin/login` y `/cira-test/` responden 200;
    - la app lee la base (406 empresas activas);
    - el código nuevo está en el contenedor.
  - **Corrección de fondo (aprobada por el propietario en el chat, 2026-11-07):** se bajó `origin_connection_limit` de Hyperdrive de 60 a 8 (`wrangler hyperdrive update … --origin-connection-limit 8`).
    - **Bajo carga:** durante la regresión congelada completa, el Worker usó como máximo 5 conexiones (muestreo cada 3 s).
    - **Regresión:** los mismos 3 cambios esperados.
    - **Latencia:** mediana de 2.6 s, igual que antes del cambio.
    - **Staging:** sigue leyendo la base.
    - **Reversión:** el mismo comando con 60.
- **Lista de «X» para revisión manual:** se generó fuera del repo, porque contiene nombres de empresas. Tiene 250 filas en 236 empresas:
  - 119 de prioridad alta, que perderían el sector;
  - 125 de prioridad media, cubiertas por el sector declarado;
  - 6 de prioridad baja, con otro servicio en el sector.
- **Al integrar con la rama SEC-01:** `tests/Feature/Security/CompanyRelationManagersTest.php` afirma que «attach» no existe y hay que ajustarlo.
- **«X» en datos (solo lectura, 2026-11-07):**
  - Hay 8 servicios «X», uno por sector, con 250 vínculos en 236 empresas.
  - En 244 vínculos, «X» es la única pertenencia de la empresa a ese sector.
  - De esos, 125 ya están cubiertos por el sector principal o secundario declarado.
  - Los otros 119 vínculos son de 109 empresas sin sector declarado, que perderían el sector si se borra «X» sin corregirlas antes.
- **Decisiones de datos del propietario, fuera del código:**
  - si la Cámara debe aparecer como empresa;
  - completar `sector_principal_id`.

## Paso 3: comparación con la evaluación del cliente y ajuste de «similar a» (2026-11-07)

**Pedido del propietario:** comparar los resultados actuales con la evaluación del cliente (lote 001), ver si el paso 2 degradó las consultas buenas o excelentes y si mejoró las malas. Después autorizó en el chat ajustar «similar a» (preview y pruebas; el pase a vivo espera su aprobación).

**Método:**
- Se repitieron las 27 consultas con registro de lo que vio el cliente (03-11). Se usaron la misma consulta y las mismas frases que envió CIRA, y se midió el top 15.
- Versiones comparadas: el Worker anterior a hoy (`e7ad2079`), el vivo (`fb72aa80`, paso 2) y la preview del paso 3.
- Las métricas se calcularon con `evaluate_search.mjs --from` sobre los juicios del golden set.
- Complementos: el golden set por la ruta MCP del evaluador y la regresión congelada.
- **Resultados detallados:** `docs/uat/privado/2026-11-07_comparacion_cliente/`. Es una carpeta local excluida por `.gitignore`, porque tiene nombres de empresas.

**Paso 2 (vivo) frente a lo que vio el cliente:**

| | Cliente 03-11 | Antes de hoy | Paso 2 (vivo) |
|---|---|---|---|
| MRR (relevantes conocidas) | 0.839 | 0.805 | 0.884 |
| Relevantes en top 10 | 36/44 | 35/44 | 36/44 |
| No relevantes en top 5 / top 10 | 50 / 53 | 46 / 50 | 44 / 49 |

- **Excelentes y buenas:** ninguna empeoró por el paso 2.
  - «Tratamiento de aguas de perforación»: una relevante bajó del puesto 3 al 7. Ya pasaba antes de hoy.
- **Aceptables:** mejoran «operadores» y «transporte de gandolas» (la relevante pasa al puesto 1); las demás quedan igual.
- **Deficientes e incorrectas:** sin cambios por el paso 2.
- **Causa:** en esas consultas, 22 de las 41 no relevantes del top 10 entran por «similar a» (embeddings). Casi siempre no hay coincidencias de catálogo, así que la compuerta del paso 2 no se activa.

**Diagnóstico de «similar a»:**
- Las relevantes que entran por «similar a» suelen coincidir por dos o más vías: servicio, categoría CPV y experiencia.
- El ruido suele coincidir por una sola.
- Se descartó extender la compuerta de catálogo a `service` y `taxonomy` (commit `31d81b8`, revertido en `4f4c01a`): no cambió ninguna consulta del cliente.
- Se descartó la regla «sin "similar a" si hay coincidencia literal», que quitaba 4 de 5 relevantes de una consulta excelente.

**Ajuste (Worker `feat/semantic-gate`, commit `ecb8e47`, preview `4742a36f`, NO promovido):**
- **Regla, `applySingleSimilarGate`:** si la frase tiene al menos una empresa con respaldo más fuerte (otra lista, o «similar a» por dos o más vías), se quitan las empresas que solo aparecen en una lista «similar a» (`service`, `taxonomy` o `experiencia`).
- Si no hay nada más fuerte, no se toca nada, por ejemplo «camión chupón».
- `canonical_related` (familia CPV) queda fuera de la regla.
- **Consultas del cliente, frente al vivo:**
  - relevante conocida en el top 5: 94 % → 100 % de los casos;
  - MRR: 0.884 → 0.891;
  - relevantes en el top 10: 36 → 37 de 44;
  - no relevantes en el top 5 / top 10: 44 / 49 → 31 / 34;
  - empresas devueltas, media: 11.2 → 8.0.
- **Por caso:** mejoran 8 y ninguna empeora. Son «fabricantes de cabrias», «levantamiento artificial», «guaya fina», «guaya eléctrica», «arbolitos para pozos», «mantenimiento de levantamiento artificial», «refinery» y una más. Las excelentes y buenas quedan igual.
- **Golden set, ruta MCP:**
  - mejoran «mantenimiento de cabrias», «fabricantes de cabrias», «guaya fina», «guaya eléctrica», «refinery» y «arbolitos»;
  - «…en el lago»: la relevante baja del puesto 2 al 3, pero sale el ruido conocido;
  - «balancines» varía en ambas versiones;
  - «gandola» tuvo un error puntual del servicio de embeddings y al repetir da igual que en vivo.
- **Regresión congelada:** muchas consultas devuelven menos candidatos, y los quitados son de una sola vía «similar a». Ejemplos: «camiones» 24→5, «levantamiento artificial» 17→3, «fabricantes de balancines» 9→1. Las coincidencias literales se conservan.
- **Riesgo para decidir el pase a vivo:** se pierden empresas no juzgadas que solo se parecían por una vía. Si alguna era relevante, el cliente ya no la ve.
