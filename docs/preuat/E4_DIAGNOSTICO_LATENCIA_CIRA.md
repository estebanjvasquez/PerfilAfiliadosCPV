# E4 — Diagnóstico de latencia de CIRA (solo lectura)

**TASK-0033** · 2026-11-17 · Para: el propietario y el orquestador · Índice: [`00_INDICE.md`](00_INDICE.md)

**Conclusión en una línea:** en la UAT del propietario, una búsqueda tarda unos **10 s de extremo a extremo**. Ese tiempo
se reparte en tres partes:

- el **modelo** del agente: ~4,2 s;
- el **Worker sin caché**: ~3,8 s;
- el resto del agente, el registro, la memoria y otros nodos: ~2 s.

A eso el navegador suma ~0,6 s. El p50 de 6,1 s de la copia aislada no se repite en uso real por dos motivos medidos: el
benchmark repetía consultas, que la caché de Hyperdrive acelera, y el modelo varió más esta mañana. No se cambió nada:
ni n8n, ni el Worker, ni el modelo, ni W1.

Etiquetas: **VIVO** = medido hoy en la fuente viva · **DOC** = documentado antes · **HIP** = hipótesis · **PEND** = pendiente.

## 1. Versiones medidas (VIVO, 2026-11-17)

| Componente | Versión | Evidencia |
|---|---|---|
| n8n `zbVLoCdR09IA9yQK` | `2d0a1ee8` (publicado 2026-11-16 19:12:49Z) | [`evidencia/vivo_2026-11-17.md`](evidencia/vivo_2026-11-17.md) §1 |
| Worker | `8ce4e0ff` al 100 % | idem |
| Hyperdrive | caché de consultas **activa** (`caching.disabled = false`) | idem |
| Laravel | `5e6e3fc` | idem |
| Modelo del agente | `gpt-6-luna`; prompt de ~4.950 tokens; 1 llamada por búsqueda | [`n8n_tokens_vivo.json`](../cira/task0033/resultados/n8n_tokens_vivo.json) |

## 2. Método (reproducible, sin tráfico de búsqueda nuevo por n8n)

| # | Fuente | Qué mide | Muestra | Escritura |
|---|---|---|---|---|
| M1 | API de n8n, `executionTime` por nodo ([`n8n_timings.mjs`](../cira/task0030/latencia/n8n_timings.mjs)) | Tiempo de servidor de cada tramo | 22 ejecuciones del flujo vivo desde `2d0a1ee8`: 14 del E2E del 16/11 y **8 de la UAT del propietario del 17/11** (23156–23163) | Ninguna (GET) |
| M2 | Tiempos de navegador del propietario (`6945403230`) emparejados con su ejecución de n8n por hora y consulta | Navegador frente a n8n | 5 | — |
| M3 | `cira_search_log.response_ms` (desde la entrada del webhook hasta antes de «Log Search») | Serie histórica por versión | 142 filas (75 desde el 16/11) | Ninguna (SELECT en transacción READ ONLY) |
| M4 | Copias aisladas de TASK-0032 (ya existentes) | Por tramo y por repetición | 288 ejecuciones (V0 y V1b r1–r3) | Ninguna (archivos versionados) |
| M5 | Worker vivo por `/mcp` ([`worker_frio_caliente.mjs`](../cira/task0033/latencia/worker_frio_caliente.mjs)) | Worker solo, primera llamada y en caliente, con los argumentos exactos de la UAT | 5 `tools/list` y 30 `tools/call` | Ninguna: `/mcp` no escribe; el registro lo hace n8n |
| M6 | Worker vivo, caché ([`worker_cache_ttl.mjs`](../cira/task0033/latencia/worker_cache_ttl.mjs)) | Repetir, esperar 75 s una vez y repetir | 10 llamadas | Ninguna |
| M7 | Worker vivo, `/debug-search` ([`worker_etapas_debug.mjs`](../cira/task0033/latencia/worker_etapas_debug.mjs)) | Señales SQL frente a embedding | 6 llamadas (3 frases nuevas × 2) | Ninguna (endpoint de solo lectura) |
| M8 | Tokens del modelo por ejecución ([`n8n_tokens.mjs`](../cira/task0033/latencia/n8n_tokens.mjs)) | Si el prompt crece con la memoria | 14 ejecuciones | Ninguna (GET) |

Análisis consolidado: [`analisis_latencia.mjs`](../cira/task0033/latencia/analisis_latencia.mjs) →
[`analisis_latencia.json`](../cira/task0033/resultados/analisis_latencia.json). Horas de las llamadas directas: 2026-11-17
entre 10:59Z y 11:12Z. `cira_search_log` tenía 142 filas antes y después de las mediciones, sin filas nuevas.

**No se hizo** (y por qué):
- **Búsquedas por la interfaz o el webhook:** escriben `cira_search_log`, que es un registro real.
- **Una copia aislada nueva en n8n:** la asignación no permite editar n8n.

## 3. Resultados

### 3.1 Navegador frente a n8n (VIVO; M1 + M2)

| Escenario (propietario) | Navegador | Ejecución n8n | n8n total | Diferencia | Modelo | Worker |
|---|---:|---|---:|---:|---:|---:|
| Empresas de perforación | 12,78 s | 23158 (09:34:28Z) | 12,18 s | 0,60 s | 4,62 s | **5,33 s** |
| Balancín («balacin») | 10,74 s | 23159 (10:18:27Z) | 9,90 s | 0,85 s | 3,29 s | **4,16 s** |
| Macolla | 8,24 s | 23160 (10:20:31Z) | 7,76 s | 0,48 s | 2,79 s | **3,05 s** |
| Camiones de transporte de maquinaria pesada | 10,74 s | 23161 (10:22:08Z) | 10,05 s | 0,69 s | 4,71 s | **3,26 s** |
| Búsqueda directa («INTERNATIONAL OFFSHORE») | 10,08 s | 23163 (10:23:26Z) | 9,53 s | 0,55 s | 3,76 s | **3,50 s** |

- **Navegador menos n8n:** p50 **0,60 s** (0,48–0,85 s). Es la red hasta n8n, el TLS y el pintado. Es poco y estable.
- **Los tiempos del propietario son correctos:** están a menos de 1 s del tiempo de servidor. La latencia percibida no se
  explica por el navegador.
- **Dos ejecuciones más de la UAT, sin cronometrar en el navegador:** 23156 («balancines», 13,80 s, el modelo 6,45 s con 198
  tokens de salida) y las fichas 23157 y 23162 (5,2 s y 6,1 s).

### 3.2 Por tramo (VIVO; M1). Búsquedas `search_empresas`

| Tramo | UAT 17/11 (n=6) p50 / p95 | E2E 16/11 (n=8) p50 / p95 | Copia aislada V1b r3 (n=72) p50 / p95 |
|---|---:|---:|---:|
| **Total n8n** | **9,97 / 13,40 s** | 6,68 / 9,41 s | 6,04 / 7,96 s |
| Agente (modelo + memoria + herramientas) | 5,11 / 7,22 s | 4,00 / 5,57 s | — |
| · Modelo `gpt-6-luna` | **4,19 / 6,02 s** | 3,02 / 4,49 s | 2,92 / 4,57 s |
| · Agente sin modelo | 1,01 / 1,25 s | 0,61 / 1,21 s | — |
| **Worker** (`Call MCP Empresas`) | **3,83 / 5,17 s** | 2,06 / 3,35 s | 2,25 / 3,26 s |
| Registro (`Log Search`) | 0,56 / 0,85 s | 0,50 / 0,68 s | no aplica: la copia no registra |
| Memoria + aprendizajes | 0,47 / 0,53 s | 0,17 / 0,41 s | — |
| Tarjetas | 0,06 / 0,16 s | 0,03 / 0,05 s | — |

- **Ruta crítica (orden real de nodos):** entrada → aprendizajes → prompt → agente → interpretación → Worker → registro
  (`Log Search`) → tarjetas → respuesta. El registro va **antes** de la respuesta porque las tarjetas llevan el id del
  registro, que usan los votos y «Evaluar».
- **Pedido de ficha** (clic en la tarjeta; 2 en la UAT): 5,2–6,1 s. El Worker tarda 0,4–0,8 s, pero el **modelo se sigue
  llamando** (2,4–3,6 s), aunque `Parse Intent` reemplaza después su salida por `get_empresa {id}`.

### 3.3 Serie histórica por versión (VIVO; M3, `response_ms`, sin el registro ni las tarjetas)

| Período | Búsquedas n | p50 | p95 | Fichas n | p50 |
|---|---:|---:|---:|---:|---:|
| Antes de V1b (hasta el 16/11 18:40Z) | 97 | 11,42 s | 16,92 s | 7 | 4,36 s |
| V1b `c6c70402` (16/11 18:40–19:12Z) | 12 | 5,31 s | 8,64 s | 4 | 3,68 s |
| `2d0a1ee8`, 16/11 (E2E) | 8 | 6,04 s | 8,54 s | 6 | 4,16 s |
| `2d0a1ee8`, 17/11 (UAT del propietario) | 6 | 9,15 s | 12,35 s | 2 | 4,94 s |

V1b bajó la mediana de 11,4 a 5–6 s en las pruebas del 16/11. El 17/11 la mediana sube a 9,2 s con la misma versión. Las
secciones 3.4 y 3.5 explican la diferencia.

### 3.4 El Worker: caché de Hyperdrive y coste de la BD (VIVO; M4–M7)

- **Mismos argumentos que la UAT, llamados directamente (M5):**
  - primera llamada: 1,8–4,8 s;
  - segunda: 1,7–3,7 s;
  - tercera en adelante: **0,14–1,0 s**;
  - `tools/list`, sin BD: **61–379 ms**.

  El tiempo no es un arranque en frío del Worker, sino de la consulta.
- **Caché (M6):** «equipos de izamiento» tarda 2,72 → 0,25 → 0,14 s; tras **75 s** de pausa vuelve a **2,88 s**. Con
  «inspección de tuberías» pasa lo mismo: 2,27 → 0,52 → 0,78 s, y 2,31 s tras la pausa.
  - Esto concuerda con la caché de consultas de Hyperdrive, que está activa y por defecto dura unos 60 s.
  - **HIP con evidencia fuerte:** la respuesta no indica si hubo acierto de caché, así que no queda demostrado.
- **Etapas (M7, `/debug-search`, frases nuevas):**
  - sin caché, la señal SQL más lenta (`structured`) tarda **0,73–1,74 s**;
  - embedding y resto: **0,24–0,69 s**;
  - total del Worker: 0,96–2,43 s.
  - Repitiendo la frase: SQL **7–13 ms** y total 63–89 ms.
  - **El coste está en las consultas a la BD cuando no hay caché.** El modo `grupos` que usa CIRA Test hace más consultas,
    con variantes y dos grupos, de ahí sus 1,8–5,3 s.
- **Benchmarks aislados (M4):** el Worker mejora con cada repetición de la misma consulta.
  - V1b r2: p50 **2,34 s** en la 1.ª repetición, 0,63 s en la 2.ª y 0,44 s en la 3.ª.
  - V1b r1: 2,34 / 1,37 / 1,16 s.
  - El p50 global de esas corridas mezcla consultas con y sin caché, así que **subestima** lo que ve un usuario con una
    búsqueda nueva.
  - Este es un **sesgo del método de TASK-0031/0032**: queda registrado aquí sin reescribir esos informes.
- **En la UAT del 17/11** todas las consultas eran nuevas, después de horas sin tráfico: Worker p50 3,8 s.

### 3.5 El modelo (VIVO; M1 + M8)

- **Tokens:** el prompt mide **4.947–5.047 tokens** en todas las ejecuciones, sin crecer con la memoria de la sesión, y la
  salida 73–247 tokens. **Hipótesis «la memoria agranda el prompt»: descartada.**
- **Tiempo:** con los mismos tokens, el modelo tardó 2,4–6,5 s (p50 4,2 s el 17/11 frente a 3,0 s el 16/11). La variación es
  del proveedor o del momento del día. La muestra (6 frente a 8 búsquedas) **no basta para afirmar una tendencia**.
- **Fuera del modelo:** el agente añade ~1,0 s (memoria de Postgres, armado de la llamada).

## 4. Diagnóstico

| Pregunta | Respuesta | Nivel |
|---|---|---|
| ¿Mide bien el propietario? | Sí: navegador = n8n + 0,5–0,85 s | VIVO |
| ¿Hay regresión de V1b o de la fase B? | No se observa: los tramos que cambiaron (agente sin `resolve_search_intent`, ficha por id) siguen más rápidos que antes de V1b. La subida del 17/11 está en el Worker y en el modelo, que no cambiaron | VIVO; la muestra del 17/11 es pequeña |
| ¿Por qué el 6,1 s de la copia no se ve en uso real? | (1) Caché de Hyperdrive en las consultas repetidas del benchmark (−1,5 s en el Worker). (2) Variación del modelo (+1,2 s el 17/11). (3) El registro (+0,5 s) no existe en la copia. (4) El navegador (+0,6 s) no está en el tiempo de n8n | (1) HIP con evidencia fuerte; (2)–(4) VIVO |
| ¿Dónde está el tiempo? | Del tiempo de n8n: modelo ~40–45 %, Worker sin caché ~35–40 %, resto del agente + registro + memoria + otros ~20 %. El navegador suma ~6 % encima | VIVO (UAT, n=5–6; medianas por tramo, que no suman exactamente el total) |
| ¿Qué tarda dentro del Worker? | Las consultas SQL a la BD sin caché (señal `structured` la más lenta); el embedding es menor | VIVO (M7, modo por defecto; en `grupos` hay más consultas) |

**Límites:**
- 6 búsquedas reales del 17/11: pocas para un p95 estable.
- `/debug-search` no usa el modo `grupos`.
- La caché solo se infiere por los tiempos.
- No se midió la red entre n8n y el Worker: queda incluida en el tiempo de «Call MCP Empresas».

## 5. Opciones (ninguna implementada; cada una requiere asignación y gate propios)

| # | Opción | Componente | Beneficio estimado | Riesgo | Costo |
|---|---|---|---|---|---|
| L0 | **No cambiar nada para la UAT institucional**; avisar a los evaluadores de que una búsqueda tarda 8–14 s y una ficha ~5–6 s | Comunicación | Expectativas correctas | Ninguno técnico; percepción de lentitud | Nulo |
| L1 | **Saltar el agente en el pedido de ficha `[ID n]`**: el patrón ya se reconoce en `Parse Intent`; trasladarlo antes del agente | n8n | −2,4 a −3,6 s por ficha (de ~5,5 a ~2 s) | Bajo: la ruta es determinista y ya está probada; hay que conservar memoria y registro | Bajo: un nodo y una copia aislada |
| L2 | **Indicador de progreso** en CIRA Test («Buscando…», «Ordenando resultados…») | Página `cira-test` | Mejora la percepción, no el tiempo | Bajo | Bajo |
| L3 | **Optimizar las consultas SQL del Worker** (señal `structured` y modo `grupos`): plan de ejecución, índices, menos idas y vueltas | Worker y BD | Hasta −1 a −3 s en consultas nuevas | Medio: cambia el ranking si se toca la lógica; un índice nuevo es DDL en la BD compartida | Medio: análisis `EXPLAIN` de solo lectura primero |
| L4 | **W1** (TASK-0031) | Worker | −0,4 s p50 en frío (medido en T31) | Bajo | Bajo; **no autorizado** |
| L5 | **Registro fuera de la ruta crítica** (responder y registrar en paralelo) | n8n | −0,5 a −0,9 s | Medio: votos y «Evaluar» dependen del id del registro | Medio |
| L6 | **Prompt más corto** (~4.950 tokens) o menos tokens de salida | n8n (prompt) | −0,3 a −1 s, estimado sin medir | Medio: puede afectar la calidad; requiere el corpus de 55 | Medio |
| L7 | Cambiar de modelo | Modelo | Incierto | Alto: TASK-0031 lo desaconsejó | — |
| L8 | Ampliar la muestra con un protocolo de medición en la UAT institucional: usar `response_ms` y la ejecución de n8n de cada búsqueda real, sin cronómetro manual | Solo lectura | p50/p95 con decenas de búsquedas reales | Ninguno | Nulo (lectura posterior) |

**Recomendación** (para decidir, no para ejecutar):
- **L0 + L8** para la UAT institucional, sin cambios técnicos.
- Si se quiere mejorar antes, **L1** es el de mejor relación entre beneficio y riesgo.
- **L3** necesita primero un análisis de solo lectura (`EXPLAIN`, sin DDL).

## 6. Pendiente

- **PEND:** p95 con muestra real suficiente: se logra con L8 durante la UAT, sin generar tráfico propio.
- **PEND:** confirmar el acierto de la caché de Hyperdrive, por ejemplo con métricas del panel de Cloudflare (requiere
  acceso) o una cabecera de diagnóstico (requiere un cambio en el Worker).
- **PEND:** `EXPLAIN` de la señal `structured` en modo `grupos` (solo lectura) antes de proponer L3.
