# Herramientas de evaluación del buscador

| Script | Para qué |
|---|---|
| [`build_golden_set_lote001.js`](build_golden_set_lote001.js) | Convierte el Excel del lote 001 en [`../golden_set_lote001.json`](../golden_set_lote001.json). |
| [`evaluate_search.mjs`](evaluate_search.mjs) | Corre las consultas de la lista contra el buscador y mide aciertos y ruido conocidos. |
| [`measure_cira_n8n.mjs`](measure_cira_n8n.mjs) | Mide la ruta completa chat → n8n → MCP, con la reescritura del agente incluida. |
| [`compare_cira_n8n.mjs`](compare_cira_n8n.mjs) | Compara dos corridas de `measure_cira_n8n.mjs` caso por caso: tool, consulta enviada y variación entre repeticiones. |

Todos funcionan con Node 18 o superior y no tienen dependencias. Ninguno escribe en la base ni en el
Worker. `measure_cira_n8n.mjs` sí ejecuta el flujo de n8n (una conversación nueva por envío, con
`sessionId` que empieza con `eval-`), y el flujo guarda su memoria de chat como en cualquier conversación.

## `measure_cira_n8n.mjs`

Envía cada consulta al webhook del flujo y después lee la ejecución por la API de n8n (solo lectura).
Por envío guarda: la consulta original, la versión del prompt (`promptVersion`) y del flujo
(`versionId`), la interpretación de `resolve_search_intent`, la tool y los parámetros que salieron del
agente, si la guarda restituyó la consulta, la respuesta de texto cuando no hubo búsqueda y los
resultados en el orden en que se muestran.

Accesos, solo por variables de entorno: `CIRA_WEBHOOK_URL`, `N8N_BASE_URL` y `N8N_API_KEY`. La URL del
webhook de un flujo de prueba no se escribe en el repo: es un endpoint público sin autenticación.

```powershell
node docs/uat/tools/measure_cira_n8n.mjs --workflow <id> --golden docs/uat/golden_set_lote001.json `
  --cases docs/uat/cira_n8n_cases_task0013.json --reps 3 --save corrida.json
node docs/uat/tools/evaluate_search.mjs --from corrida.rep1.json --compare otra.rep1.json
node docs/uat/tools/compare_cira_n8n.mjs antes.json despues.json
```

`--save` escribe un archivo por repetición (`corrida.repN.json`) con el formato de `--from`, para
calcular las mismas métricas que la medición directa. Como el agente no es determinista, se comparan
repeticiones, no una sola corrida.

## `evaluate_search.mjs`

### Modos

| Modo | Qué llama | Qué mide | Token (variable de entorno) |
|---|---|---|---|
| `mcp` (por defecto) | `/mcp`: `resolve_search_intent` y después `search_empresas`, en el mismo orden que CIRA | La lista ya cortada (20, o 150 si hay más de 20 coincidencias directas), como la recibe n8n | `CPV_MCP_TOKEN` |
| `debug` | `/debug-search` | El ranking completo. El corte 20/150 se simula y lo que queda fuera se guarda en `beyond_cut` | `CPV_DEBUG_TOKEN` |

Lo que el script **no** reproduce es la reescritura de la consulta que hace el agente de n8n. Mide el
motor con la consulta tal como la escribió el cliente. Si n8n la reduce a una "palabra raíz", el
resultado en el chat puede diferir: para eso está `measure_cira_n8n.mjs`.

### El token

El token se lee de una variable de entorno: no se acepta como argumento, no se imprime y no se guarda en
la corrida. En Windows PowerShell 5.1, para que no quede en el historial:

```powershell
$t = Read-Host 'Token' -AsSecureString
$env:CPV_MCP_TOKEN = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($t))
```

### Uso

```powershell
# Corrida de partida, guardada
node docs/uat/tools/evaluate_search.mjs --save docs/uat/runs/2026-11-xx_base.json

# Después de un cambio: nueva corrida comparada contra la base
node docs/uat/tools/evaluate_search.mjs --save docs/uat/runs/2026-11-xx_cambio.json --compare docs/uat/runs/2026-11-xx_base.json

# Recalcular sin red desde una corrida guardada
node docs/uat/tools/evaluate_search.mjs --from docs/uat/runs/2026-11-xx_base.json

# Solo algunos casos
node docs/uat/tools/evaluate_search.mjs --only G2-01,G3-05
```

Otras opciones: `--no-intent` (busca solo la consulta literal), `--mode debug`, `--url`, `--golden`,
`--delay`, `--json`. Con `--help` se ven todas.

Código de salida: `0` si todo va bien, `1` si `--compare` encuentra algún caso que empeora y `2` si hay
un error de uso o de acceso.

### Qué reporta

Por caso:
- empresas devueltas;
- posición de la primera relevante conocida;
- relevantes conocidas en los primeros 5 y 10;
- ruido conocido (empresas marcadas como no relevantes) en los primeros 5 y 10.

Los casos G4-02 y G7 se verifican por su comportamiento esperado: pedir aclaración, no devolver
resultados o declinar.

Resumen general:
- % de casos con alguna relevante conocida en el top 5;
- MRR (inverso de la posición de la primera relevante, promediado);
- relevantes conocidas recuperadas en el top 10;
- ruido conocido total en el top 5 y en el top 10;
- en qué consultas siguen apareciendo en el top 10 las empresas que el cliente marcó como ruido varias
  veces (sin contar las consultas donde esa empresa sí era relevante).

Las empresas sin juicio del cliente son **desconocidas**, no negativas, y no cuentan como ruido.
Detalles en [`../GOLDEN_SET_LOTE001.md`](../GOLDEN_SET_LOTE001.md).

### Regla de uso

Ningún cambio de ranking se acepta solo porque mejora una consulta. Se mira el resumen completo y la
lista de casos que empeoran, junto con la regresión congelada del Worker
(`perfilafiliados-mcp/scripts/regression-suite.mjs`).
