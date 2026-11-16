# TASK-0032 · Fase A: V1b en staging (CIRA sin `resolve_search_intent`)

| Campo | Valor |
|---|---|
| Fuente | Issue #2 `6924316065` + adenda `6924366566` (propietario, 2026-11-16) |
| Estado de la fase | **PHASE_A_DEPLOYED_FOR_REVIEW**, solo staging |
| Flujo n8n `zbVLoCdR09IA9yQK` | `7feb48bc` → **`c6c70402-8af5-4427-851f-d08617c5d82d`** (2026-11-16 18:40:06Z) |
| Worker | `8796f292` sin cambios (W1 no desplegado) |
| Modelo | `gpt-6-luna` sin cambios |
| Laravel, BD | Sin cambios; sin DDL ni escrituras masivas |
| Tarea | [`docs/orquestador/tasks/0032-cira-v1b-and-company-detail.md`](../docs/orquestador/tasks/0032-cira-v1b-and-company-detail.md) |
| Evidencia | [`docs/cira/task0032/`](../docs/cira/task0032/) |

## 1. Qué cambió

Solo 2 de 39 nodos; las conexiones no cambian ([`build_v1b.mjs`](../docs/cira/task0032/n8n/build_v1b.mjs), que arma el cuerpo desde el export vivo con anclas únicas):

- **`MCP Perfil Afiliados CPV`:** `includeTools` pasa de `resolve_search_intent, search_taxonomy` a `search_taxonomy`. El agente ya no hace la segunda llamada a un modelo ni el viaje de ida y vuelta al Worker.
- **`Build System Prompt`:** la sección existente «ENTENDER LA INTENCION ANTES DE BUSCAR» se reescribe para que el propio agente interprete la frase. No se añade ningún apéndice. Las reglas son:
  - **Un concepto:** se usa la frase completa en `query`.
  - **Tildes:** la variante con tildes va en `resolvedPhrases`, también cuando el usuario las omitió («tuberia octg» → «tubería octg»).
  - **Varios conceptos:** una frase por concepto.
  - **Tipeo evidente** («balacines» → «balancines»): `query` queda como la escribió el usuario y la forma correcta va en `resolvedPhrases`.
  - **Ambigüedad:** solo cuando una frase admite dos lecturas de negocio. Un término suelto o una sigla nunca es ambiguo.
  - **Alcance:** declinar solo lo que claramente no tiene relación; ante la duda, buscar. Se conserva la excepción de jerga de campo.
  - **Limpieza:** desaparecen las 7 menciones a la tool retirada.
  - **Trazabilidad:** `promptVersion` pasa a `…+T32-v1b` y queda en `engine_version` de cada registro.
- **Lo que no cambia:** modelo, Worker, ranking, grupos, tarjetas, registro, votos, evaluaciones y contratos. `get_empresa` y `search_empresas` reciben los mismos parámetros que antes.

Copia sanitizada de la versión publicada: [`zbVLoCdR09IA9yQK_c6c70402_sanitizado.json`](../docs/cira/task0032/n8n/zbVLoCdR09IA9yQK_c6c70402_sanitizado.json).

## 2. Gates

| Gate | Resultado |
|---|---|
| Vivo verificado | Antes de empezar y justo antes del `PUT`: `7feb48bc`, idéntico byte a byte al respaldo (nodos, conexiones, versión activa). Worker `8796f292` al 100 % (`wrangler deployments status`) |
| Aislamiento respecto de producción | De 49 flujos (9 activos), solo dos activos usan el Worker, y los dos son de staging: este y «CIRA votos (staging)». Ningún otro flujo referencia este flujo ni su webhook, que solo está en `public/cira-test/index.html`. De las últimas 100 ejecuciones, 70 llegan con `origin` `https://pruebas.camarapetrolera.app` y 30 son de nuestros scripts de evaluación del 2026-11-09 (`user-agent: node`) |
| Respaldo | `%USERPROFILE%\.cpv\n8n_backups\zbVLoCdR09IA9yQK_2026-11-16_pre-t32_live.json` (`7feb48bc`, sha256 `E1CECF3194D33D68…`), fuera del repo |
| Copia aislada | 7 copias temporales: webhook propio, `Log Search` y `CIRA_log` desactivados, borradas al terminar. El vivo se verificó antes y después de cada una |
| Calidad sin degradación | PASS (§3) |
| Rollback ensayado | PASS en la copia `UuL3qipNmibULIQX`: `PUT` de V1b deja `search_taxonomy` como única tool; `PUT` del original da 0 nodos distintos y las mismas conexiones. Copia borrada |
| Diff mínimo | Cuerpo frente al vivo: cambian `Build System Prompt` y `MCP Perfil Afiliados CPV`; conexiones iguales. Versión publicada = cuerpo enviado (0 nodos distintos) |
| STOP/HOLD | Issue #2 revisado a las 18:39:46Z, justo antes del `PUT`: sin comentarios posteriores a `6924366566` |
| E2E escritorio y móvil | PASS (§4) |

## 3. Copia aislada: 55 consultas, 31 etiquetadas, UAT y casos de control

**Método:**
- **Corridas:** en la misma tarde, en serie y nunca dos a la vez, se corrió V0 (línea base: el flujo vivo, copiado) y luego V1b.
- **Entradas** ([`entradas.json`](../docs/cira/task0032/resultados/entradas.json)): las 55 del corpus de TASK-0028; las 4 de la UAT, 3 veces cada una; y 5 de control (clima, saludo, dos conceptos, ficha por nombre y ficha por RIF).
- **Calidad:** se mide con las etiquetas del cliente, sin reetiquetar.
- **Scripts:** los de TASK-0031 (`run_variant`, `collect`, `eval_variants`).

V1b pasó por tres revisiones antes de quedar fija:
- **r1:** reglas integradas tal como se midieron en TASK-0031. Perdió la variante con tilde en «Tuberia octg» (1 → 0 filas).
- **r2:** se corrige la regla de tildes. En la prueba dirigida, «Tuberia octg» funcionó 5 de 5 veces, pero «IPC» pidió aclaración 3 de 5 veces, cuando antes siempre buscaba. Era una regresión real de V1b.
- **r3 (desplegada):** un término suelto o una sigla nunca es ambiguo. En la prueba dirigida: «IPC» buscó 5/5 (24 filas), «Tuberia octg» 5/5, la frase ambigua de ejemplo del prompt pidió aclaración 5/5 (se conserva) y «OCTG» buscó 5/5.

| Métrica | V0 (vivo copiado) | **V1b r3** |
|---|---|---|
| Latencia corpus p50 / p95 (n8n, ms) | 10 396 / 14 227 | **6 107 / 8 089** |
| Latencia UAT p50 / p95 (12 búsquedas) | 11 846 / 15 627 | **6 371 / 8 379** |
| Nodo agente p50 | 7 253 | **3 393** |
| Llamadas al modelo del agente | 2 | **1** |
| Llamadas a `resolve_search_intent` (`gpt-5.6-luna` en el Worker) | 1 por búsqueda | **0** |
| Tokens del agente por ejecución, media (entrada / salida) | 9 942 / 139 | **4 950 / 131** |
| No relevantes en el top 5, grupo 1 (ruido) | 27 | **27** |
| Relevantes en el top 5, grupo 1 | 22 | **23** |
| Precisión sobre lo juzgado | 44,9 % | **46,0 %** |
| Cobertura (relevantes en el top 10 de cada grupo) | 38/49 | **39/49** |
| Consultas vacías (de 55) | 2 | **1** («venta de zapatos») |
| Errores | 0 | 0 |

UAT por consulta (ms de n8n, 3 repeticiones):

| Consulta | V0 | V1b r3 |
|---|---|---|
| empresas de perforacion | 15 627 / 10 561 / 15 576 | 7 153 / 6 487 / 8 379 |
| balancin | 11 379 / 10 228 / 11 846 | 6 575 / 3 920 / 5 032 |
| macolla | 11 712 / 9 518 / 12 770 | 6 709 / 4 013 / 4 386 |
| gandola | 15 003 / 10 451 / 11 912 | 5 897 / 6 371 / 5 080 |

**Costo:**
- **Medido:** los tokens del agente.
- **Estimado:** el costo por búsqueda, con precios de agregadores para `gpt-6-luna` ($0,10 de entrada y $0,50 de salida por millón; el mismo supuesto de TASK-0031): unos **$0,00106 en V0 y $0,00056 en V1b**.
- **Ahorro adicional:** V1b elimina la llamada a `gpt-5.6-luna` dentro del Worker. Sus tokens no son visibles desde n8n y no se midieron aquí.

**Casos pedidos** (fuente: [`comparacion_variantes.json`](../docs/cira/task0032/resultados/comparacion_variantes.json)):

| Caso | Resultado de V1b r3 |
|---|---|
| perforación (U01 y L064/L069) | Mismos resultados (29 filas en la UAT; mechas 4; fluidos 12, con la corrección «fuidos» → «fluidos») |
| gandola (L024/L027/L028/L036/L053) | Mismos top 5 |
| balancín / balacines | Mismos resultados. «balacines» lleva la corrección en `resolvedPhrases`. **L022 «balancín»** falló en V0 (sin búsqueda) y en V1b encuentra la relevante 157 |
| macolla | 13 filas, 0 capacidades. Se ofrece la vista de experiencias sin cambiar de vista automáticamente |
| manejo de aguas residuales | Mismas 9 filas |
| fabricación (L010, L026, S005) | Mismos resultados. L034 «fabriquen y mantengan balancines»: la relevante 157 se mantiene en el top 5 (puesto 2 en vez de 1) y salen del grupo de experiencias dos no relevantes etiquetadas (333, 391) |
| venta de zapatos | Sin resultados en ambas. V1b ya no llama al Worker y responde con el mensaje de siempre |
| Capacidades / experiencias | Grupos y orden iguales en 50 de 55 top 5. Las diferencias son L007, L022, L025, L033 y L034, todas explicadas aquí |
| Negativas y control | El clima se declina y el saludo recibe respuesta, sin búsqueda en ambos casos. Los dos conceptos dan una frase por concepto. La ficha por nombre (TASPSA) y la ficha por RIF dan 1 fila cada una |

**Variación conocida, no atribuible a V1b:** L033 «…en el lago» a veces sale con `ciudad: "lago"` y 0 filas. Ocurrió también en la línea base V0 de TASK-0031 (`ejecuciones_V0r2`) y en 2 de 5 repeticiones dirigidas. Es un comportamiento previo del modelo y queda fuera del alcance de V1b.

## 4. Despliegue y E2E en staging

- **`PUT`:** se envió a las 18:40:06Z, con `versionId` = `activeVersionId` = `c6c70402` y el flujo activo. La versión publicada coincide con el cuerpo enviado.
- **E2E:** [`e2e_cira.mjs`](../docs/cira/task0032/e2e/e2e_cira.mjs) con Playwright en `https://pruebas.camarapetrolera.app/cira-test/`, en escritorio (1280×900) y Pixel 5. Resultados en [`e2e_faseA.json`](../docs/cira/task0032/resultados/e2e_faseA.json); capturas en [`docs/cira/task0032/capturas/`](../docs/cira/task0032/capturas/) (`faseA_<dispositivo>_<n>[_perfil].png`, con n: 0 perforación · 1 balancín · 2 macolla · 3 gandola · 4 balacines · 5 venta de zapatos).

| Consulta | Escritorio, navegador (ms) | Pixel 5, navegador (ms) | Vistas (capacidades / experiencias) |
|---|---|---|---|
| empresas de perforacion | 24 663 (primera ejecución tras publicar; 12 386 en n8n) | 5 980 | 22 / 13 |
| balancin | 8 767 | 7 442 | 1 / 4 |
| macolla | 6 275 | 4 696 | 0 / 13 (botón; sin cambio automático) |
| gandola | 6 627 | 4 691 | 6 / 1 |
| balacines | 6 266 | 7 133 | 1 / 4 |
| venta de zapatos | 5 861 | 7 308 | «No encontramos resultados» |

Como referencia, el E2E de TASK-0030 midió en escritorio 13 400 / 11 244 / 10 343 / 11 429 ms para perforación, balancín, macolla y gandola.

- **Vistas:** excluyentes en todas las consultas; `aria-pressed` correcto al abrir, al cambiar y al volver.
- **Página:** sin desplazamiento horizontal y sin errores de JS.
- **Votos:** 👍 dio «✓ gracias». En el flujo de votos quedaron el voto 37 (log 111) y el 38 (log 119), ambos `ok:true` y `rank_position:1`.
- **Clic en la empresa:** abre la ficha en los 4 casos probados (INDUSTRIAS METAL MECANICA MERIDA y OEG INTERNACIONAL SERVICE), en 3,7 a 5,1 s.
- **«Evaluar esta búsqueda»:** lleva `log` y `sid`.

**Registros (BD, transacción `READ ONLY`):**
- `cira_search_log` 111–126: 16 filas, todas con `engine_version` `flow zbVLoCdR09IA9yQK TASK-0013-step1-v2+explain+votos+T32-v1b | T30-B r2 | worker 8796f292` y sin error.
- `response_ms` de n8n: 10 300 en la primera búsqueda y entre 3 053 y 7 278 en el resto.
- Evaluaciones: 0 nuevas (`cira_evaluations` = 0).

**Datos que dejó el E2E:** las 16 filas de `cira_search_log` (111–126), los votos 37 y 38 y la memoria de chat de sesiones `test-…`. Es el mismo tipo de datos que dejaron TASK-0029 y TASK-0030.

## 5. Rollback

`PUT /api/v1/workflows/zbVLoCdR09IA9yQK` con el cuerpo `%USERPROFILE%\.cpv\n8n_backups\zbVLoCdR09IA9yQK_2026-11-16_pre-t32_ROLLBACK_body.json`, armado desde el respaldo `…_pre-t32_live.json` (`7feb48bc`). En esta instancia, el `PUT` publica directamente. El procedimiento está ensayado (§2). El Worker no cambió, así que no requiere rollback.

## 6. Límites y pendientes

- **Latencia:** los tiempos de staging los fijan el modelo y el Worker, y varían con la carga. La mejora medida no es una garantía.
- **Alcance pendiente:** UAT manual del propietario. La fase B (ficha de la empresa 177) se trata aparte. La fase C (W1) no está autorizada.
- **Estado de tareas anteriores:** TASK-0030 conserva su aceptación **parcial** de UX y TASK-0029 sigue sin aprobación para producción.
