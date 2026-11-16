# TASK-0031 — CIRA: diagnóstico y benchmark aislado de latencia (sin deploy)

| Campo | Valor |
|---|---|
| Fuente | Issue #2 `6920929114` (2026-11-16T15:34Z) |
| Antecedentes | TASK-0030 (`6920381900`): aceptación **parcial** de UX, no APPROVED global. TASK-0029 (`6915259431`) |
| Estado | **READY_FOR_REVIEW — SIN DEPLOY** |
| Alcance real | Lectura, copias aisladas de n8n (creadas y borradas), versiones de vista previa del Worker sin tráfico. **No** se tocaron el flujo activo, el Worker vivo, el modelo o el prompt en vivo, staging, producción, la BD (salvo lecturas), credenciales, kiosco, ni logs, votos o evaluaciones reales |
| Evidencia | [`docs/cira/task0031/`](../docs/cira/task0031/) |

## 1. Línea base congelada (2026-11-16 ~15:40Z)

| Componente | Valor vivo verificado |
|---|---|
| Flujo n8n `zbVLoCdR09IA9yQK` | `7feb48bc-218d-4c4e-af19-8a471b7512bc`, idéntico al export posterior a TASK-0030 |
| Worker | `8796f292` al 100 % (código `1ea9175`) |
| Agente | `OpenAI Chat Model` = **`gpt-6-luna`**. Prompt `TASK-0013-step1-v2+explain+votos` (17 619 caracteres + *learnings*). Herramientas MCP del agente: `resolve_search_intent` y `search_taxonomy`; memoria Postgres |
| `resolve_search_intent` | Corre en el Worker y hace **su propia llamada a OpenAI** (`gpt-5.6-luna`, prompt de unos 6 250 caracteres, *timeout* de 8 s) |

**Cómo resuelve una búsqueda el agente actual:**
1. el modelo recibe el mensaje;
2. el modelo pide `resolve_search_intent`;
3. el Worker llama a otro modelo;
4. el modelo hace una segunda llamada y arma el JSON;
5. el Worker ejecuta `search_empresas`;
6. se escribe el registro y se arman las tarjetas.

Son **tres llamadas a modelos en serie** por búsqueda.

## 2. Método y gates del experimento

- **Copias aisladas de n8n:**
  - **Configuración de cada copia:** se arma con [`build_variant.mjs`](../docs/cira/task0031/bench/build_variant.mjs) a partir del export vivo, con webhook propio, y tiene desactivados `Log Search` y `Call 'CIRA_log'`. **No escribe en `cira_search_log`, votos ni evaluaciones.**
  - **Ciclo de cada variante:** se crea, se activa, se usa y se borra ([`bench_variant.ps1`](../docs/cira/task0031/bench/bench_variant.ps1)). Las 10 copias (y el flujo temporal que listó los modelos) se borraron.
  - **Qué comparten:** usan la credencial de OpenAI y el Worker vivo **sin modificarlos**, y escriben memoria de chat en la tabla interna de n8n con sesiones `t31-…`.
  - **Comprobación previa:** antes de cada variante se verificó que el flujo vivo siguiera en `7feb48bc`.
- **Entradas:**
  - **Corpus:** las **55 consultas** de TASK-0028 con su **texto real de usuario**, leído de `cira_search_log` en una transacción `READ ONLY`. Para las 6 sintéticas se usa la consulta tal cual. Están en [`entradas.json`](../docs/cira/task0031/resultados/entradas.json).
  - **UAT:** las 4 consultas («empresas de perforacion», «balancin», «macolla», «gandola») × 3 repeticiones.
  - **Sesiones:** una nueva por consulta, en serie y sin paralelismo.
- **Medición:**
  - **Por ejecución:** API de n8n ([`collect.mjs`](../docs/cira/task0031/bench/collect.mjs)), con ms por nodo, llamadas al modelo, tokens, parámetros del agente y resultados (id y grupo).
  - **Tiempo del cliente:** del POST al webhook hasta la respuesta, es decir el tiempo hasta las tarjetas menos el render, que en TASK-0030 se midió por debajo de 0,1 s.
- **Calidad:** etiquetas del cliente de TASK-0028, sin reetiquetar ([`eval_variants.mjs`](../docs/cira/task0031/bench/eval_variants.mjs)).
- **Variación de la propia línea base:** V0 se corrió **dos veces** (V0r1 y V0r2).
- **Latencia limpia de la UAT:** como V1b coincidió en parte con la prueba del Worker, se repitieron V0 y V1b con solo las 4 consultas de la UAT × 4, sin otra carga.
- **Worker:** cambio W1 en la rama `perf/t31-worker-parallel` (`988f87f`), subido como **vista previa sin tráfico** `3539deec` y comparado con la vista previa de la versión desplegada `8796f292` ([`worker_w1.mjs`](../docs/cira/task0031/bench/worker_w1.mjs)).

**Total de búsquedas de agente ejecutadas:** 9 corridas que suman 501 ejecuciones (7 × 67 + 2 × 16).

## 3. Variantes

| Id | Qué cambia (solo en la copia) | Qué toca si se adopta |
|---|---|---|
| V0 | Nada (línea base) | — |
| V1 | El agente ya no tiene `resolve_search_intent` (queda `search_taxonomy`). Un ajuste al final del prompt le pide armar el JSON en una sola llamada | n8n: prompt y herramientas del agente |
| **V1b** | V1 + dos reglas: corregir errores de tipeo evidentes en `resolvedPhrases`; ante la duda de alcance, buscar | n8n: prompt y herramientas del agente |
| M41 | Modelo `gpt-4.1-mini`; prompt y herramientas iguales | n8n: modelo |
| M54 | Modelo `gpt-5.4-mini`; prompt y herramientas iguales | n8n: modelo |
| V1M41 | V1 + `gpt-4.1-mini` | n8n: modelo, prompt y herramientas |
| W1 | Worker: embeddings en paralelo con las consultas que no los necesitan, `structuredList` en paralelo y clasificación del texto completo anticipada. **Sin cambio de resultados** | Worker |

**No probadas, con su causa:**
- **`resolve_search_intent` por el flujo**, en paralelo con el agente: exige otra estructura del flujo (ramas y *merge*). Con los datos de §5.2, su aporte no lo justifica.
- **Responder antes de registrar:** los votos necesitan el `search_log_id` en las tarjetas.
- **Optimizar el prompt grande:** reescribir el prompt completo cambiaría la semántica. V1b prueba el cambio mínimo.

## 4. Resultados

### 4.1 Latencia (ms) y costo

**Corpus de 55 consultas, por corrida:**

| Variante | Total p50 | Total p95 | Agente p50 | Modelo p50 | Worker p50 | Llamadas al modelo | Tokens entrada / salida | Costo del agente por búsqueda* |
|---|---|---|---|---|---|---|---|---|
| V0r1 | 10 175 | 14 029 | 7 293 | 4 668 | 2 607 | 2 (+1 en el Worker) | 10 155 / 143 | ≈ $0,0011 + la llamada de `resolve_search_intent` |
| V0r2 | 10 321 | 18 097 | 7 332 | 4 443 | 2 595 | 2 (+1) | 10 162 / 148 | ídem |
| V1 | 7 146 | 10 671 | 4 043 | 3 363 | 2 560 | 1 | 5 075 / 158 | ≈ $0,0006 |
| **V1b** | **6 991** | 12 540 | **4 240** | 3 437 | 2 341 | **1** | 5 263 / 160 | **≈ $0,0006** |
| M41 | 9 319 | 13 118 | 6 112 | 3 781 | 2 645 | 1,9 (+1) | 9 543 / 87 | ≈ $0,0040 + `resolve_search_intent` |
| M54 | 9 064 | 10 533 | 6 155 | 3 629 | 2 676 | 2 (+1) | 10 152 / 91 | ≈ $0,0080 + `resolve_search_intent` |
| V1M41 | 5 489 | 10 483 | 2 628 | 2 054 | 2 536 | 1 | 4 989 / 55 | ≈ $0,0021 |

**UAT limpia, 4 consultas × 4, sin otra carga** ([`uat_limpia_v0_v1b.json`](../docs/cira/task0031/resultados/uat_limpia_v0_v1b.json)):

| Variante | p50 | p95 | Agente p50 | Worker p50 |
|---|---|---|---|---|
| V0 | 10 217 | 13 665 | 7 026 | 2 854 |
| **V1b** | **6 246** | 11 293 | **3 295** | 2 240 |

**Por consulta, en ms (4 repeticiones, en orden):**

| Consulta | V0 | V1b |
|---|---|---|
| empresas de perforacion | 12 237 · 10 119 · 10 531 · 10 676 | 6 988 · 11 293 · 5 089 · 8 799 |
| balancin | 13 665 · 7 571 · 11 961 · 8 478 | 6 599 · 4 405 · 7 629 · 7 724 |
| macolla | 9 685 · 7 856 · 10 217 · 9 969 | 6 675 · 5 588 · 4 177 · 5 185 |
| gandola | 10 620 · 11 476 · 9 831 · 8 314 | 6 246 · 5 133 · 4 818 · 5 231 |

\* **Precios por millón de tokens (entrada / salida):**

| Modelo | Precio | Fuente |
|---|---|---|
| `gpt-6-luna` | $0,10 / $0,50 | agregadores ([BenchLM](https://benchlm.ai/openai/api-pricing), [Costgoat](https://costgoat.com/pricing/openai-api)); **no hay página oficial de OpenAI** |
| `gpt-4.1-mini` | $0,40 / $1,60 | agregadores; uno lo marca como obsoleto |
| `gpt-5.4-mini` | $0,75 / $4,50 | [anuncio de OpenAI](https://openai.com/index/introducing-gpt-5-4-mini-and-nano/) |

- **Tokens:** los cuenta n8n y solo incluyen el agente.
- **Llamada de `resolve_search_intent` (`gpt-5.6-luna` en el Worker):** no se registra. Su precio no se encontró; con un prompt de unos 1 600–1 800 tokens, es un costo adicional que V1 y V1b **eliminan**.
- **Cifras de costo:** son estimaciones a partir de tokens medidos, **no facturas**.

### 4.2 Calidad (31 consultas etiquetadas, top 5 del grupo de capacidades)

| Variante | No relevantes | Relevantes | Precisión sobre lo juzgado | Cobertura top 10 (ambos grupos) | Vacías (de 55) | Sin búsqueda | Mismo top 5 por grupo que V0r1 |
|---|---|---|---|---|---|---|---|
| V0r1 | 27 | 23 | 46,0 % | 39/49 | 1 | 0 | 55/55 |
| V0r2 | 27 | 22 | 44,9 % | 38/49 | 2 | 0 | 52/55 |
| V1 | 27 | 23 | 46,0 % | 39/49 | 2 | 1 (L070) | 50/55 |
| **V1b** | **27** | **23** | **46,0 %** | **39/49** | **1** | 1 (L042, negativa) | **52/55** |
| M41 | 27 | 22 | 44,9 % | 38/49 | 2 | 0 | 50/55 |
| M54 | 27 | 21 | 43,8 % | 37/49 | 3 | 1 | 51/55 |
| V1M41 | 26 | 22 | 45,8 % | 38/49 | 2 | 0 | 49/55 |

**Variación de la propia línea base:** entre sus dos corridas, V0 difiere en 3 de 55 tops y en ±1 relevante. Diferencias de ese tamaño son **ruido del agente**, no regresiones.

### 4.3 Diferencias por consulta (corpus, frente a V0r1)

**V1:**
- **L073 «balacines», regresión:** no agrega «balancines», y Lukiven pasa solo a experiencias.
- **L070 «manejo de aguas residuales», regresión:** no busca; la trata como conversación.
- **Otros cambios, sin efecto en el top relevante:** L033 pierde dos sin juicio; L069 corrige «fuidos»; L007 cambia el orden del grupo 2.

**V1b corrige las dos regresiones:**
- **«balacines»:** `resolvedPhrases` = «balancines», «balancin», con el mismo resultado que V0.
- **«manejo de aguas residuales»:** busca, con el mismo resultado.
- **Casos fijados, iguales a V0:** «balancín» (157 en capacidades; 91, 333 y 391 en experiencias), «macolla» (solo experiencias, 13), «gandola» (6 + 1), «fabricantes de balancines» (157) y «empresas que fabriquen y mantengan balancines» (separa los dos conceptos, igual que V0).
- **L042 «venta de zapatos»** (negativa del cliente, G7-03, sin relevantes): V0 busca y no encuentra nada; V1b la **declina** como fuera de alcance. Las dos respuestas son aceptables para una negativa.
- **Etiquetas `self_declared` / `validated` / `suggested`, separación de capacidades y experiencias, y roles:** no cambian, porque las produce el Worker, que es el mismo.

**Cambios de modelo:**
- **M41 / V1M41:** pierden «tratamiento de aguas de perforación en el lago» (L033: vacía o sin la relevante 115) y cambian el orden en válvulas.
- **V1M41 en «empresas que fabriquen y mantengan balancines»:** no separa los conceptos, y el grupo 1 se reduce a Lukiven.
- **M54:** baja 2 relevantes y tiene 3 vacías.

### 4.4 Worker W1 (vista previa `3539deec` frente a `8796f292`; 55 consultas)

| Medición | Resultado |
|---|---|
| Equivalencia, modo por defecto | **55/55** idénticas (ids, orden y puntaje) |
| Equivalencia, `grupos` | 54/55 en la corrida. L072 difirió en la primera llamada, pero **3 repeticiones dieron resultados idénticos** en ambas versiones (transitorio en frío) |
| Latencia en frío con `grupos` (110 llamadas por versión; mayúsculas variadas para evitar la caché de Hyperdrive, alternando qué versión recibe cada escritura) | p50 **1 960 → 1 582** ms (−0,38 s); p95 **3 136 → 2 378** ms (−0,76 s) |
| Fallos transitorios | 3 (2 respuestas cortadas, 1 *fetch failed*), reintentados |

## 5. Análisis

### 5.1 Dónde está el tiempo

- **El agente es el 70 % del p50.**
  - **De qué se compone:** dos llamadas a `gpt-6-luna` más `resolve_search_intent`, que a su vez llama a `gpt-5.6-luna`.
  - **Variación:** el p95 sube por las colas de esas llamadas; V0r2 tuvo un caso de 29,8 s.
- **El Worker es el resto, con unos 2,3–2,9 s:** consultas a la BD en frío y embeddings.
- **Registro y tarjetas:** menos de 0,7 s en total.

### 5.2 Cuánto aporta `resolve_search_intent`

**En V0r1 aporta una frase distinta de la propia consulta (sin contar tildes) en 4 de 55 consultas (7 %):**
- **2 correcciones de tipeo:** L069 «fuidos» y L073 «balacines».
- **1 separación en dos conceptos:** L034.
- **1 caso en que mete la ubicación en la frase:** L033 «… en el lago».

V1b cubre los dos tipeos y la separación de conceptos con reglas del prompt.

### 5.3 Comparación

| Opción | Latencia (p50 corpus / UAT limpia) | Calidad | Costo | Complejidad | Reversibilidad |
|---|---|---|---|---|---|
| **V1b** | **−31 % / −39 %** (10,2 → 7,0 s / 10,2 → 6,2 s) | Igual a V0 (dentro de su variación) | **−45 %** de tokens del agente y sin la llamada de `resolve_search_intent` | Baja: 2 nodos de n8n (prompt y lista de herramientas) | `PUT` del respaldo |
| V1M41 | −46 % / — | Algo menor (−1 relevante, −1 cobertura, pierde la separación de conceptos) | Unas 2 veces V1b | Baja, pero cambia el modelo | `PUT` del respaldo |
| M41 / M54 | −9 % / −11 % | Menor (−1/−2 relevantes, más vacías) | 4–7 veces más | Baja (modelo) | `PUT` del respaldo |
| W1 (Worker) | −0,4 s p50 y −0,8 s p95 en frío, sumable a V1b | Idéntica | Igual | Media (Worker) | `wrangler versions deploy 8796f292…@100%` |

## 6. Recomendación técnica (requiere autorización; no aplicada)

1. **Adoptar V1b**, sin cambiar de modelo.
   - **Qué se cambia:** solo el flujo n8n de CIRA Test.
     - **`MCP Perfil Afiliados CPV`:** quitar `resolve_search_intent` de `includeTools`.
     - **`Build System Prompt`:** **reescribir** la sección «ENTENDER LA INTENCION ANTES DE BUSCAR» con las reglas de V1b, en lugar de añadir un ajuste al final como en la prueba, y subir `promptVersion`.
   - **Qué no cambia:** el modelo sigue en `gpt-6-luna`, y el Worker y el ranking quedan igual.
   - **Resultado medido:** p50 de unos 10 s a unos 6–7 s, con calidad igual y costo menor.
   - **Riesgos:** el juicio de alcance y de ambigüedad pasa a ser solo del agente; las respuestas de aclaración siguen el prompt. Se mitiga con la batería de 55 consultas y las negativas antes de promover.
2. **W1 en el Worker**, opcional y como segundo paso: unos 0,4 s más en p50 (0,8 s en p95), con resultados idénticos. Su vista previa ya está subida (`3539deec`), y el rollback es `8796f292`.
3. **No recomendado:** cambiar de modelo. La ganancia es menor que la de V1b, la calidad baja algo y el costo sube entre 2 y 7 veces.

**Plan de implementación, para autorizar después:**
1. **Preflight:** versión viva, respaldo del flujo y diff de 2 nodos.
2. **Copia aislada:** armar el prompt definitivo, correr las 55 consultas y la UAT, y comparar con V0 y V1b.
3. **Promoción:** `PUT` del flujo, solo en staging.
4. **Verificación posterior:** E2E en escritorio y móvil, latencia por nodo y BD en lectura.
5. **Rollback:** `PUT` del respaldo `7feb48bc`.
6. **W1, si se autoriza:** en un paso aparte, con regresión 32/32 y 55/55, y rollback `wrangler versions deploy 8796f292…@100%`.

## 7. Limitaciones

- **Tamaño de muestra:**
  - **Corpus:** 1 corrida por variante (2 para V0).
  - **UAT:** 3 repeticiones, más 4 en la corrida limpia de V0 y V1b.
  - **Consecuencia:** los p95 se basan en pocas observaciones.
- **Carga y caché compartidas:** el Worker y la API de OpenAI son compartidos, y la caché de Hyperdrive favorece repeticiones. La UAT de V1b en la corrida completa coincidió con W1; por eso se repitió limpia.
- **Tiempo del navegador:** se tomó el tiempo del cliente al webhook. El render es menor de 0,1 s (TASK-0030), y la red del navegador agrega alrededor de 1 s.
- **Precios:** los de `gpt-6-luna` y `gpt-4.1-mini` son de agregadores. El de `gpt-5.6-luna` no se encontró.
- **Copias aisladas:** escriben memoria de chat en la tabla interna de n8n, con sesiones de prueba `t31-…`, sin datos del cliente.
- **Evaluaciones de la Cámara:** no se probaron, porque ninguna variante toca los registros.

## 8. Cero cambios en lo vivo

| Componente | Estado |
|---|---|
| Flujo activo | Sigue en `7feb48bc`, verificado antes de cada variante y al final |
| Worker | Sigue en `8796f292` al 100 %. Las vistas previas `3539deec` no reciben tráfico |
| `cira_search_log`, votos y evaluaciones | Sin escrituras: las copias tenían el registro desactivado y las llamadas directas fueron `search_empresas`, que es de lectura |
| Copias de n8n | Las 10 copias y el flujo temporal de listado de modelos se borraron |
