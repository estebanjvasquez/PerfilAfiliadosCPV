# TASK-0030 — CIRA: correcciones de la UAT de TASK-0029 (vistas excluyentes y diagnóstico de latencia)

| Campo | Valor |
|---|---|
| Fuente | Issue #2 `6919847012` (CORRECTIONS_REQUIRED de la UAT de TASK-0029, 2026-11-16T14:52Z) |
| Antecedente | TASK-0029 (entrega `6915259431`, HANDOFF `ac93eb6`), sin reescribir. **No aprobada para producción** |
| Estado | **DEPLOYED_FOR_OWNER_UAT** (solo staging) |
| Cambio desplegado | Flujo n8n `zbVLoCdR09IA9yQK`: `d2f8efb5` → **`7feb48bc-218d-4c4e-af19-8a471b7512bc`** (2026-11-16 **15:01:54Z**); solo `Format Cards2` y la revisión en `Build Search Log` |
| Sin cambios | Worker `8796f292` (no se redesplegó); Laravel `5e6e3fc`; BD, modelo `gpt-6-luna`, prompt, ranking, taxonomía, kiosco, `main`, producción |
| Evidencia | [`docs/cira/task0030/`](../docs/cira/task0030/) |

## 1. Vistas excluyentes

| Pedido | Implementación |
|---|---|
| Opciones «Solo capacidades» (por defecto) / «Empresas con experiencias relacionadas» | Dos botones con su conteo y `aria-pressed`, en un grupo con `aria-label` |
| En «Solo capacidades», solo tarjetas de capacidades, sin el desplegable inferior | Cada vista es un panel. El otro queda oculto (`hidden`) y desaparecieron el `<details>` del grupo 2 y el de experiencias por tarjeta. La existencia de experiencias se indica con una línea discreta: «También tiene N experiencia(s) relacionada(s): ver en «Empresas con experiencias relacionadas»» |
| En la vista de experiencias, solo tarjetas de experiencias, sin duplicados | Vista de experiencias en dos partes, sin repetir empresas: primero el grupo de experiencias del Worker, en su orden y con su puntaje; después, separadas, las empresas del grupo de capacidades que **también** tienen experiencias que coinciden |
| Empresas con ambas evidencias (decisión documentada) | Esas empresas se muestran en la vista de experiencias **solo con sus experiencias** (fragmentos, aviso «La experiencia no acredita por sí sola fabricación o comercialización» y «Esta empresa también aparece en «Solo capacidades» por su catálogo»). No se muestra su capacidad, su puntaje de capacidad ni ningún rol |
| Sin capacidades | Mensaje «Ninguna empresa declara … en su catálogo» y botón «Ver empresas con experiencias relacionadas (N)». **No** cambia de vista solo |
| Sin experiencias | En esa vista: «No hay empresas con experiencias relacionadas con …» |
| Se conservan | Origen `self_declared` / `validated` / `suggested` / dato del perfil, rol no acreditado, explicación y cálculo del puntaje, clic y Enter para el perfil, votos por tarjeta y por búsqueda (`search_log_id`), enlace «Evaluar esta búsqueda», registro con `grupo` y `cap` |

**Código:** [`format_cards2_vistas.js`](../docs/cira/task0030/n8n/format_cards2_vistas.js) reemplaza el bloque de TASK-0029 entre sus marcadores. [`build_flow.mjs`](../docs/cira/task0030/n8n/build_flow.mjs) arma el `PUT` desde el export vivo. La revisión del flujo pasa a `T30-B r2`, así que los registros nuevos se distinguen de los de TASK-0029.

## 2. Latencia: diagnóstico medido

### 2.1 Método

| Medición | Fuente | Script o archivo |
|---|---|---|
| Por nodo | Tiempos de cada ejecución en la API de n8n (lectura) | [`n8n_timings.mjs`](../docs/cira/task0030/latencia/n8n_timings.mjs) |
| Por período | Resumen | [`resumen.json`](../docs/cira/task0030/latencia/resumen.json) |
| Worker directo, en frío y en caliente | `search_empresas` con y sin `grupos`, llamadas de lectura | [`worker_latency.mjs`](../docs/cira/task0030/latencia/worker_latency.mjs) |
| Desglose interno del Worker | `/debug-search` | — |
| Navegador | Playwright en staging, del clic en enviar a las tarjetas | [`e2e_navegador.json`](../docs/cira/task0030/latencia/e2e_navegador.json) |

### 2.2 Reproducción de «empresas de perforacion»

**Tus dos ejecuciones** (sesión de UAT, flujo `d2f8efb5`, 29 resultados: 22 de capacidades y 7 de experiencias):

| Ejecución | Hora | Total | Agente (gpt-6-luna + herramienta MCP) | Worker `search_empresas` | Registro | Tarjetas |
|---|---|---|---|---|---|---|
| 22206 | 13:25:20Z | 19,2 s | 12,7 s | 5,1 s | 0,67 s | 0,07 s |
| 22207 | 13:26:40Z | 11,4 s | 9,0 s | 1,4 s | 0,57 s | 0,05 s |

**Reproducción en staging con sesión nueva**, 2026-11-16 15:02–15:04Z, flujo `7feb48bc`:

| Ejecución | Total | Agente | Worker | Navegador (clic → tarjetas) |
|---|---|---|---|---|
| 22213 | 12,3 s | 6,9 s | 4,3 s | 13,4 s (escritorio) |
| 22221 | 12,0 s | 7,2 s | 3,7 s | 13,1 s (Pixel 5) |

**Lectura de los datos:**
- **Navegador:** la diferencia entre lo que ve el navegador y la ejecución en n8n (unos 1,1 s) es red y webhook.
- **Primer contenido:** el flujo responde una sola vez, al final, así que el tiempo hasta el primer contenido es el mismo que hasta las tarjetas completas.

### 2.3 Medianas por período (solo búsquedas `search_empresas`)

| Período | Ejecuciones | Total | Agente | de ello, modelo | de ello, herramienta MCP | Worker | Registro | Tarjetas | *Learnings* + memoria |
|---|---|---|---|---|---|---|---|---|---|
| Antes de TASK-0029 (9–15 nov) | 42 | 13,6 s | 8,6 s | 5,5 s | 2,0 s | 3,1 s | 0,56 s | 0,05 s | 0,51 s |
| TASK-0029 | 11 | 12,1 s | 7,9 s | 5,0 s | 2,0 s | 3,1 s | 0,57 s | 0,04 s | 0,46 s |
| TASK-0030 | 10 | 12,0 s | 7,7 s | 4,9 s | 2,1 s | 2,6 s | 0,54 s | 0,04 s | 0,29 s |

### 2.4 Worker directo (mediana de 5, intercalado; primera llamada en frío)

| Consulta | Por defecto: frío / mediana | `grupos`: frío / mediana |
|---|---|---|
| empresas de perforacion | 4,5 s / 0,40 s | 3,8 s / 0,21 s |
| balancin | 2,0 s / 0,42 s | 2,3 s / 0,25 s |
| macolla | 1,6 s / 0,20 s | 1,9 s / 0,16 s |
| gandola | 1,7 s / 0,20 s | 1,9 s / 0,37 s |

**Lectura de los datos:**
- **Repeticiones:** bajan a unos 0,2 s por la caché de lecturas de Hyperdrive; las consultas reales casi siempre llegan en frío.
- **En frío:** una frase tarda unos 1,2–1,4 s (por `/debug-search`). La lista más lenta es `structured`, con unos 750 ms.

### 2.5 Conclusión

- **El cuello de botella es el agente de n8n:** el modelo `gpt-6-luna` más su llamada MCP a `resolve_search_intent` es el **64–65 % del tiempo** en los tres períodos.
- **Tramos que siguen:**
  - el Worker, con 2,6–3,1 s de mediana;
  - el registro, con unos 0,55 s;
  - las tarjetas, con menos de 0,1 s.
- **TASK-0029 no agregó latencia medible:** la mediana total pasó de 13,6 s a 12,1 s, y el Worker se mantuvo en 3,1 s.
- **Los 19 s de tu primera consulta:** coinciden con un agente lento (12,7 s) y un Worker en frío (5,1 s), en la misma consulta. Repetida al minuto, bajó a 11,4 s.

### 2.6 Optimizaciones: qué se hizo y qué no

**Ninguna optimización se desplegó.** Las opciones son estas:

| Opción | Ganancia esperada (medida) | Por qué no se aplicó |
|---|---|---|
| Cambiar el modelo, reducir el prompt o no llamar a `resolve_search_intent` | Hasta unos 8 s (el 64 % del total) | Está fuera de alcance: modelo y prompt. Requiere una decisión y un gate aparte |
| Ejecutar en paralelo las consultas de `structuredList` en el Worker | Unos 0,3 s en frío (≈2–3 %) | Toca el camino por defecto del ranking a cambio de poca ganancia. Queda como opción |
| Registrar la búsqueda después de responder | Unos 0,55 s | Los votos necesitan el `search_log_id` en las tarjetas. Habría que reestructurar el flujo (otro nodo de respuesta) |
| Presentación (`Format Cards2`) | — | Ya tarda menos de 0,1 s |

No se fija un umbral de latencia: se reporta lo medido.

## 3. Regresión

| Prueba | Resultado |
|---|---|
| Vista previa con respuestas reales del Worker para 6 consultas (perforación, balancín, macolla, gandola, mantenimiento de balancines, fabricantes de balancines); Playwright en escritorio y Pixel 5 | **PASS** (detalle abajo) |
| Copia aislada de n8n `0XoZhWg5oG9hwm3u` (creada desactivada, webhook propio, sin registro) | «macolla», «empresas de perforacion» y «sin resultado» PASS |
| **Rollback ensayado** en esa copia | `PUT` del flujo de T29 → vuelve el formato anterior, con desplegable (macolla). `PUT` de T30 → vuelven las vistas (balancín). Después se desactivó y se borró |
| 55 consultas (Worker sin cambios) | B **idéntico a TASK-0029** en las 55 (ids y grupos). Modo por defecto 55/55. Métricas iguales: ruido en el top 5 27 (actual 38), cobertura 39/49, 2 vacías. Un fallo de red transitorio del cliente (L027) se repitió con éxito |
| E2E en staging, escritorio y Pixel 5 | **PASS** (detalle abajo) |
| BD (transacción `READ ONLY`) | Logs 88–98 con `… \| T30-B r2 \| worker 8796f292`, `grupo` y `cap`. Voto 36 (log 88, posición 1). La instantánea de TASK-0027 del log 88 es compatible (29 resultados). 0 evaluaciones nuevas. Tus búsquedas de UAT (85–87) quedan intactas |

**Vista previa (las 6 consultas):**
- **Al abrir:** solo capacidades, con el otro panel oculto.
- **Con el botón de experiencias:** solo experiencias, sin duplicados.
- **Al volver:** igual que al abrir.
- **`aria-pressed`:** correcto en cada cambio.
- **Teclado:** el foco y Enter cambian la vista.
- **Desplegables del grupo 2:** ninguno.
- **«macolla»:** abre con 0 tarjetas y el botón; solo cambia a experiencias al pulsarlo.
- **Navegador:** sin desplazamiento horizontal y sin errores de JS.

**E2E en staging (6 consultas):**
- **Vistas:** excluyentes en todas, sin desplegable inferior.
- **«macolla»:** abre en capacidades con el mensaje.
- **Consulta sin resultados:** mensaje de siempre.
- **Interacciones:** 👍 «✓ gracias»; el clic en la empresa abre su ficha; «Evaluar esta búsqueda» lleva `log` y `sid`.
- **Navegador:** sin desplazamiento horizontal ni errores.

**Capturas:** [`docs/cira/task0030/capturas/`](../docs/cira/task0030/capturas/), con el patrón `e2e30_<dispositivo>_<consulta>_<vista>.png`:
- consultas: 0 = perforación, 1 = balancín, 2 = macolla;
- vistas: `cap` o `exp`.

## 4. Gates

| Gate | Resultado |
|---|---|
| Aislamiento | El componente que cambió es el flujo `zbVLoCdR09IA9yQK`. Su webhook solo lo usa `public/cira-test/index.html` de la rama de staging (no está en `main` ni en otros repos). El kiosco usa otro flujo |
| Respaldo | Export vivo `d2f8efb5` fuera del repo (sha256 `9C927FFA…`), verificado idéntico justo antes del `PUT` |
| Diff mínimo | Versión publicada frente al respaldo: cambian `Format Cards2` y `Build Search Log` (solo la revisión); conexiones iguales |
| Rollback ensayado | En la copia aislada (§3) |
| Worker | No se tocó: el cambio pedido es de presentación |

## 5. Rollback

**n8n:** `PUT /api/v1/workflows/zbVLoCdR09IA9yQK` con `%USERPROFILE%\.cpv\n8n_backups\zbVLoCdR09IA9yQK_2026-11-16_pre-t30_live.json`, que es la versión `d2f8efb5` de TASK-0029. En esta instancia el `PUT` publica. Para volver a antes de TASK-0029 están el respaldo `…_pre-t29_live.json` y el rollback del Worker de TASK-0029.

## 6. Limitaciones

- **Latencia:** la principal (el agente) no se corrigió porque requiere cambiar el modelo, el prompt o el uso de herramientas, y eso necesita autorización aparte (§2.6).
- **Empresas con ambas evidencias:** en la vista de experiencias van al final y sin puntaje propio, porque el Worker no calcula un puntaje de experiencias para empresas del grupo de capacidades. Darles uno exigiría un cambio en el Worker.
- **Datos que dejó el E2E:**
  - 11 filas de `cira_search_log` (88–98);
  - el voto 36, de sesiones `test-…`.
