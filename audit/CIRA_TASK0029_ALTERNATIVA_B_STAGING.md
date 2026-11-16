# TASK-0029 — CIRA: alternativa B (capacidades / experiencias) desplegada en staging para UAT del propietario

| Campo | Valor |
|---|---|
| Fuente | Issue #2 `6914028986` (autorización expresa del propietario, 2026-11-16T10:36Z) |
| Antecedente | Auditoría TASK-0028 ([`CIRA_TASK0028_FUENTES_RANKING_CAPACIDADES.md`](CIRA_TASK0028_FUENTES_RANKING_CAPACIDADES.md)) |
| Estado | **DEPLOYED_FOR_OWNER_UAT** (solo staging). La aceptación funcional queda reservada al propietario |
| Alcance real | Worker `perfilafiliados-mcp` y 3 nodos del flujo n8n de CIRA Test. **Sin** cambios en Laravel, BD (DDL, migraciones o escrituras masivas), `empresa_search_documents`, taxonomía, roles, datos de empresas, credenciales, kiosco ni `main` |
| Evidencia | [`docs/cira/task0029/`](../docs/cira/task0029/) |

## 1. Versiones y SHAs

| Elemento | Antes | Después |
|---|---|---|
| Worker (despliegue al 100 %) | `1005d61f` (código `77ce273`) | **`8796f292-5ee5-446b-98ce-7ae36099dde9`** (código `1ea9175`, rama `feat/cira-two-groups`), 2026-11-16 **11:17:40Z** |
| Flujo n8n `zbVLoCdR09IA9yQK` | `bafd597a` (2026-11-09) | **`d2f8efb5-f3ac-417c-ab3d-df2d4ee12465`**, publicado 2026-11-16 **11:18:47Z** |
| Laravel staging | `5e6e3fc` | `5e6e3fc` (sin cambios; `public/cira-test/index.html` no se tocó) |

| SHA (repo principal, `fix/sec-01-r4-01-role-editor-preserve`) | Valor |
|---|---|
| BASE_SHA | `d628d2f` |
| Materialización | `e683a49` |
| TESTED_HEAD (código del Worker) | `1ea9175` (`perfilafiliados-mcp`) |
| DEPLOY_SHA | Worker `1ea9175` → versión `8796f292`; n8n `d2f8efb5` |
| REVIEW_HEAD / HANDOFF_SHA | en el comentario de entrega y en `audit/orchestrator_handoff.json` |

Versiones de vista previa intermedias, sin tráfico: `3b7a6238` (r1, código `57db8a1`), reemplazada por `8796f292` (r2) tras la corrección de §4.3.

## 2. Qué cambió

### 2.1 Worker (`search_empresas` con `grupos: true`)

- **Modo opcional:** sin `grupos`, la respuesta es la misma de siempre. Solo el flujo de CIRA Test lo envía.
  - **Dos llamadas de lectura lo verifican:** la regresión congelada (32 de 32 consultas sin cambios) y las 55 consultas, que dan el mismo orden que la versión anterior.
- **Dos grupos:**
  - **`grupo: "capacidad"`:** evidencia de catálogo, es decir servicio, sector, categoría CPV, nombre, certificación o sostenibilidad, más el texto del perfil que coincide **sin** contar sus experiencias.
  - **`grupo: "experiencia"`:** coincidencia literal o por significado en experiencias, o texto del perfil que solo coincide por experiencias.
  - **Puntajes:** cada grupo se fusiona y se ordena con su propia evidencia, y la experiencia no suma puntaje en el grupo de capacidades.
  - **Empresas en ambos grupos:** aparecen una sola vez, en capacidades, con `experiencias_relacionadas`.
- **El `fulltext` mixto no cuenta como capacidad:**
  - **Cómo se clasifica:** para las empresas que encontró, una sola consulta quita del documento el texto de sus experiencias, igual que `esd_noexp` en TASK-0028, y repite la búsqueda de texto.
  - **Sin escrituras:** no se regeneró ni se escribió nada.
- **`capacidad` / `capacidades`:** cada una trae estos campos.
  - **`tipo`:** `categoria_cpv`, `servicio`, `sector`, `nombre`, `certificacion`, `sostenibilidad` o `texto_perfil`.
  - **`coincidencia`:** `directa`, `parecida` (por significado) o `relacionada` (familia CPV).
  - **`codigo` y `tipo_oferta`:** los de la categoría CPV.
  - **`origen`:** para categorías CPV, el de `empresa_taxonomy_category.origen` (`self_declared`, `validated` o `suggested`); para servicios y sectores, `perfil`.
  - **`rol`:** siempre `null`, con `rol_nota` «Rol no acreditado en el perfil».
- **Experiencias:** `experiencias_relacionadas` trae `experiencia_id` y un fragmento de una línea, centrado en la frase cuando aparece.
- **Variantes con y sin tilde:** una frase y sus variantes, por ejemplo `balancin` y `balancín`, se cuentan **una vez**, así que en la explicación no hay fuentes repetidas.
  - **Listas léxicas y de texto:** ya ignoran las tildes y se calculan una vez.
  - **Listas vectoriales:** se calculan con cada variante y se intercalan sin sumar, porque el embedding sí cambia.
- **`lexical_experiencia`:** con `order by id` determinista. Antes era `limit 20` sin orden.
- **Compuertas:**
  - **«similar a» única:** sobre todas las listas, como en el camino de siempre.
  - **«solo texto»:** sobre el texto del perfil del grupo de capacidades.
- **`/search-log`:** agrega `| worker <versión>` a `engine_version` (binding `version_metadata`). Solo afecta a filas nuevas.

### 2.2 n8n (solo 3 nodos; conexiones, prompt y modelo `gpt-6-luna` intactos)

| Nodo | Cambio |
|---|---|
| `Call MCP Empresas` | `grupos: true` solo cuando la herramienta es `search_empresas` |
| `Build Search Log` | por resultado agrega `grupo`, `cap` (tipo, código, origen, coincidencia) y `nexp`. `engine_version` = `flow <id> <promptVersion> \| T29-B r1`, y el Worker le suma su versión |
| `Format Cards2` | Si las filas traen `grupo`, muestra las dos secciones y el selector. Si no, usa el formato de siempre, sin cambios |

**`Format Cards2` con grupos:**
- **Sección principal:** «Empresas que ofrecen productos o servicios relacionados». Cada tarjeta muestra la capacidad que coincidió con su origen («declarada por la empresa», «validada por la Cámara», «sugerida por el sistema, no confirmada por la empresa» o «dato del perfil») y la nota de rol no acreditado. Las experiencias complementarias van plegadas.
- **Sección secundaria:** «Empresas con experiencias relacionadas», plegada por defecto y con el aviso «La experiencia no acredita por sí sola fabricación o comercialización».
  - **Sin capacidades:** se abre sola con la explicación.
- **Selector:** «Solo capacidades», por defecto, o «Capacidades + experiencias». Usa `aria-pressed`.
- **Se conservan:**
  - votos por tarjeta y por búsqueda (`search_log_id`);
  - puntaje relativo dentro de cada grupo, con su explicación y cálculo;
  - clic en la tarjeta, también con Enter, para pedir el perfil;
  - el enlace «Evaluar esta búsqueda», que CIRA Test agrega con el `data-log` de las tarjetas.
- **Seguridad:**
  - todo texto que viene de datos se escapa y se pasa a una sola línea, porque CIRA Test convierte cada `\n` en `<br>`;
  - el nombre que va al pedido de perfil se pasa con `JSON.stringify` y se escapa para HTML.

Diff de nodos, versión publicada y conexiones verificados contra el respaldo: solo cambian esos 3 nodos. Copia sanitizada: [`docs/cira/task0029/n8n/zbVLoCdR09IA9yQK_d2f8efb5_sanitizado.json`](../docs/cira/task0029/n8n/zbVLoCdR09IA9yQK_d2f8efb5_sanitizado.json). Fuentes del cambio: [`format_cards2_grupos.js`](../docs/cira/task0029/n8n/format_cards2_grupos.js) y [`build_flow.mjs`](../docs/cira/task0029/n8n/build_flow.mjs), que arma el cuerpo **desde el export vivo**, nunca desde una copia vieja.

## 3. Gates y preflight

| Gate | Resultado |
|---|---|
| Aislamiento del Worker | De 49 flujos de n8n, solo 2 activos llaman al Worker, y los dos son de staging: CIRA Test y los votos `iVDEEo0oaEHDOvlp`. El kiosco (`Z2OvMPk0zj2MSYwL`, MySQL) no lo usa. El comportamiento nuevo exige `grupos: true` |
| Regresión del camino de siempre | Regresión congelada contra la versión anterior: **32 de 32 sin cambios**, en la vista previa y en el vivo tras promover. Con las 55 consultas sobre datos vivos, **55 de 55 con el mismo orden** |
| Gate de BD | Sin DDL, migraciones, regeneración ni escrituras masivas. Las únicas escrituras son las del uso normal en el E2E (§5) |
| n8n | Export vivo respaldado fuera del repo antes de editar (`bafd597a`, sha256 `16631B77…`). `versionId` comprobado justo antes del `PUT`. Diff de 3 nodos |
| Preflight | Rama del Worker limpia y en `origin` (`1ea9175`). Repo principal en `e683a49` = `origin`. Flujo vivo idéntico al respaldo. Votos activos. `/cira-test/` 200. Despliegue previo `1005d61f` al 100 %. `wrangler` y la API de n8n con permisos. Laravel no se despliega, así que ninguna migración ajena se activa. Monitorizaciones de TASK-0026 y TASK-0027 cerradas (2026-11-14) |

## 4. Regresión: 55 consultas (31 con etiquetas del cliente)

**Método:** consultas y etiquetas de TASK-0028 ([`cases.json`](../docs/cira/task0028/experimentos/cases.json)), con datos vivos de solo lectura (`search_empresas` no registra nada). Se compara el Worker vivo `1005d61f` en modo por defecto con la versión nueva `8796f292` con `grupos`. Antes se probó de punta a punta con `wrangler dev` sobre una copia aislada de la BD (dump `cira-t29-20261116T104910Z`, sin puertos publicados, ya retirada).

Resultados por consulta: [`resultados_55_vivo_vs_b.json`](../docs/cira/task0029/experimentos/resultados_55_vivo_vs_b.json), con ids y sin nombres. Métricas: [`metricas.json`](../docs/cira/task0029/experimentos/metricas.json).

### 4.1 Métricas frente a la línea base de la auditoría

| Métrica (31 consultas etiquetadas) | Actual (vivo) | B auditado (TASK-0028) | **B desplegado** |
|---|---|---|---|
| No relevantes en el top 5 (grupo principal) | 38 | 27 | **27** |
| Relevantes en el top 5 (grupo principal) | 34 | 24 | **23** |
| Precisión sobre lo juzgado | 47,2 % | 47,1 % | **46,0 %** |
| Relevantes en el top 10 (cobertura; en B, top 10 de cada grupo) | 39/49 | 39/49 | **39/49** |
| Consultas sin resultados (de 55) | 2 | 2 | **2** |

**Desviaciones, con su causa:**
- **Una relevante menos en el top 5, por eso 46,0 %:**
  - **Dónde:** en «mantenimiento de cabrias» (G3-01), la relevante 179 baja del top 5 del grupo principal.
  - **Por qué:** entran antes empresas cuyo sector o servicio declarado coincide con «mantenimiento», es decir del nivel declarado.
  - **Efecto:** sigue en la cobertura del top 10.
- **3 empresas del orden actual que B no muestra:** 173 y 133 en «tratamiento de aguas de perforación», puestos 9 y 13; 138 en «fabricación de válvulas industriales», puesto 15. Las tres son coincidencias solo «por significado» y el cliente no las juzgó.
  - **Por qué:** las listas vectoriales de las variantes se intercalan con el mismo tope.
- **Ninguna relevante del cliente se pierde.**

### 4.2 Casos fijados por la asignación

| Consulta | Grupo principal | Grupo de experiencias | Cumple |
|---|---|---|---|
| balancin (G2-07) | 157 LUKIVEN: categoría `CPV-29.04.02G` «Balancín», bien, `self_declared`, 100 % catálogo; 3 experiencias complementarias | 91 DISTEINSA, 333 CONSORCIO OGS, 391 TOTAL CLEAN | Sí |
| macolla (G2-04) | — | 13 empresas, entre ellas las 4 relevantes del cliente (155, 171, 1, 76); se abre sola | Sí |
| fabricantes de balancines | 157 (`canonical_cpv`) | — | Sí; rol no acreditado |
| venta de balancines | 157 | 333 (texto del perfil por experiencias) | Sí |
| mantenimiento de balancines | 157; después servicios parecidos (74, 107, 73, 94…) | — (OGS y TOTAL CLEAN no entran: su única evidencia, texto o experiencia, queda bajo la compuerta) | Como en la auditoría §4.4 |
| sellado de fluidos en balancines | 487, 91 («servicio parecido»: EMPACADURAS Y SELLOS) | — | Sí |
| limpieza de balancines | 391, 523 (servicio parecido: LIMPIEZA) | 333 | Sí |
| válvulas de bola | 573 (empresa de QA activa, TASK-0028 §7), 80, 107, 157 | 560, 487 | Hallazgo previo, sin cambio de datos |
| revestidor (G1-03) | servicios parecidos | 157 (relevante del cliente) | Sí |
| mechurrio (G2-03) / camión chupón (G2-09) / guaya eléctrica (G2-06) | — | 140 / 391 / 79 (relevantes del cliente) | Sí |
| gandola | 6 empresas por `CPV-42.01`, todas `suggested` (se rotulan «sugerida por el sistema») | 433 | Sí |
| sin resultados («xyzzy qwerty…») | — | — | Mensaje de siempre |

### 4.3 Corrección durante las pruebas (r1 → r2)

La primera vista previa (`3b7a6238`) dio 30 no relevantes en el top 5 y perdía «camión chupón» (391). Las causas fueron dos:
- **Compuerta «similar a» solo sobre el catálogo:** dejaba pasar «servicio parecido» de una sola vía cuando la evidencia fuerte estaba en experiencias.
- **Una sola variante de escritura para el embedding:** la 391 solo aparece con «camion chupon» sin tildes.

La r2 (`8796f292`) aplica la compuerta sobre todas las listas y calcula las listas vectoriales con cada variante. Así quedan los valores de §4.1.

### 4.4 Invariantes (55 consultas)

| Invariante | Resultado |
|---|---|
| Empresa repetida entre grupos | 0 |
| `rol` distinto de `null` | 0 |
| Frases repetidas por tildes en las fuentes | 0 |
| Algún resultado de experiencias antes que uno de capacidades | 0 |

**Origen de la capacidad mostrada** (566 tarjetas): `perfil` 341, `suggested` 207, `self_declared` 17, `validated` 2.

### 4.5 Latencia

**`search_empresas` (mediana de 55 consultas):**

| Versión | Mediana |
|---|---|
| Vista previa, por defecto | 2 027 ms |
| Vista previa, con `grupos` | 2 075 ms |
| Vivo anterior, por defecto | 1 425 ms |

La URL de vista previa agrega su propio costo. Medida sobre la misma versión, la diferencia de B es de unos 50 ms.

**Flujo completo en el E2E (agente + Worker + registro):** entre 10 y 19 s. La auditoría midió 13 s antes del cambio.

## 5. E2E real en staging (11:19–11:27Z)

Se usó Playwright desde el servidor, en escritorio (1280 × 900) y en Pixel 5, sobre `https://pruebas.camarapetrolera.app/cira-test/`.

| Paso | Escritorio | Móvil |
|---|---|---|
| Consulta «balancin»: dos grupos (1 + 3), experiencias plegadas | PASS (log 74) | PASS (log 77) |
| Selector «Capacidades + experiencias» abre ambos `<details>`; «Solo capacidades» los vuelve a plegar | PASS | PASS |
| 👍 en LUKIVEN → `cira_search_feedback` id 35 (log 74, empresa 157, posición 1, `staff`) | PASS | — (para no duplicar votos) |
| Clic en la empresa → CIRA envía «dame información sobre la empresa LUKIVEN, S.A.» y muestra la ficha | PASS (logs 80–84) | — |
| «Evaluar esta búsqueda» → `/admin/evaluacion-cira/evaluar?log=74&sid=…` | PASS | PASS (`log=77`) |
| «macolla»: sin capacidades, grupo de experiencias abierto, aviso y selector en «Capacidades + experiencias» | PASS | PASS |
| Sin desplazamiento horizontal ni errores de JavaScript | PASS | PASS |

**Comprobación en la BD** (transacción `READ ONLY`, dentro del contenedor de staging):
- **Búsquedas nuevas (74–84):** guardan `engine_version` = `flow zbVLoCdR09IA9yQK TASK-0013-step1-v2+explain+votos | T29-B r1 | worker 8796f292`, y cada resultado trae `grupo` y `cap`.
- **Filas anteriores:** sin cambios.
- **Instantánea de TASK-0027 (`snapshotFromLog(74)`):** lee los 4 resultados en orden, con relevancia y fuentes.
- **Evaluaciones nuevas:** 0.

**Datos que dejó el E2E** (uso normal, sin borrar):
- 11 filas de `cira_search_log` (74–84, sesiones `test-…`);
- 1 voto (id 35).

El propietario decide si se conservan.

**No probado en vivo:** guardar y reanudar una evaluación, y el panel autorizado. Habría que crear evaluaciones con una cuenta invitada, y la asignación prohíbe crear evaluaciones de la Cámara. Laravel no cambió, la instantánea es compatible y TASK-0027 ya cubrió ese flujo (E2E 45/45).

**Capturas:** [`docs/cira/task0029/capturas/`](../docs/cira/task0029/capturas/).

| Captura | Qué muestra |
|---|---|
| `antes_*_balancin.png` | Formato anterior: una sola lista |
| `e2e_*_balancin_cap.png` | Vista «Solo capacidades» |
| `e2e_*_balancin_todo.png` | Vista «Capacidades + experiencias» |
| `e2e_*_macolla.png` | Consulta sin capacidades |
| `e2e_desktop_perfil.png` | Ficha de la empresa |

## 6. Rollback (cada componente por separado)

| Componente | Cómo | Compatibilidad verificada |
|---|---|---|
| Worker | `npx wrangler versions deploy 1005d61f-7024-4a10-a201-9106a8a7f8c3@100%` (desde `perfilafiliados-mcp`) | La versión `1005d61f` ignora `grupos` y responde con el formato anterior (probado en vivo, solo lectura). `Format Cards2` sin `grupo` usa el formato de siempre (vista previa) |
| n8n | `PUT /api/v1/workflows/zbVLoCdR09IA9yQK` con el respaldo `%USERPROFILE%\.cpv\n8n_backups\zbVLoCdR09IA9yQK_2026-11-16_pre-t29_live.json` (`bafd597a`); en esta instancia el `PUT` publica la versión | Con el flujo anterior, el Worker nuevo no recibe `grupos` y responde igual que antes (32 de 32 y 55 de 55) |
| BD | Nada que revertir | — |

Los rollbacks no se ejecutaron en vivo para no generar cambios de más. La compatibilidad de cada mitad sí se verificó.

## 7. UAT del propietario (5–10 minutos)

1. Abrir `https://pruebas.camarapetrolera.app/cira-test/` y pulsar «Nueva conversación» antes de cada consulta.
2. **«balancin»:**
   - **Grupo principal:** solo LUKIVEN, con «coincide · categoría CPV: Balancín (CPV-29.04.02G, bien) · declarada por la empresa» y «Rol no acreditado en el perfil».
   - **Experiencias de LUKIVEN:** pulsar «También tiene experiencias relacionadas (3)» para verlas.
   - **Grupo «Empresas con experiencias relacionadas (3)»:** plegado.
3. **Selector:** pulsar «Capacidades + experiencias». Se abren DISTEINSA, CONSORCIO OGS y TOTAL CLEAN, cada una con el fragmento de su experiencia y el aviso. Volver a «Solo capacidades».
4. **Puntaje:** abrir «por qué aparece y cálculo del puntaje» en una tarjeta. Las fuentes ya no se repiten por la tilde.
5. **Votos:** votar 👍 o 👎 en una empresa de cada grupo. Debe responder «✓ gracias» o pedir el motivo.
6. **Evaluación:** pulsar «Evaluar esta búsqueda». Abre el módulo de evaluación con esa búsqueda (requiere sesión).
7. **«macolla»:** no hay grupo principal, y las experiencias se abren solas con la explicación.
8. **Otras consultas:**
   - «mantenimiento de balancines» y «limpieza de balancines»: servicios parecidos rotulados «≈ parecido por significado»;
   - «gandola»: categorías rotuladas «sugerida por el sistema, no confirmada por la empresa»;
   - una consulta sin resultados: mensaje de siempre.
9. **Móvil:** repetir 2, 3 y 7 en el teléfono.

## 8. Limitaciones y riesgos

- **Rol:** no se puede acreditar. No hay un dato de rol en el perfil (TASK-0028 §4.4), así que B nunca afirma fabricante, vendedor ni mantenedor.
- **Capacidades `suggested`:** el 36 % de las tarjetas con capacidad muestran una categoría sugerida por el sistema. Se rotulan como tal, sin presentarlas como declaración.
- **Servicios y sectores:** no tienen columna de origen. Se rotulan «dato del perfil de la empresa», sin afirmar validación.
- **Clasificación del texto del perfil:**
  - **Cómo se calcula:** se quitan las experiencias **actuales** de un documento de 2026-09-18 que nunca se regeneró.
  - **Riesgo:** si una experiencia cambió después, su texto viejo podría contarse como catálogo.
  - **Arreglo de fondo:** regenerar el documento sin experiencias (fase 3 de TASK-0028). Exige su propio gate de BD y **no se hizo**.
- **Puntaje relativo:**
  - **Cómo se calcula:** se toma dentro de cada grupo, contra el primer resultado, que puede ser de nivel declarado.
  - **Efecto:** un «servicio parecido» posterior puede pasar de 100; ya ocurría en el formato anterior y la leyenda lo explica.
- **Instantáneas de evaluación (TASK-0027):** guardan las posiciones en el orden mostrado, es decir el grupo 1 y después el grupo 2.
  - **Qué falta:** el formulario no muestra el grupo. El dato está en `result_evidence.grupo` y en `engine_version`, así que las campañas se pueden separar por versión.
  - **Para mostrarlo:** habría que cambiar Laravel, fuera de este alcance.
- **Empresa de QA 573:** sigue activa y encabeza «válvulas de bola» (TASK-0028 §7). Su tratamiento es decisión del propietario.
- **Latencia:** unos 50 ms más en el Worker, por una consulta extra por frase y los embeddings de las variantes.

## 9. Cero cambios fuera de alcance

- **Laravel:** staging sigue en `5e6e3fc`.
- **BD:** no se ejecutaron DDL ni migraciones, `empresa_search_documents` no se regeneró y no hubo `UPDATE` ni `DELETE`.
- **Taxonomía, roles, datos de empresas y credenciales:** sin cambios.
- **n8n:**
  - el kiosco y las demás copias no se tocaron;
  - la copia de prueba `ANvAvXQGAKrISrNu`, creada y desactivada para esta tarea, se borró;
  - el flujo de votos sigue igual (`685bc0fa`).
- **Copia aislada de la BD:** se retiró. El dump queda fuera del repo, en `/root/backups/cira-t29-20261116T104910Z` (permisos 700).
- **`main`:** sin cambios, y no hubo *force-push*.
