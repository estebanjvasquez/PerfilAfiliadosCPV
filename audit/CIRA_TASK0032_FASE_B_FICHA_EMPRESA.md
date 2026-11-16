# TASK-0032 · Fase B: «Empresa no afiliada» al pedir la ficha de la empresa 177

| Campo | Valor |
|---|---|
| Fuente | Issue #2 `6924316065` §2 + adenda `6924366566` §3 |
| Estado de la fase | **PHASE_B_DEPLOYED_FOR_REVIEW**, solo staging |
| Worker `perfilafiliados-mcp` | `8796f292` (código `1ea9175`) → **`8ce4e0ff-c7fd-4c7f-b566-0bdea179dd2e`** (código `4599217`, rama `fix/t32-get-empresa-id`, sin W1), 2026-11-16 19:06:54Z |
| Flujo n8n `zbVLoCdR09IA9yQK` | `c6c70402` (fase A) → **`2d0a1ee8-5a7f-4c7a-bcfd-8404b3e04fbb`**, 19:12:49Z |
| BD, `chamber_empresa`, datos de empresas | Sin cambios (`chamber_empresa`: 173 filas antes y después; solo lecturas en transacción `READ ONLY`) |
| Modelo, Laravel | Sin cambios |

## 1. Causa raíz (con evidencia, antes de cambiar código)

Recorrido real (resultado → clic → agente → herramienta → BD → respuesta), en ejecuciones de n8n leídas por la API:

1. **Resultado:** la búsqueda «camiones de transporte de maquinaria pesada» es `cira_search_log.id = 108`. Corresponde a la ejecución n8n `22715` (sesión `test-18313ca8…`) y devolvió `{177, 333}`, con la 177 en el puesto 1.
2. **Clic:** `Format Cards2` arma el pedido de ficha con el **nombre** y sin el id: `sendPrompt('dame información sobre la empresa ' + nombre)`. Las ejecuciones `22716` y `22717` recibieron «dame información sobre la empresa INTERNATIONAL OFFSHORE ENGINEERING AND DEVELOPMENT I.O.E.D COMPANY, C.A (I.O.E.D COMPANY C.A.)».
3. **Agente:** aplicó la regla del prompt para `get_empresa` («si el nombre trae siglas entre paréntesis, usa las siglas») y quitó los puntos. El resultado fue `{"tool":"get_empresa","params":{"nombre":"ioed"},"queryIntent":"COMPANY"}`.
4. **Herramienta (Worker `get_empresa`, código `1ea9175`):**
   - `where e.status_id = 1 and unaccent(e.name) ilike '%ioed%'` da **0 filas**, porque el nombre guardado es «I.O.E.D».
   - El respaldo `word_similarity('ioed', name) > 0.5` tampoco encuentra nada: vale 0,2 para la 177, que además es el máximo entre todas.
   - En cambio, `ilike '%i.o.e.d%'` y la comparación sin puntuación sí encuentran la 177.
5. **Respuesta:** con 0 filas, `If2` lleva a `Code in JavaScript1`, que con intención COMPANY pinta «Empresa no afiliada».

**`chamber_empresa` no interviene en ningún punto del recorrido**, ni en el SQL del Worker ni en n8n.
- La 177 tiene 0 filas allí; también 314 de las 406 empresas activas.
- La 157 tiene 2 filas.
- La correlación con el fallo es casual: la causa es la reescritura del nombre.

**Criterio de pertenencia al Perfil en el código:** `empresas.status_id = 1`.
- Es la columna «Activo» en Filament: las acciones masivas ponen 1 o 0 y el filtro se llama «Activo».
- El índice `BuildEmpresaSearchDocuments` usa el mismo filtro.
- Hoy las 406 empresas tienen `status_id = 1`.

Consultas de respaldo (solo lectura, transacción `READ ONLY` con rollback):

```sql
select id from empresas e where e.status_id = 1 and unaccent(e.name) ilike unaccent('%ioed%');      -- 0 filas
select word_similarity(unaccent('ioed'), unaccent(name)) from empresas where id = 177;              -- 0.2
select id from empresas e where e.status_id = 1 and unaccent(e.name) ilike unaccent('%i.o.e.d%');  -- 177
select empresa_id, count(*) from chamber_empresa where empresa_id in (177, 157) group by 1;        -- solo 157 (2)
```

## 2. Corrección mínima

**Desde la tarjeta: id estable y determinista.**
- `Format Cards2` envía «dame información sobre la empresa `<nombre>` [ID `<id>`]».
- `Parse Intent JSON3` reconoce ese marcador (patrón anclado, id de 1 a 9 dígitos) y fija `get_empresa {id}`. Ya no depende de cómo el agente reescriba el nombre.
- `humanSummary` conserva el nombre para el mensaje.
- Constructor: [`build_detail.mjs`](../docs/cira/task0032/n8n/build_detail.mjs). Cambia 3 nodos (el tercero, `Build Search Log`, solo para agregar la revisión `T32-ficha-id`); las conexiones no cambian.

**Worker `get_empresa`** (diff: [`4599217_get_empresa.diff`](../docs/cira/task0032/worker/4599217_get_empresa.diff)):
- **`id` opcional con prioridad:** busca con `status_id = 1 and id = …`. Si no encuentra la empresa, responde «no encontrada»; no cae a otro criterio.
- **Respaldo para consultas de texto:** compara nombre y término sin signos de puntuación, conservando los espacios. Solo actúa cuando las búsquedas existentes no dieron ninguna fila, así que lo que antes encontraba algo no cambia.
- **Se conservan** el filtro `status_id = 1` y el `limit 5`.
- **No se agrega** ningún `JOIN`, filtro ni vínculo con `chamber_empresa`.

**Lo que no se tocó:** el prompt (salvo la fase A), capacidades/experiencias, puntaje, `search_empresas`, votos, evaluaciones, datos y W1.

**Nota de UX:** la burbuja del usuario muestra el marcador `[ID n]`, porque es el texto que se envía.

## 3. Gates

| Gate | Resultado |
|---|---|
| Aislamiento: n8n | El mismo flujo de la fase A. Solo lo usa CIRA Test (staging), con evidencia de origen de las ejecuciones |
| Aislamiento: Worker | En n8n, solo dos flujos activos lo usan y los dos son de staging. `main`, la base de producción (último commit del 2026-09-09), no referencia el Worker. Laravel de staging solo usa `/embed`, que no cambia. La herramienta `get_empresa` solo la llama CIRA Test |
| Cobertura de la autorización | `6924316065` §2: «corrección mínima compatible que priorice ID estable al venir de tarjeta y mantenga fallback seguro para consultas textuales», con despliegue solo si se demuestra el aislamiento. La rama sale de `1ea9175` (el código desplegado), **sin W1** (`988f87f`); el modelo no cambia |
| Worker candidato, vista previa sin tráfico `8ce4e0ff` | `get_empresa`, vivo frente a candidata ([`fase_b_get_empresa_regresion.json`](../docs/cira/task0032/resultados/fase_b_get_empresa_regresion.json)): con el nombre completo, **406/406 idénticas** y con la empresa correcta. Siglas reescritas al estilo del agente, 33 casos: el vivo encuentra 30 y la candidata 31, **0 pérdidas**. Por `id`: las **314/314** empresas sin `chamber_empresa`. El `id` manda sobre un nombre contradictorio; un id inexistente da «no encontrada»; los negativos no cambian. `search_empresas`: **110/110 idénticas** (55 consultas, por defecto y con grupos) |
| Copia aislada de n8n `d8BbBdTzk8WzZhzv` (Worker de vista previa, sin registro) | 11/11 conforme a lo esperado ([`fase_b_copia_aislada.json`](../docs/cira/task0032/resultados/fase_b_copia_aislada.json)): las tarjetas llevan `[ID n]`; las fichas por id de 177, 157 (con `chamber_empresa`), 143 y 150 (sin `chamber_empresa`, nombres con paréntesis) y 317 (con apóstrofo) abren correctamente; texto libre «I.O.E.D COMPANY» y el **nombre completo original sin id** llegan al Worker como `"ioed"` y ahora encuentran la 177; el negativo y el id inexistente muestran el mensaje de siempre; «gandola» sigue con grupos |
| Rollback ensayado | n8n: en esa copia, los 3 nodos vuelven a los del vivo V1b (4/4 nodos iguales); después se borró. Worker: el comando de rollback es el mismo de TASK-0029, con la versión `8796f292` disponible |
| STOP/HOLD | Issue #2 revisado a las 19:06:22Z: sin comentarios nuevos aparte de la entrega de la fase A (`6925761178`) |
| Despliegue | Worker: se verificó `8796f292` al 100 % antes de pasar a `8ce4e0ff` al 100 %. Después, en vivo: `get_empresa {id:177}` → 177, `{nombre:"ioed"}` → 177; `search_empresas` 110/110 idénticas a `8796f292`. n8n: vivo = V1b publicado (respaldado) → `PUT` → `2d0a1ee8`; publicada = cuerpo enviado; diff de 3 nodos |

## 4. E2E en staging (escritorio y Pixel 5)

[`e2e_faseB.json`](../docs/cira/task0032/resultados/e2e_faseB.json), capturas `faseB_*` en [`docs/cira/task0032/capturas/`](../docs/cira/task0032/capturas/).

| Consulta → clic en la primera tarjeta | Escritorio | Pixel 5 |
|---|---|---|
| camiones de transporte de maquinaria pesada → **177** | 10,3 s; la ficha «Información de INTERNATIONAL OFFSHORE …» en 7,2 s; voto ✓ | 7,9 s; ficha 177 en 5,6 s; voto ✓ |
| lukiven → 157 | 9,7 s; ficha en 4,6 s | 6,8 s; ficha en 3,9 s |
| gandola → 165 | 7,5 s; ficha en 4,6 s | 4,9 s; ficha en 5,2 s |
| macolla | 6,0 s; 0 capacidades y botón a experiencias | 5,1 s |

- **Página:** sin errores de JS ni desplazamiento horizontal; vistas y `aria-pressed` como en la fase A.
- **Registros:** `cira_search_log` 127–140, todos sin error y con `engine_version` `…+T32-v1b | T30-B r2 + T32-ficha-id | worker 8ce4e0ff`. Los pedidos de ficha quedan registrados como `get_empresa {"id": 177 | 157 | 165}`.
- **Resultados:** «gandola» y «macolla» devuelven los mismos ids que en la fase A.
- **Evaluaciones nuevas:** 0.

**Datos que dejó el E2E:**
- 14 filas de `cira_search_log` (127–140);
- 2 votos de sesiones `test-…`;
- la memoria de chat de esas sesiones.

## 5. Rollback (cada mitad es independiente)

- **n8n:** `PUT` de `%USERPROFILE%\.cpv\n8n_backups\zbVLoCdR09IA9yQK_2026-11-16_pre-t32b_ROLLBACK_body.json`, que es V1b `c6c70402`. Para volver además a antes de la fase A: `…_pre-t32_ROLLBACK_body.json` (`7feb48bc`).
- **Worker:** `npx wrangler versions deploy 8796f292-5ee5-446b-98ce-7ae36099dde9@100% -y` (desde el repo `perfilafiliados-mcp`).
- **Orden recomendado:** primero n8n y después el Worker. El Worker nuevo es compatible con el flujo anterior, y el flujo nuevo envía `id`, que solo entiende el Worker nuevo.

## 6. Límites y pendientes

- **Mensaje cuando no se encuentra la empresa:** cuando una búsqueda de texto no encuentra una empresa que sí está en el Perfil, el mensaje sigue diciendo «Empresa no afiliada». El caso de la tarjeta ya no puede llegar ahí, pero un texto libre muy distinto del nombre real sí. Cambiar la redacción a «no la encontramos en el Perfil de Afiliados» sería un cambio de texto aparte, no pedido.
- **Pendiente:** UAT manual del propietario.
- **Fuera de alcance:** W1 (fase C) no está autorizado; producción no se toca.
