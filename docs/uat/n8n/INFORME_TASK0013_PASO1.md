# TASK-0013, paso 1: consulta completa en el agente de CIRA (n8n)

Asignación: Issue #2 `6686193637` (2026-11-06). Propuesta para revisión, **no aplicada al flujo que usa
staging**. Un resultado así no se acepta automáticamente.

## 1. Qué flujo atendió la UAT (solo lectura)

| Dato | Valor |
|---|---|
| Flujo | «Chat CIRA V5 - MCP», id `zbVLoCdR09IA9yQK`, activo, `versionId` `e22c8dd5-d37c-4fe4-bc16-42cda305dc61`, sin cambios desde el 2026-09-17 |
| Webhook | `/webhook/4882efaf-…`, llamado solo por `public/cira-test/index.html` |
| Tráfico | las 87 ejecuciones guardadas (2026-10-26 a 2026-11-06) tienen `origin` `https://pruebas.camarapetrolera.app` (staging) |
| Producción | `camarapetrolera.app/cira-test/` y `www.…/cira-test/` responden 404. Los kioscos usan otro webhook (`cira-botV2`, flujo «Chat CIRA V5») que no se tocó |
| Recursos compartidos | el flujo usa el Worker MCP y la base Postgres compartidos (memoria de chat `chat_memory`, `agent_learnings` de solo lectura en la ruta normal) |

El flujo de la UAT es de staging y no atiende producción. Aun así, todo se probó en una **copia
aislada**: «Chat CIRA V5 - MCP [PRUEBA TASK-0013 paso 1]», id `3PtTduMeifEe2fg9`, con un webhook propio
que no se publica en el repo. Después de medir, **la copia quedó desactivada**.

Los respaldos recuperables están fuera del repo, en `%USERPROFILE%\.cpv\n8n_backups\`:
- `zbVLoCdR09IA9yQK_2026-11-06_pre-task0013-step1.json` (el original);
- `3PtTduMeifEe2fg9_2026-11-06_task0013-step1-v2.json` (la copia).

El JSON del flujo no se versiona porque incluye un nodo con una contraseña.

## 2. El cambio propuesto (v2, `promptVersion` `TASK-0013-step1-v2`)

Texto exacto del prompt: [`prompt_task0013_paso1_v2.txt`](prompt_task0013_paso1_v2.txt).
Diferencia con el actual: [`prompt_task0013_paso1_v2.diff`](prompt_task0013_paso1_v2.diff).

**Prompt («Build System Prompt»):**
- **CONSULTA COMPLETA** reemplaza a la «REGLA ÚNICA» de raíces recortadas:
  - `query` lleva toda la necesidad del usuario, en minúsculas y sin tildes;
  - solo se quitan el relleno («necesito», «empresas de»…) y la ubicación, que va en `ciudad`;
  - está prohibido recortar la frase a una sola palabra, pasarla a raíz o reemplazarla por una palabra general.
- **`resolvedPhrases`** se suman a `query` y solo con respaldo: lo que devuelve `resolve_search_intent`, y el singular cuando la consulta es una sola palabra en plural.
- **Búsqueda de empresa (CASO B)** solo con evidencia de nombre o de RIF: «la empresa …», sufijo legal, `&` o un RIF. Las mayúsculas no cuentan como evidencia. El nombre que se manda es la parte distintiva («costa norte», no «costa»).
- **Término suelto:** si es jerga de campo (guaya, cabria, mechurrio…), se busca aunque `resolve_search_intent` diga `out_of_scope` por tomarlo como un lugar. Las ciudades y estados venezolanos van en `ciudad`.
- **Aprendizajes de `agent_learnings`:** se siguen aplicando, pero no cambian la regla de `query`. Los redactados en términos de SQL o de columnas se traducen al parámetro equivalente.
- **Herramientas del agente:** el agente no llama `search_empresas` ni `get_empresa`. Esas las ejecuta el flujo con el JSON que el agente produce.

**Configuración:**
- **Cliente MCP del agente:** `include: selected`, `includeTools: [resolve_search_intent, search_taxonomy]`.
- **«Parse Intent JSON3»**, con su diferencia en [`parse_intent_task0013_paso1_v2.diff`](parse_intent_task0013_paso1_v2.diff):
  - **Guarda determinista:** cuando el agente mandó una sola palabra y la primera frase de `resolvedPhrases` la contiene junto con otras, se restituye esa frase completa. Si la frase trae ciudad o relleno, la guarda no actúa.
  - Se quitan las tildes de `query`, y la variante con tildes queda en `resolvedPhrases`.
  - La salida expone `queryRestoredFrom` y `queryAccentsStripped`.
- **Sin cambios:** el webhook (salvo en la copia), los demás nodos, las credenciales, el Worker y los nodos de registro. «Call 'CIRA_log'» sigue desconectado.

**Reversión:**
- el original no se modificó; para volver atrás basta con no promover el cambio;
- si se promueve y hay que revertir, se restaura el JSON del respaldo con `PUT /api/v1/workflows/zbVLoCdR09IA9yQK`, conservando el webhook `4882efaf-…`.

## 3. Medición de la ruta completa (chat → n8n → MCP)

[`../tools/measure_cira_n8n.mjs`](../tools/measure_cira_n8n.mjs) mide cada envío en una conversación nueva:
- los 35 casos de la lista de referencia y 13 casos adicionales ([`../cira_n8n_cases_task0013.json`](../cira_n8n_cases_task0013.json)): «GUAYAS», nombre, RIF, filtros, consultas amplias, fuera de alcance y saludo;
- **3 repeticiones** por flujo, 144 envíos en cada uno.

| Corrida | Archivo |
|---|---|
| Antes (flujo de staging, sin tocar) | [`../runs/2026-11-06_n8n_antes.json`](../runs/2026-11-06_n8n_antes.json) |
| Prueba v1 (descartada) | [`../runs/2026-11-06_n8n_prueba_v1.json`](../runs/2026-11-06_n8n_prueba_v1.json) |
| Prueba v2 (propuesta) | [`../runs/2026-11-06_n8n_prueba_v2.json`](../runs/2026-11-06_n8n_prueba_v2.json) |

### Estabilidad de la consulta

| | Antes | v2 |
|---|---|---|
| Casos con más de una variante entre repeticiones (de 48) | 15 | 3 |
| Envíos donde la consulta perdió palabras del usuario | 9 | 3 (las 3 son «en el lago» → filtro de ciudad, por un aprendizaje activo) |
| Búsquedas hechas por el agente por su cuenta (respuesta en texto libre, sin tarjetas) | 4 | 0 |

### Contra la lista de referencia (por repetición; las empresas sin juicio no cuentan como ruido)

| | Antes r1 / r2 / r3 | v2 r1 / r2 / r3 |
|---|---|---|
| MRR sobre relevantes conocidas | 0,788 / 0,783 / 0,816 | 0,817 / 0,817 / 0,817 |
| Relevantes conocidas en el top 10 (de 49) | 37 / 37 / 37 | 37 / 37 / 37 |
| Casos con una relevante en el top 5 | 90 % / 90 % / 90 % | 90 % / 90 % / 90 % |
| Ruido conocido en el top 5 (de 66) | 53 / 53 / 52 | 53 / 55 / 54 |
| Ruido conocido en el top 10 | 60 / 57 / 58 | 58 / 61 / 60 |
| Empresas devueltas, media | 17,2 / 15,7 / 17,0 | 17,5 / 17,6 / 17,6 |

**Cambios por caso:**
- **«transporte de gandolas»:** antes tuvo dos variantes (`transporte de gandola` / `transporte de gandolas`); ahora siempre `transporte de gandolas`. La reducción a `transporte` de la UAT no se repitió en ninguna de las dos corridas, y en v2 la guarda no tuvo que actuar.
- **«fabricantes de cabrias»:** antes `fabricante cabria`, una respuesta en texto y `fabricante de cabria`, con 0/0/15 resultados; ahora 14/14/14.
- **«mantenimiento de cabrias»:** antes 20/0/20; ahora 20/20/20.
- **«GUAYAS»:** antes se mandaba como `ciudad=guayas` (0 resultados) o se declinaba como topónimo; ahora es `query: guayas`, con 5 empresas por coincidencia literal. No hay juicios del cliente para este caso.
- **«empresas que fabriquen y mantengan balancines»:** antes `balancin`; ahora la frase completa, con menos ruido conocido en el top 5 (de 2-3 a 0-2).
- **Nombre:** «COSTA NORTE…» devolvía 2 empresas con `costa`; ahora 1 con `costa norte`. «SUMINISTROS NACIONALES…» devolvía 5; ahora 1.
- **Consultas que ya funcionaban** siguen igual en las 3 repeticiones: el RIF, «LUKIVEN, S.A.», los filtros por ciudad, «abogados» (antes `abogad`, el mismo resultado), «servicios a pozos», el clima, el saludo y la afiliación.

### Casos que empeoran o siguen mal

- **«mecha»:** ahora busca siempre (23 resultados, 4 de ruido conocido en el top 5), mientras que antes pedía aclaración 2 de cada 3 veces. No lo causa el prompt: en las corridas nuevas `resolve_search_intent` (Worker) devolvió `single_compound` y no `ambiguous`. Es variabilidad del Worker y suma la mayor parte del ruido extra del top 5.
- **«tratamiento de aguas de perforación en el lago»:**
  - sigue variando entre `ciudad=maracaibo` y `ciudad=zulia`, porque el aprendizaje activo de «lago» le da al agente tres ciudades y `ciudad` admite una sola;
  - en r1 el ruido del top 5 subió de 1 a 3, y en r2 y r3 bajó de 4 a 2.
- **«drilling»**, r3: antes una variante recortada (`drill`) puso 3 relevantes en el top 5; ahora quedan 2.
- **«necesito arbolitos para pozos petroleros»**, r2: el ruido del top 10 pasó de 2 a 3, comparado con la variante `arbol` de antes.
- **«dame todas las empresas»:** marca `FALLA` en las dos corridas por la métrica, pero no es una regresión. En los dos flujos la respuesta pide un criterio; lo único que cambia es que ahora el agente consulta `resolve_search_intent` y la métrica lee `out_of_scope`.
- **«caballito»:** en 1 de 3 envíos el agente buscó aunque la intención decía `ambiguous`. Devolvió 0 resultados.
- **Descartado en v1:** v1 mandaba la consulta con tildes. Con eso «camión chupón» pasó de 1 a 0 y perdió su única relevante, y «guaya eléctrica» bajó de 10 a 2. Antes el Worker buscaba dos variantes (la consulta sin tildes y la frase con tildes), y una parte de su búsqueda distingue tildes. v2 restituye las dos variantes.

## 4. Medición directa del motor (`evaluate_search.mjs --compare`)

[`../runs/2026-11-06_mcp_recheck.json`](../runs/2026-11-06_mcp_recheck.json), comparada contra `2026-11-06_base.json`:
- el motor no cambió, y la mayoría de los casos dan lo mismo;
- en «refinery» el ruido del top 5 subió de 2 a 4;
- en «dame todas las empresas» `resolve_search_intent` cambió su respuesta.

Esa diferencia es variabilidad del LLM del Worker entre dos corridas. Esta medición llama al MCP directo y no pasa por el agente, así que complementa la de n8n pero no la reemplaza.

**Regresión congelada del Worker (`perfilafiliados-mcp/scripts/regression-suite.mjs`).** En la primera
entrega estaba bloqueada porque faltaba `DEBUG_TOKEN`. El propietario lo rotó el 2026-11-06 con
`docs/orquestador/rotate_debug_token.ps1`, y la regresión se ejecutó:
- **línea base de 32 consultas** guardada en
  [`../runs/2026-11-06_worker_regression_baseline.json`](../runs/2026-11-06_worker_regression_baseline.json),
  sin errores. La línea base anterior («32/32, 288 campos, 0 diferencias») se perdió con la máquina anterior;
- **segunda corrida** contra esa base: «Sin cambios en ninguna de las 32 queries del fixture». El Worker
  es estable y este paso no lo tocó.

**Medición base en modo `debug`** (ranking completo más allá del corte):
[`../runs/2026-11-06_debug_base.json`](../runs/2026-11-06_debug_base.json), para el paso 2.
- MRR 0,708;
- 36 de 49 relevantes conocidas en el top 10;
- ruido conocido de 47 en el top 5 y 53 en el top 10.

Un caso, «como me afilio a la camara?», respondió HTTP 500 una vez; al reintentarlo dio 200, con y sin «?».
## 5. Límites

- 3 repeticiones por caso muestran tendencias, no garantías. El agente y `resolve_search_intent` siguen siendo LLM.
- La variabilidad que queda viene sobre todo de `resolve_search_intent` (en el Worker) y de los aprendizajes de `agent_learnings`, y ninguna de las dos se tocó.
- El ruido conocido no baja con este paso. Viene del ranking del Worker, que es el paso 2 del plan.
- La sensibilidad a las tildes de una parte del Worker queda registrada para el paso 2.
- Cada envío deja filas de memoria de chat (`chat_memory`) con un `sessionId` `eval-…`, igual que cualquier conversación de staging.

## 6. Promoción al flujo de staging (2026-11-06)

- **Aprobación:** la dio el propietario en el chat de desarrollo («It is approved»), después de la entrega `6687648330`.
- **Quién aplicó el cambio:** el propietario ejecutó el script de promoción en su máquina, porque el control de permisos del agente bloqueó la escritura en el flujo compartido.
- **Qué se aplicó:** sobre «Chat CIRA V5 - MCP» `zbVLoCdR09IA9yQK`, el mismo contenido que la prueba v2, nodo por nodo. Se conservan el nombre y el webhook `4882efaf-…`.

| Versión | `versionId` |
|---|---|
| Antes | `e22c8dd5-d37c-4fe4-bc16-42cda305dc61` |
| Después | `279690f8-e861-421f-8563-a4ad9f00c668` (activo) |

- **Respaldo previo**, fuera del repo: `zbVLoCdR09IA9yQK_2026-11-06_pre-promote-v2.json`. Para revertir, el mismo script se ejecuta con `-Revert` y restaura ese respaldo con `PUT`.

**Verificación por el webhook real de staging:** [`../runs/2026-11-06_n8n_staging_v2_promovido.json`](../runs/2026-11-06_n8n_staging_v2_promovido.json), 10 casos × 3 repeticiones.
- Los 10 casos dan la misma consulta y la misma cantidad de resultados en las 3 repeticiones, y coinciden con la prueba v2: «transporte de gandolas» 6, «fabricantes de cabrias» 14, «camión chupón» 1, «GUAYAS» 5, «costa norte» 1, RIF 1, construcción en Maracaibo 25 y clima sin búsqueda.
- La única diferencia: en una repetición, `resolve_search_intent` dividió «inspección y mantenimiento de guaya eléctrica» en dos frases. Igual devolvió 20 resultados.
- `https://pruebas.camarapetrolera.app/cira-test/` responde 200.

La copia `3PtTduMeifEe2fg9` sigue desactivada. Se puede borrar cuando ya no haga falta.
