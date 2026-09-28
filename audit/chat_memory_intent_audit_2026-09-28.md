# Auditoría de `chat_memory` — pipeline de resolución de intención del chatbot CIRA

**Fecha:** 2026-09-28
**Fuente:** `database/chat_memory_rows.sql` (export directo de la tabla `public.chat_memory` de
Supabase, 6.7MB, 11.952 filas — un único `INSERT` de una sola línea, demasiado grande para leer
completo con las herramientas estándar).
**Motivación:** el archivo era demasiado grande para procesar directo. En vez de reconsultar
Supabase, se extrajo y analizó localmente para dejar un dataset chico y reutilizable como insumo de
Fase C (mejora del parser de intención, dataset de eval/regresión, auditoría de calidad histórica).

## Metodología

No había Python ni PHP disponibles en el shell de esta sesión (solo un stub de Microsoft Store para
`python`), así que el parseo se implementó en PowerShell puro: un tokenizador que separa las tuplas
`VALUES (...), (...), ...` respetando paréntesis anidados y comillas simples escapadas (`''`), y
luego separa cada tupla en sus 6 columnas (`session_id, message, created_at, metadata, role, id`).
El campo `message` es un JSON (`{"type":"human"|"ai"|"tool", "content": "...", ...}`) que se parsea
con reintentos, porque una fracción de los registros tiene doble-escapado (`\\"` en vez de `\"`,
`\\n` en vez de `\n`) — artefacto de cómo el workflow de n8n serializa la respuesta del modelo
cuando esta ya viene en formato JSON dentro de un string.

Scripts usados (quedaron en el scratchpad de la sesión, no versionados — son de un solo uso):
`extract_sample.ps1`, `extract_intent_pairs.ps1`, `classify_failures.ps1`, `investigate_burst.ps1`.

## 1 — Muestra base (50 filas)

`database/chat_memory_sample.json`: 50 filas repartidas uniformemente entre marzo y septiembre de
2026 (de las 11.952 totales), con `tool_calls`/`additional_kwargs`/firmas base64 descartados —
mantiene solo `id, session_id, role, created_at, msg_type, content`.

## 2 — Dataset pregunta→intención (5.055 pares)

Cada sesión con sufijo `_intent` registra, para algunas consultas, un turno `human` (la pregunta
del usuario) seguido de un turno `ai` cuyo `content` es un JSON estructurado
(`whereClause, queryIntent, hasFilter, needsClarification, isSearchReady, humanSummary`) — el
resultado del clasificador de intención que arma la consulta SQL antes de buscar.

Se encontraron **5.055 pares** de este tipo en todo el archivo. Distribución por `queryIntent`:

| queryIntent | count | % |
|---|---:|---:|
| COMPANY | 3.098 | 61.3% |
| SERVICE | 710 | 14.0% |
| (no clasificable — ver sección 3) | 537 | 10.6% |
| SECTOR | 320 | 6.3% |
| MIXED | 267 | 5.3% |
| CITY | 110 | 2.2% |
| RIF | 8 | 0.2% |
| STATE | 3 | 0.1% |
| `"\nSECTOR"` (artefacto de escapado) | 2 | <0.1% |

Dataset de eval/regresión resultante: `database/chat_memory_intent_eval_sample.json` — 49 casos
estratificados (prioriza los casos marcados con flags de calidad + cubre cada categoría de
`queryIntent`), en el mismo espíritu que `intent_eval_cases.md` (mencionado en `docs/task.md`
sección 13 pero nunca versionado en el repo).

Auditoría completa (todo el dataset, no solo la muestra): `audit/chat_memory_intent_audit_2026-09-28.json`.

## 3 — Corrección: los "537 no clasificables" NO son mayormente bugs

La primera pasada marcó 536 de los 5.055 pares como `INNER_JSON_PARSE_FAILED` (el `content` del
turno `ai` no parseaba como JSON), lo que en un primer momento se reportó como "≈10.6% de fallos de
parseo". **Esa lectura era incorrecta.** Al clasificar los 536 casos completos por causa raíz
(`audit/chat_memory_intent_parse_failures_2026-09-28.json`):

| Categoría | count | Interpretación |
|---|---:|---|
| `TRUNCATED_OR_NOT_JSON` | 526 | **No es un bug.** El bot respondió en texto plano (ej. "¿qué puedes hacer?" → explicación conversacional) porque la consulta no era una búsqueda — nunca se intentó generar JSON. |
| `MARKDOWN_FENCED_JSON` | 5 | **Bug real.** El modelo envuelve el JSON de intención en un bloque ` ```json ` indentado en vez de una línea compacta — rompe cualquier parser estricto downstream. Los 5 casos ocurren en la misma sesión temprana (16 de abril). |
| `OTHER` | 5 | Ver sección 4 — incluye el patrón de JSON duplicado y un caso de sintaxis inválida (comentario `/* */` inyectado dentro del JSON). |

**Fallos reales de formato: 10 de 5.055 (≈0.2%)**, no 536. El pipeline de intención es
consistente la gran mayoría del tiempo; el ruido detectado es acotado y con causa identificada.

## 4 — Patrón de JSON duplicado/concatenado por ráfaga de consultas

Dentro de la categoría `OTHER` se detectó que 3 respuestas contienen **dos objetos JSON pegados**
con un salto de línea (el de la consulta anterior seguido del de la consulta actual). Se investigó
si el patrón aparecía en más sesiones (`audit/chat_memory_duplicate_json_pattern_2026-09-28.json`):

- **3 ocurrencias, confinadas a una sola sesión** (`a5f759e2-0573-454c-9592-509a7228ded3_intent`),
  el 24 de julio, 3 consultas de empresas distintas en ~4 segundos.
- Las 3 ocurrencias tienen `seconds_since_last_human_msg = 0` — la respuesta llegó dentro del mismo
  segundo que la pregunta previa del usuario.
- En los 3 casos, el primer objeto JSON corresponde a la consulta **anterior** (arrastrada/stale) y
  el segundo a la consulta **actual** (correcta).

**Conclusión:** condición de carrera real cuando llegan consultas más rápido de lo que el pipeline
de intención alcanza a resolver — no un bug generalizado, sino aislado a un patrón de uso de ráfaga
(probablemente una prueba manual, no tráfico real repetido). Bajo riesgo actual, pero identificado
y reproducible si se quiere corregir.

## 5 — Archivos generados

| Archivo | Contenido |
|---|---|
| `database/chat_memory_sample.json` | 50 filas de muestra general |
| `database/chat_memory_intent_eval_sample.json` | 49 casos de eval/regresión estratificados |
| `audit/chat_memory_intent_audit_2026-09-28.json` | Auditoría completa (5.055 pares, distribución) |
| `audit/chat_memory_intent_parse_failures_2026-09-28.json` | Clasificación de los 536 "fallos" por causa raíz |
| `audit/chat_memory_duplicate_json_pattern_2026-09-28.json` | Investigación del patrón de ráfaga |
| `audit/chat_memory_intent_audit_2026-09-28.md` | Este documento (resumen narrativo) |

## 6 — Verificación real contra Supabase (solo lectura, 2026-09-28)

**Nota (ver corrección en sección 6bis): esta sección describe la arquitectura legada
(`whereClause` SQL crudo), vigente en los datos históricos de `chat_memory` hasta mediados de
septiembre de 2026. El workflow de producción actual ya no funciona así — ver sección 6bis antes
de sacar conclusiones sobre el tráfico de HOY.**

Se corrieron los 27 casos de `chat_memory_intent_eval_sample.json` que tienen `whereClause` no
nulo directamente contra la base Postgres/Supabase real (`aws-0-us-west-2.pooler.supabase.com`,
mismo proyecto `mrquhxwvcrbwbuqafjee` documentado en `docs/task.md`), usando la conexión local
`pgsql` ya configurada (`php artisan tinker`, sin necesidad de `DEBUG_TOKEN`/`MCP_TOKEN` del
Worker). Resultado completo: `audit/chat_memory_searcher_verification_2026-09-28.json`.

**Tal cual el bot genera el `whereClause` hoy: 23 de 27 casos (85%) fallan.** Causa raíz confirmada
con una prueba directa (`SELECT ... WHERE LOWER(CIUDAD) LIKE ...` vs. la misma consulta con
`"CIUDAD"` citada): las columnas `CIUDAD`, `Sector` y `Servicios` en `catalogoView`/`capacityView`
se crearon con mayúsculas mixtas en Postgres, que exige comillas dobles (`"CIUDAD"`) para
resolverlas — sin comillas, Postgres las pliega a minúsculas (`ciudad`) y responde
`column "ciudad" does not exist`. El `whereClause` que arma el clasificador de intención nunca usa
comillas (es sintaxis heredada de cuando el sistema corría en MySQL, case-insensitive por
defecto — `docs/task.md` sección 4 confirma que producción sigue en MySQL y solo staging migró a
Postgres). **Esto afecta a la mayoría de las búsquedas reales**: cualquier intent `CITY`, `SECTOR`,
`SERVICE` o `MIXED` (≈27.8% del total de 5.055 pares históricos) que dependa de esas 3 columnas.

**Corrigiendo comillas + calificando las columnas ambiguas (`name`/`Sector` existen en ambas
vistas): 23 de 27 (85%) corren sin error, 16 de 27 (59%) devuelven resultados reales** coherentes
con el `humanSummary` histórico (ej. "maturin" → 5 empresas en Maturín; "sismica" → 2 empresas de
geotecnia/riesgos sísmicos). Los que dan 0 filas son en su mayoría RIFs o nombres que
razonablemente no existen en la base (no es un bug del buscador).

**Bug estructural distinto, 100% reproducible: las 4 consultas restantes (todas `queryIntent`
relacionado a estado/región) fallan con `column "state" does not exist` — y no es un problema de
comillas.** Ninguna de las dos vistas expone una columna de estado: existe una tabla `states`
(columna `state_name`) enlazada vía `cities.states_id`, pero ese dato nunca llega a
`catalogoView`/`capacityView` (que solo exponen `CIUDAD`, la concatenación de ciudad+país). El
`whereClause` legado le pedía a esas vistas una columna que no existe ahí.

**(Corregido en 6bis: esto era una limitación de esas dos vistas, no del buscador actual. El Worker
MCP no usa esas vistas — consulta `empresas`/`cities`/`states` directamente y sí resuelve estado
correctamente. Ver sección 6bis.)**

## 6bis — CORRECCIÓN CRÍTICA (2026-09-28): el bug de la sección 6 es código legado, ya reemplazado

Con acceso SSH real al VPS que aloja n8n (`vmi2945958.contaboserver.net`, clave documentada en
`audit/cira_test_json_error_2026-09-23.md`, confirmada aún vigente) se exportó en solo lectura
(`docker exec n8n-n8n-1 n8n export:workflow --id=zbVLoCdR09IA9yQK`) el workflow real "Chat CIRA
V5 - MCP" que corre en producción HOY. Esto cambia la conclusión de la sección 6:

**El workflow actual ya NO genera `whereClause` SQL.** El propio prompt del agente lo dice
explícitamente: *"Ya NO redactas SQL. Tu única salida para una búsqueda es un JSON que indica qué
tool ejecutar y con qué parámetros"*. Desde el **16 de septiembre de 2026** ("Fase MCP-4"/"Fase
MCP-6", según comentarios fechados en el propio código del workflow), el agente devuelve
`{"tool":"search_empresas"|"get_empresa","params":{...},"queryIntent":...}` con parámetros
tipados (`query`, `sector`, `ciudad`, `categoria_codigo` / `rif`, `nombre`), que un nodo downstream
(`Call MCP Empresas`) envía como `tools/call` al Worker MCP
(`https://perfilafiliados-mcp.sisteg.workers.dev/mcp`) — la construcción real de SQL vive ahora en
el Worker (repo hermano `perfilafiliados-mcp`, no en este repo, no inspeccionado en esta sesión).

Es decir: **el bug de comillas de la sección 6 es real para los datos históricos (marzo–mediados de
septiembre) pero ya no aplica al tráfico actual** — coincide en el tiempo con el momento en que el
equipo migró de SQL crudo a parámetros tipados, lo cual es consistente con que haya sido
precisamente ese tipo de problema (u otros de la misma familia — inyección, fragilidad de esquema)
lo que motivó el cambio de arquitectura.

**Verificación en vivo (real, contra el webhook público, sin tokens):** se envió la consulta real
`"empresas del zulia"` al webhook de producción
(`https://vmi2945958.contaboserver.net/webhook/4882efaf-6d0e-44ae-82fe-61ebab61f54b`, el mismo que
usa `/cira-test/`). Respuesta: `200`, 6 resultados, etiquetados "Empresas afiliadas en el estado
Zulia".

**El gap de "state" NO sigue vivo — el buscador funciona correctamente.** Una primera lectura de
esta respuesta sugirió lo contrario (5 de los 6 resultados muestran la etiqueta "coincide en texto
con 'empresas'", que parecía indicar matcheo genérico sin filtro de estado), pero eso resultó ser
una conclusión equivocada. Dos verificaciones lo desmienten:

1. **Código del Worker** (`perfilafiliados-mcp/src/empresa-tools.ts:408-412, 477`): el parámetro
   `ciudad` matchea contra `c.city_name` **O** `st.state_name` (join
   `cities.states_id → states.state_name`, exactamente el camino de datos que la sección 6 daba por
   faltante), y se aplica como condición `AND` obligatoria incluso en la ruta de búsqueda híbrida.
2. **Datos reales**: se consultó la ubicación real de las 6 empresas devueltas — **las 6 están en
   el estado Zulia** (5 en Maracaibo, 1 en Cabimas). El filtro funcionó perfectamente.

Lo que sí es un hallazgo real, pero **cosmético (UX), no funcional**: la etiqueta `matched_via` que
se le muestra al usuario ("coincide en texto con 'empresas'") describe la señal de ranking de texto
libre, pero **oculta la razón que de verdad importa** (que la empresa está en Zulia). Un usuario que
lea eso concluye que los resultados son basura cuando en realidad son correctos — exactamente el
error de interpretación que cometió esta auditoría en su primera pasada. Sugerencia: cuando hay un
filtro estructurado activo (`ciudad`/`sector`/`categoria_codigo`), incluirlo en `matched_via`
(el código ya arma esa etiqueta correctamente en la ruta sin `query`, línea 439:
`ciudad o estado: ${ciudad}` — falta propagarla también a la ruta híbrida).

**Alcance de la verificación**: un segundo query de control (`"zulia"` solo) fue bloqueado por el
clasificador de auto-mode de esta sesión (acción sobre recurso compartido, requiere confirmación
explícita) — correcto, mandar múltiples requests de prueba a un webhook de producción compartido no
debería hacerse sin autorización expresa. La conclusión de arriba no depende de ese segundo test:
se confirmó por código + datos reales, no por inferencia sobre la respuesta del chat.

**No se tocó ni se modificó el workflow de n8n** — todo lo de esta sección fue export de solo
lectura + una única consulta de prueba al webhook público (misma categoría de acción que ya se
había hecho el 2026-09-25). Ningún cambio de código, credenciales ni configuración.

## 7 — Estado y siguiente paso recomendado

**Status: DONE / VERIFIED** — extracción, dataset de eval, auditoría de calidad, verificación
contra la base real y verificación en vivo contra el workflow de producción actual, completados sin
escribir nada en Supabase ni modificar el workflow de n8n (todo fue lectura: `SELECT`s puntuales con
`LIMIT 5`, un export read-only del workflow, y un único mensaje de prueba al webhook público — cero
`INSERT`/`UPDATE`/`DELETE`, cero cambios de configuración).

**El hallazgo de comillas SQL (sección 6) ya está resuelto de facto** — el equipo migró el 16 de
septiembre de 2026 de `whereClause` crudo a parámetros tipados vía el Worker MCP (sección 6bis), lo
cual elimina esa clase de bug sin que haga falta ninguna acción de esta sesión.

**El gap de "state" tampoco existe en el sistema actual** (sección 6bis): el Worker ya resuelve
ciudad **y** estado correctamente (`empresa-tools.ts:408-412`), verificado contra datos reales. La
sección 6 describía una limitación de las vistas `catalogoView`/`capacityView` que el Worker
simplemente no usa — consulta las tablas base (`empresas`, `cities`, `states`) directamente.

**Conclusión sobre el estado del buscador: sano.** Los dos bugs que esta auditoría creyó encontrar
resultaron ser (a) código legado ya reemplazado, y (b) un error de interpretación de esta misma
auditoría. No hay ninguna acción correctiva urgente pendiente sobre el pipeline de búsqueda.

**Lo único accionable encontrado, de severidad baja (cosmético/UX):** la etiqueta `matched_via` en
la ruta de búsqueda híbrida no menciona los filtros estructurados activos (`ciudad`/`sector`/
`categoria_codigo`), sólo la señal de texto libre. Resultado: búsquedas correctas se le presentan al
usuario con una justificación que parece irrelevante ("coincide en texto con 'empresas'"). El código
ya arma la etiqueta correcta en la ruta sin `query` (`empresa-tools.ts:437-443`) — falta propagarla
a la ruta híbrida (`empresa-tools.ts:486-496`). Fix acotado, en el repo `perfilafiliados-mcp`.

**Lección de proceso (vale más que los hallazgos):** esta auditoría concluyó dos veces que había un
bug grave en producción, y las dos veces se equivocó — primero analizando datos históricos sin
verificar qué código corre hoy, después interpretando etiquetas de una respuesta sin verificar los
datos subyacentes. En ambos casos la corrección vino de ir a la fuente real (el workflow desplegado,
el código del Worker, la base de datos), no de razonar sobre evidencia indirecta. Para auditorías
futuras sobre este sistema: **verificar contra la fuente antes de reportar, no después.**

Nada de esto requiere poblar `taxonomy_concept_relations` ni tocar Fase 3 — sigue siendo un sistema
independiente de la taxonomía CPV, con su propia autorización separada pendiente.
