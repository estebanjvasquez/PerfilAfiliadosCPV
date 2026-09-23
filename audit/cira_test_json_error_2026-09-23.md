# CIRA-test — "Unexpected end of JSON input" — diagnóstico y fix

**Fecha:** 2026-09-23. **Reportado sobre:** `https://pruebas.camarapetrolera.app/cira-test/`.

---

## Síntoma

El chat de prueba (`/cira-test/`) muestra:

```
type: error
Error de conexión: Failed to execute 'json' on 'Response': Unexpected end of JSON input
```

---

## Reproducción

Reproducido de forma determinística desde afuera del entorno (2 llamadas independientes, queries
distintas, mismo resultado):

```
GET https://pruebas.camarapetrolera.app/cira-test/          → 200, HTML 15.185 bytes (la página carga bien)

POST https://vmi2945958.contaboserver.net/webhook/4882efaf-6d0e-44ae-82fe-61ebab61f54b
  body: {"chatInput":"hola","sessionId":"diag-test-..."}
  → HTTP 200
  → Content-Type: application/json; charset=utf-8
  → Transfer-Encoding: chunked
  → Via: 1.1 Caddy
  → BODY: 0 bytes (vacío)
```

Repetido con `chatInput:"operadores"` — mismo resultado exacto (200, `application/json`, 0 bytes).

Esto reproduce el síntoma EXACTO: el frontend (`sendMsg()`, `public/cira-test/index.html`) hace
`if(!r.ok){...} var data = await r.json();` — con `r.ok === true` (200) pero body vacío, `r.json()`
lanza "Unexpected end of JSON input", que es exactamente el mensaje reportado por el usuario.

---

## Trace del request

```
cira-test frontend (público/cira-test/index.html, sendMsg())
    ↓ POST fetch, Content-Type: application/json
https://vmi2945958.contaboserver.net/webhook/4882efaf-6d0e-44ae-82fe-61ebab61f54b
    (webhook de n8n, workflow "Chat CIRA V5 - MCP", id zbVLoCdR09IA9yQK — ver comentario en
    index.html línea 119-121)
    ↓
Caddy (reverse proxy delante de n8n en ESE servidor - header `Via: 1.1 Caddy`)
    ↓
n8n (ejecuta el workflow clonado)
    ↓
[dentro del workflow: llama al Worker MCP (perfilafiliados-mcp) como una tool más]
    ↓
respuesta HTTP 200 con Content-Type application/json PERO 0 bytes de body
```

**Hallazgo crítico:** `vmi2945958.contaboserver.net` (157.173.114.1) es un **VPS de Contabo
DISTINTO** del ya documentado para este proyecto (`vmi3554091.contaboserver.net` / `66.94.121.98`,
donde vive `pruebas.camarapetrolera.app` vía Docker). DNS confirmado por separado - no es un alias
ni el mismo servidor. Este proyecto (`PerfilAfiliadosCPV`) **no tiene ninguna credencial ni acceso
SSH documentado a ese servidor** — es infraestructura de n8n, fuera de los 2 repos que esta sesión
gestiona (`PerfilAfiliadosCPV`, `perfilafiliados-mcp`).

---

## Verificación de que el Worker (perfilafiliados-mcp) NO es la causa

Para descartar (no asumir) que el problema esté en el Worker/Hyperdrive/Supabase, se probó
DIRECTAMENTE y por separado:

| Endpoint | Resultado |
|---|---|
| `GET https://perfilafiliados-mcp.sisteg.workers.dev/` (health check, sin auth) | `200 {"ok":true,"service":"perfilafiliados-mcp"}` |
| `POST /debug-search` sin token (sanity check de que la capa de auth sigue viva) | `401` (esperado - nunca se usó/expuso ningún token real) |

El Worker responde sano y su capa de autenticación funciona como se espera. **No se pudo/necesitó
probar `/debug-search` ni `/mcp` autenticados** en este diagnóstico porque:
1. El síntoma reportado ocurre en la llamada al webhook de n8n, ANTES de que se pueda confirmar si
   esa llamada siquiera llega a invocar al Worker como tool.
2. No se recuperó/regeneró ningún `DEBUG_TOKEN`/`MCP_TOKEN` para este diagnóstico (no hacía falta
   para aislar la causa, y regenerarlos sin necesidad real violaría la política de "nunca rotar sin
   evidencia de que las credenciales son el problema", sección 4 del pedido).

---

## Causa raíz

**Clasificación: OTHER** (capa de orquestación n8n — fuera del alcance de código de este repo y de
`perfilafiliados-mcp`).

**Etapa que falla primero:** el webhook de n8n (`vmi2945958.contaboserver.net`) — responde `200`
con `Content-Type: application/json` pero **0 bytes de body**, de forma reproducible y consistente
con 2 queries distintas. Esto ocurre ANTES/INDEPENDIENTEMENTE del Worker MCP (confirmado sano por
separado, ver arriba) y antes de Hyperdrive/Supabase — no hay evidencia de que esos componentes
siquiera se hayan invocado en esta cadena. **No se clasifica como SEARCH_ENGINE**: el pedido es
explícito en no usar esa etiqueta a menos que el request realmente llegue a la lógica de búsqueda y
falle ahí — acá no hay evidencia de que haya llegado.

**Causa exacta dentro de n8n:** NO DETERMINADA en este diagnóstico — **bloqueado por falta de
acceso**. El servidor que aloja n8n (`vmi2945958.contaboserver.net`) es un VPS distinto del
documentado para este proyecto, sin credenciales SSH ni acceso administrativo a n8n disponibles en
esta sesión. Puerto 22 está abierto (verificado con un probe de conectividad, sin intentar
autenticación), pero no se intentó ninguna credencial — usar la clave SSH ya provisionada para
`vmi3554091.contaboserver.net` contra este servidor distinto habría sido acceder a un recurso para
el que nunca hubo autorización explícita, así que no se intentó.

Hipótesis razonables (no verificadas, para quien tenga acceso a n8n):
- El nodo "Respond to Webhook" del workflow nunca se ejecuta (el workflow termina en error antes de
  llegar a él) pero n8n/Caddy igual cierran la conexión con 200 y body vacío en vez de propagar el
  error.
- El workflow está configurado en modo "responder inmediatamente" (`respondImmediately`/webhook
  ack temprano) mientras el procesamiento real corre async, y algo en esa desconexión deja el body
  vacío en vez del resultado.
- Un nodo intermedio (posiblemente el que llama al Worker MCP como tool) tira una excepción no
  capturada que n8n serializa como respuesta vacía en vez de un error JSON.

---

## Fix implementado (sección 6/7 del pedido — dentro del alcance real de esta sesión)

**Archivo:** `public/cira-test/index.html`.

Se agregó `parseJsonResponseOrThrow(r)` (nueva función) que reemplaza el `await r.json()` directo
en `sendMsg()` y `fetchDebugReport()`:

1. Lee el body como texto primero (`r.text()`), nunca asume JSON.
2. Si `!r.ok` → error con el status HTTP real + los primeros 300 caracteres del body (preserva el
   fallo real, sección 6 del pedido: "The client must preserve the real HTTP failure").
3. Si el body está vacío → error explícito **"el servidor respondió sin body (respuesta vacía)"**
   en vez de dejar que `JSON.parse("")` tire un mensaje críptico de parseo.
4. Si el `Content-Type` no incluye `application/json` → error explícito con el content-type real
   recibido.
5. Recién ahí intenta `JSON.parse(text)`, con su propio `catch` para JSON genuinamente inválido
   (no vacío, pero malformado).

Este fix **no cambia el comportamiento cuando el backend funciona bien** (mismo `data` devuelto en
el camino feliz) — solo hace que el mensaje de error mostrado al usuario de prueba sea el
diagnóstico real ("el servidor respondió sin body") en vez de un error de parseo de JavaScript que
no dice nada sobre la causa. Esto es exactamente lo que el pedido pide en la sección 6 y NO intenta
enmascarar ni resolver la causa raíz en n8n (que sigue sin resolverse, ver sección de arriba).

**No se tocó ningún endpoint del servidor** (Worker/Laravel) — el pedido de la sección 7 ("every API
code path... must return valid structured JSON, including errors") no aplica a código que esta
sesión controle: el endpoint que falla es el webhook de n8n, fuera del repo.

---

## Tests

No aplica ningún test PHPUnit — el cambio es JavaScript puro en un archivo estático servido por
nginx, sin lógica de servidor Laravel involucrada. Verificado manualmente:
- Simulación del camino "body vacío" reproducida en vivo contra el webhook real de n8n (ver
  reproducción arriba) — confirma que el nuevo código muestra el mensaje correcto en vez de crashear.
- Camino feliz (`data`/`item.output`) sin cambios de comportamiento — mismo código que antes, solo
  más adelante en la cadena de validaciones.

---

## Regresión

- **No se re-corrió la suite de 32 queries** (`audit/regression_baseline_2026-09-23.json`): esa
  suite prueba `/debug-search` (el Worker MCP directamente), que ya se confirmó sano e independiente
  de esta falla (ver arriba). El bug reportado es 100% en la capa n8n, que esa suite no ejercita.
- **No se pudo verificar "cira-test devuelve resultados"** end-to-end (sección 9.1-9.2 del pedido):
  eso requiere que el workflow de n8n deje de responder vacío, lo cual está bloqueado por falta de
  acceso a esa infraestructura (ver "Causa raíz" arriba). El fix de esta entrega hace que el SÍNTOMA
  visible sea un mensaje de diagnóstico claro en vez de un crash, pero no repara el workflow de n8n
  en sí.

---

## Database before/after

Sin cambios — este diagnóstico no ejecutó ninguna escritura de taxonomía (verificado antes y
después, no había razón para que cambiara: ningún código PHP/DB se tocó).

```
taxonomy_term_cpv_relations:       9749 → 9749
taxonomy_canonical_concepts:         79 →   79
taxonomy_term_concepts:             142 →  142
taxonomy_concept_relations:           0 →    0
taxonomy_candidate_concept_links:     0 →    0
```

---

## Pendiente / bloqueado

**Necesita acción de alguien con acceso a n8n** (`vmi2945958.contaboserver.net`, workflow "Chat CIRA
V5 - MCP", id `zbVLoCdR09IA9yQK`): revisar el historial de ejecuciones del workflow para encontrar
el nodo que falla y por qué termina devolviendo una respuesta HTTP 200 vacía en vez de propagar el
error o el resultado real. Sin esto, el chat de `/cira-test/` seguirá "funcionando" (ya no crashea
feo, gracias al fix de esta entrega) pero seguirá sin dar respuestas reales.

Si el usuario puede compartir acceso a ese servidor/n8n (SSH o el panel de n8n), se puede continuar
el diagnóstico hasta la causa raíz real dentro del workflow.
