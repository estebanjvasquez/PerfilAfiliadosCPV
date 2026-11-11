# TASK-0016 — Análisis IA de solicitudes de categorías CPV

| Campo | Valor |
|---|---|
| Identificador | **TASK-0016**. Se verificó libre: `tasks/` llega a 0015 y no aparece en `current_task.md`, en el handoff, en los mensajes de commit, en las ramas ni en `docs/` de ninguna rama remota |
| Fuente | Issue #2, comentario [`6791532623`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6791532623) (propietario, 2026-11-11 08:55Z). El texto íntegro está al final de este archivo |
| Relación | Funcionalidad nueva. No cambia el propósito ni el identificador de TASK-0010A/0010C (módulo de solicitudes), ni de TASK-0013/0014/0015, que siguen en revisión en otras sesiones |
| Rama de código | `feat/category-request-ai-analysis`, en el worktree `C:\Proyectos\GitHub\PerfilAfiliadosCPV-wt-ia`. Parte de `origin/feature/upgrade-filament-v3` @ `d8e2aae`, que es lo que corre staging, con TASK-0014/0015. Sin *upstream*: un `git push` sin argumentos no puede llegar a la rama que despliega |
| Registro | este archivo, `current_task.md` y el handoff, en la rama canónica `fix/sec-01-r4-01-role-editor-preserve` (mismo esquema que TASK-0014) |
| BASE | `d8e2aae46151642f50f2f29fcc1ff546ebd76cbf` |
| Estado | **READY_FOR_REVIEW, DESPLEGADA EN STAGING** (2026-11-11). REVIEW_HEAD `893351ff2769c7186a70f46dd03b6089990c8c05` en `feat/category-request-ai-analysis`, con el addendum; antes `2405b67`. Staging `d8e2aae..893351f` (run `42999603780`), por autorización del propietario en el chat; sin merge a `main` |
| Gates | sin merge, sin push a `feature/upgrade-filament-v3` (despliega), sin despliegue, sin n8n/CIRA, sin cambios de taxonomía publicada, sin gasto de API, sin cambiar credenciales |

La rama canónica tiene cambios del propietario que se conservan sin commitear: `AGENTS.md`, `CLAUDE.md`,
`docs/orquestador/PROTOCOL.md`, `docs/orquestador/SESSION_RESUME.md` y `docs/uat/RESULTADOS_BUSCADOR_LOTE001.xlsx`,
este último sin seguimiento.

## Descubrimiento (código en BASE; staging `d8e2aae`, solo lectura, transacción READ ONLY + rollback)

**Módulo existente.**
- Modelo y tabla: `App\Models\TaxonomyCategoryRequest` / `taxonomy_category_requests` (TASK-0010A), con
  `taxonomy_category_request_delivery_attempts` (TASK-0010C).
- Campos de la petición:
  - `necesidad`: «Qué necesita representar»;
  - `justificacion`: una lista cerrada de 6 motivos;
  - `detalle`;
  - `terminos_probados`;
  - `context_snapshot` (JSONB): empresa, solicitante y categorías que tenía.
- `request_status` tiene un solo valor, `pending_review`.
- Pantalla: `TaxonomyCategoryRequestResource`, que muestra la lista y el detalle (`ViewTaxonomyCategoryRequest`). Es de
  solo lectura, salvo «Reintentar aviso».
- **Acceso:** `TaxonomyCategoryRequestPolicy` lo da solo a `super_admin`, por rol y no por permisos de Shield (decisión
  de TASK-0010C). Nadie crea, edita ni borra solicitudes desde el panel.

**Las solicitudes reales.** Hay **3, no 2**, todas en `pending_review`:

| N.º | Empresa | Aviso | Fecha | Necesidad |
|---|---|---|---|---|
| #1 | 451 | `failed` | 2026-10-15 | «afiliacion», detalle «afiliacion de empresas»: la prueba de TASK-0010C |
| #2 | 76 | `sent` | 2026-11-04 | «Servicios Asesoría En Ingeniería Consultoría En Ingeniería De Costos»: **el caso de aceptación** |
| #3 | 213 | `sent` | 2026-11-06 | «Sistemas fotovoltaicos» (energía solar, inversores híbridos) |

Las «dos pendientes de pruebas» del comentario serían, por contenido, la #2 y la #3. Esta tarea no cambia los datos ni
los estados de ninguna de las tres.

**El catálogo y la búsqueda.**
- `taxonomy_categories` tiene 2418 categorías activas de nivel 2. El nombre y la descripción están en
  `taxonomy_category_translations`.
- `TaxonomyCategorySearch::search()` busca por nombre, sinónimo, código y términos aprobados del diccionario
  (`TaxonomyLookup`), solo en categorías activas y nunca en Grupos. Desde TASK-0014 rellena con similitud semántica
  rotulada como sugerencia.
- **La parte semántica no está disponible en staging:** `MCP_EMBED_URL`/`MCP_EMBED_TOKEN` no están configurados, aunque
  existen 3497 vectores.
- La categoría del caso de aceptación existe y está vigente: `CPV-43.01.10S`, «Planificación, Programación y Control de
  Costos de Proyectos» (id 3340).

**IA y fuentes externas.**
- La aplicación no tiene ningún cliente de modelos de lenguaje: solo `EmbeddingClient`, que llama al Worker externo.
- CIRA y n8n están fuera de alcance (`gpt-6-luna` en n8n) y no se tocan.
- No hay ningún buscador web integrado.

## Decisiones

- **D1 — La IA asesora y no ejecuta.** El análisis solo lee la solicitud y el catálogo y escribe una fila propia.
  - No cambia `request_status` ni ningún otro campo de la solicitud.
  - No toca `taxonomy_categories`, sus traducciones ni `empresa_taxonomy_category`.
  - El proveedor no recibe herramientas.
  - La recomendación es una lista cerrada que no incluye «aprobar»: `reuse`, `clarify` y `evaluate_new`.
- **D2 — Autorización.** Policy nueva `analyzeWithAi`: solo `super_admin`, el mismo criterio del módulo
  (TASK-0010C).
  - No se crea un permiso de Shield que pudiera asignarse a otros roles.
  - El servicio vuelve a comprobarla en cada ejecución.
- **D3 — Recuperación separada del juicio.**
  - Las candidatas del catálogo salen de `TaxonomyCategorySearch`, el buscador del panel: activas, nunca Grupos, por
    nombre, sinónimo, código y diccionario, y semántica solo si está configurada.
  - Las fuentes externas salen de un `SourceRetriever` aparte.
  - El proveedor recibe ambas cosas como datos y solo juzga.
- **D4 — Nada inventado llega a la pantalla.** `AnalysisOutputValidator` valida la respuesta contra el esquema de
  `AnalysisPrompt`.
  - Una forma inválida descarta toda la respuesta y deja el análisis `failed` / `invalid_output`.
  - Se quitan y se anotan como «Control de la respuesta»:
    - los juicios sobre categorías no elegidas;
    - las sugerencias que no son candidatas del catálogo;
    - las citas a fuentes no recuperadas;
    - las recomendaciones fuera del análisis.
  - Las URL de las referencias vienen solo del buscador, nunca del texto del modelo, y se aceptan solo si son https a
    un nombre público. Esto evita SSRF al comprobar el enlace con HEAD, con 5 s y sin seguir redirecciones.
- **D5 — Prompt injection.**
  - El texto de sistema es fijo, con versión (`v1`).
  - La solicitud y las fuentes van en `<datos>` como JSON.
  - La salida está acotada por el esquema, y la vista escapa todo el texto.
- **D6 — Datos mínimos.** Al proveedor van la necesidad, el motivo, el detalle, las palabras probadas (recortados a 2000
  caracteres) y las rutas de las categorías que la empresa tenía. Nunca van el nombre ni el RIF de la empresa, ni el
  nombre, correo o teléfono del solicitante.
- **D7 — Solo inserción.** Tabla nueva `taxonomy_category_request_analyses`, que guarda:
  - las instantáneas de la solicitud, de las categorías elegidas y de las candidatas;
  - la versión de instrucciones, el proveedor y el modelo, el estado y si fue limitado;
  - la salida validada, las fuentes y los controles;
  - el código de error, los tokens, el costo y las fechas.

  Garantías:
  - un trigger rechaza UPDATE y el modelo rechaza `update`/`delete`;
  - FK con CASCADE desde la solicitud y SET NULL desde el actor;
  - RLS explícito, como TASK-0013;
  - la migración es aditiva y reversible y **no se aplicó** en la base compartida.
- **D8 — Fallas y costos.**
  - Tiempo de espera configurable (90 s), con un único reintento si la falla es transitoria.
  - Límites de 5 análisis por hora por solicitud y 20 por hora por administrador.
  - Tope diario de gasto opcional; con él alcanzado no se llama al proveedor.
  - El costo se estima solo con los precios configurados.
  - Del mensaje del proveedor **no se guarda ni se registra nada**; solo el código.
  - Si la tabla no existe todavía, la página funciona y el análisis queda desactivado.
- **D9 — Proveedor: inactivo, decisión pendiente.**
  - Por defecto `CATEGORY_ANALYSIS_PROVIDER=disabled` y `CATEGORY_ANALYSIS_SOURCES=disabled`: no hay llamadas ni gasto.
  - **No se escribió un adaptador de proveedor real.** Haría falta agregar una dependencia (el SDK oficial del
    proveedor elegido; en este equipo no hay Composer), una clave y presupuesto, y nada de eso está autorizado. El
    contrato (`AnalysisProvider`, `SourceRetriever`) está listo: el adaptador es una sola clase registrada en
    `AppServiceProvider`.
  - Propuesta al propietario: un proveedor con búsqueda web propia, cuyas URL salgan de sus bloques de resultado y
    nunca del texto del modelo, más un tope diario.

## Implementación (rama `feat/category-request-ai-analysis`)

| Commit | Contenido |
|---|---|
| `5ca11f9` | Servicio `App\Services\CategoryAnalysis\*` (contratos, `DisabledAnalysisProvider`/`DisabledSourceRetriever`, `AnalysisPrompt`, `AnalysisOutputValidator`, `CategoryAnalysisService`), modelo `TaxonomyCategoryRequestAnalysis`, migración, `config/category_analysis.php`, policy `analyzeWithAi`, acción «Analizar con IA» y sección «Análisis con IA» en el detalle (`resources/views/filament/category-requests/analyses.blade.php`), prueba `CategoryRequestAiAnalysisTest` |
| `37dafe1` | Pruebas: el ciclo de migración de TASK-0010A revierte primero la tabla nueva (FK), como ya hacía con la de TASK-0010C; búsqueda del catálogo por nombres reales; prueba de la migración (RLS, trigger, idempotencia) |
| `2405b67` | Manual del administrador §11 «Solicitudes de categoría: Analizar con IA» |

**Selector:** un `Select` múltiple buscable, con tope de 8, cuyas opciones son `TaxonomyCategorySearch::optionLabel`
(ruta · código · motivo). Arriba aparecen las candidatas que el buscador encontró con el texto de la solicitud,
marcadas «(catálogo)». Las sugerencias de la IA salen aparte en el informe, «Sugerencias de la IA (no elegidas; vienen
del catálogo vigente)», y solo pueden ser candidatas de ese catálogo.

## Pruebas (runner aislado de Contabo, PostgreSQL desechable, PHP 8.2.35)

| Corrida | SHA | Resultado |
|---|---|---|
| Focalizada, primera | `5ca11f9` | 3 fallos: el ciclo de migración de TASK-0010A (la FK nueva impedía su `down()`) y una búsqueda de prueba mal planteada. Se corrigieron en `37dafe1` |
| Focalizada | `37dafe1` | `CategoryRequestAiAnalysisTest` y las tres de solicitudes (`TaxonomyCategoryRequest*`): **OK (48 tests, 502 aserciones)** |
| Suite completa | `2405b67` | **OK (705 tests, 7773 aserciones)**, run `20261111T091904Z-2405b67dfa69`; staging 200 antes y después; sin contenedores `cpvtest` restantes |

**Escenarios de `CategoryRequestAiAnalysisTest`** (proveedor y buscador simulados, `Http::fake`):
- migración aditiva, reversible, idempotente, con RLS y trigger;
- solo `super_admin` (afiliados, lectura y reportes rechazados en el servidor);
- desactivado por defecto: sin llamadas y con el botón deshabilitado;
- el buscador ofrece solo categorías vigentes con su ruta, ni Grupos ni inactivas;
- selección inválida: vacía, inactiva, Grupo, inexistente o más de 8;
- **caso de aceptación «Ingeniería de Costos» contra «Planificación, Programación y Control de Costos de Proyectos»:**
  - el proveedor recibe la necesidad y las dos categorías reales con su id, código, ruta y descripción;
  - no recibe el nombre de la empresa ni el correo del solicitante;
  - el texto de sistema exige evaluar el alcance y prohíbe porcentajes;
  - la relación «parcial» la decide el proveedor simulado, no el código;
  - la solicitud y la taxonomía no cambian;
- categorías y citas inventadas descartadas; fuente no-https o con IP descartada y no comprobada;
- sugerencias solo del catálogo;
- fallas que nunca cambian la solicitud:
  - timeout con un reintento;
  - error sin reintento;
  - JSON roto o incompleto;
  - recomendación «approve» inexistente;
- búsqueda externa caída: análisis limitado;
- reanálisis sin sobrescribir; edición rechazada por el modelo y por la base;
- límite por solicitud y tope de gasto;
- inyección en el detalle: queda como datos y la pantalla escapa la salida;
- ni la clave ni el texto del afiliado en logs o en el registro;
- flujo Filament con dos categorías; el afiliado recibe 403 en el detalle.

## Limitaciones y pendientes

- **Sin proveedor real ni búsqueda externa real (D9):** ningún análisis real se ejecutó, ni sobre la #2 ni sobre la #3.
  La calidad del juicio en el caso «Ingeniería de Costos» queda por comprobar con el proveedor que se autorice, sobre
  esa misma solicitud.
- La migración no se aplicó en la base compartida. Aplicarla es un paso de despliegue que autoriza el propietario.
- Sin recorrido en navegador: el código no está desplegado y en este equipo las páginas de Filament no corren.
- El buscador del catálogo no filtra por jerarquía (Grupo/Familia) como criterio propio: busca por nombre, sinónimo,
  código y diccionario, y muestra la ruta. La semántica sigue apagada en staging.
- **Solicitudes reales:** hay 3, no 2. Ninguna se modificó: las pruebas usan solicitudes sintéticas, y la lectura en
  staging fue solo de agregados y textos de negocio, sin datos personales.

---

## Addendum 2026-11-11 — configuración en el panel, proveedores reales y despliegue (propietario, en el chat)

**Pedido del propietario (literal):**

> la seleccion del proveedor, colocar la api y seleccionar el modelo debe ser configurable en el panel del
> administrador dentro del menu de taxonomia CPV, al colocar la API elsistema debe poder motrar los modelos disponibles
> a seleccionar, al colocar la API esta debe dejar de ser visible para no poder filtrarla y se debe poder establecer un
> presupuesto diario
> Si esto ya esta considerado entonces autorizo el despliegue para hacer las pruebas en el navegador yo mismo con las
> solicitudes actuales

No estaba considerado, porque la configuración era solo por variables de entorno. Por eso se preguntó antes de seguir,
y el propietario respondió:
- **Proveedores:** «Anthropic y OpenAI».
- **Despliegue:** «Sí, desplegar en staging» cuando esté construido y pasen las pruebas. Eso incluye llevar los commits
  a `feature/upgrade-filament-v3` y aplicar en la base compartida las migraciones aditivas de esta tarea.

**Alcance.** Es una ampliación de TASK-0016, con el mismo propósito y el mismo identificador. Cambia D9: el proveedor
se configura en el panel y deja de ser solo una decisión pendiente.

**Decisiones del addendum:**
- **A1 — Dónde.** Una página nueva en el menú **Taxonomía CPV**, solo para `super_admin`. Desde ella se eligen el
  proveedor (desactivado, Anthropic u OpenAI), su clave, el modelo, el presupuesto diario, los precios y si se buscan
  fuentes externas.
- **A2 — La clave no se puede filtrar.**
  - Se guarda cifrada con la clave de la aplicación (cast `encrypted`).
  - El modelo la oculta al serializarse.
  - El formulario nunca la carga de vuelta: después de guardarla, la pantalla solo dice «clave guardada el …», sin
    ningún fragmento.
  - Una clave nueva la reemplaza; «Quitar la clave» la borra.
  - La clave no viaja en el estado de Livewire después de guardar y no se registra en los logs.
- **A3 — Modelos disponibles.** Con la clave escrita o la ya guardada, «Consultar modelos» pide la lista al proveedor:
  Models API de Anthropic, o `GET /v1/models` de OpenAI filtrado a los modelos de texto. El administrador elige uno de
  esa lista.
- **A4 — Presupuesto.** El análisis solo se activa con proveedor, clave, modelo, presupuesto diario mayor que 0 y
  precios por millón de tokens.
  - Con el tope alcanzado no se llama al proveedor.
  - El costo de cada análisis suma los tokens de las dos llamadas y las búsquedas web, si las hay.
  - Los precios los escribe el administrador, porque cambian.
- **A5 — Adaptadores.**
  - **Anthropic:** SDK oficial `anthropic-ai/sdk` ^1.8, la primera dependencia nueva de esta tarea. Se agregaron 5
    paquetes y no cambió ninguno: `anthropic-ai/sdk` 1.8.0, `open-telemetry/api` 1.11.0, `open-telemetry/context`
    1.6.0, `php-http/discovery` 1.20.0 y `standard-webhooks/standard-webhooks` 1.0.2. Se resolvieron con PHP 8.2.35,
    el de staging, usando el Composer del servidor de pruebas, porque aquí no hay.
  - **OpenAI:** cliente HTTP de Laravel, sin dependencia nueva, porque no hay SDK oficial de OpenAI para PHP.
  - **Fuentes externas:** búsqueda web de Anthropic (`web_search`). Las URL salen solo de sus bloques de resultado. Con
    OpenAI no hay búsqueda externa, y el análisis sale limitado.
  - `php-http/discovery` trae un plugin de Composer: se declaró `allow-plugins: {"php-http/discovery": false}` para que
    el build no dependa del valor por defecto de Composer. El descubrimiento funciona igual en tiempo de ejecución.
  - Hallazgo: el SDK 1.x envía la clave como `Authorization: Bearer`, no como `x-api-key`.
- **A6 — Leer la configuración no escribe.** Sin fila guardada, `current()` devuelve una instancia desactivada en
  memoria; la fila se crea al guardar. El tope se mira antes de cualquier llamada de pago, también antes de la búsqueda
  web.

**Implementación del addendum** (rama `feat/category-request-ai-analysis`):

| Commit | Contenido |
|---|---|
| `1601ac0` | Página `CategoryAnalysisSettingsPage` y modelo `CategoryAnalysisSettings` con su migración `2026_11_11_130000`. `ProviderRegistry`, `AnthropicProvider` (+`AnthropicClientFactory`, `AnthropicErrors`, `AnthropicWebSearchRetriever`), `OpenAiProvider`. El servicio toma presupuesto y precios del panel y suma el costo de las búsquedas. Cambios en `composer.json`/`composer.lock`; prueba `CategoryAnalysisSettingsTest` |
| `a93af1e` | Prueba: el SDK 1.x envía la clave como Bearer |
| `893351f` | Manual del administrador §11 «Configurar el proveedor, la clave, el modelo y el presupuesto» |

**Pruebas del addendum** (runner aislado):
- **Focalizadas** `a93af1e` (`CategoryAnalysisSettingsTest`, `CategoryRequestAiAnalysisTest`, `TaxonomyCategoryRequest*`
  y `SurfaceInventoryTest`): **OK (57 tests, 585 aserciones)**.
- **Suite completa `893351f`: OK (713 tests, 7851 aserciones)**, run `20261111T095230Z-893351ff2769`. Staging respondió
  200 antes y después.
- **Escenarios:**
  - solo `super_admin`;
  - la clave:
    - se guarda cifrada en la base;
    - no aparece en el HTML, en el estado de Livewire ni en el JSON del modelo, ni después de guardar ni al volver;
    - se conserva con el campo vacío y se quita con «Quitar la clave»;
  - el análisis no se activa si falta algún dato;
  - los modelos de Anthropic se listan con el SDK real sobre un transporte simulado; los de OpenAI, filtrados a texto,
    y una clave rechazada da un aviso sin la clave;
  - el adaptador de Anthropic envía `output_config` json_schema sin herramientas y traduce negativa y 401;
  - la búsqueda web toma URL solo de `web_search_result` y su consumo incluye las búsquedas;
  - el adaptador de OpenAI usa json_schema estricto y traduce negativa y 429 reintentable.

**Despliegue a staging** (autorizado por el propietario en el chat):
- Antes: `feature/upgrade-filament-v3` @ `d8e2aae`, sin migraciones pendientes, `APP_KEY` configurada, staging 200.
- 2026-11-11: avance rápido `d8e2aae..893351f`. El workflow aplica `php artisan migrate --force`, que esta vez aplica
  solo las dos migraciones aditivas de esta tarea.
- **Resultado (verificado en solo lectura):**
  - workflow `42999603780` success; staging en `893351f`;
  - `migrate:status`: las dos migraciones `2026_11_11_*` en el lote 62;
  - las dos tablas existen, con 0 filas y RLS activo; el trigger de solo inserción está presente;
  - `anthropic-ai/sdk` instalado en la imagen; el proveedor resuelto es `DisabledAnalysisProvider` porque todavía no hay
    configuración;
  - las 3 solicitudes reales siguen igual: los mismos `request_status`, `delivery_status` y `updated_at`;
  - HTTP: `/admin/login` 200, y `/admin/configuracion-analisis-ia` y `/admin/solicitudes-de-categoria` 302 a login sin
    sesión.
- **Pendiente del propietario:**
  - UAT en el navegador: configurar el proveedor, la clave, el modelo y el presupuesto, y analizar las solicitudes #2 y
    #3;
  - el primer análisis real es también la primera llamada de pago;
  - el juicio sobre «Ingeniería de Costos» se comprueba en esa UAT.

---

## Comentario fuente (íntegro, `6791532623`)

> # ASIGNACIÓN DEL PROPIETARIO — Análisis IA de solicitudes de categorías CPV (NUEVA TAREA)
>
> **Tipo:** nueva funcionalidad, no corrección ni ampliación de una tarea anterior. **Estado solicitado:** ASSIGNED tras materialización conforme a PROTOCOL.md. **No implica autorización de despliegue, merge, modificación de taxonomía publicada ni gasto de API.**
>
> ## 0. Arranque y control de continuidad (obligatorio)
> 1. Leer `AGENTS.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/SESSION_RESUME.md` y `docs/orquestador/current_task.md`, respetando las precedencias actuales. Leer **este comentario completo una sola vez** y únicamente sus referencias directas necesarias. No recorrer el hilo ni el historial.
> 2. Comprobar `git status -sb`, rama, HEAD, remoto y cambios preexistentes **antes de editar**. El checkpoint leído por el orquestador en `feature/upgrade-filament-v3` estaba fechado 2026-10-21 y refería trabajo de seguridad en `fix/sec-01-account-path-boundaries`; **no asumir que sigue siendo el estado vivo**. No descartar cambios ni cambiar de rama de forma insegura; usar worktree separado si corresponde.
> 3. Comprobar la tarea activa y el último identificador **en los archivos versionados**; crear un identificador nuevo consecutivo según `PROTOCOL.md`, sin inventar número ni reutilizar tareas. Materializar `docs/orquestador/tasks/000N-*.md` con esta asignación y actualizar `current_task.md`/checkpoint según el procedimiento, **preservando el antecedente y sin sobreescribir tareas en revisión**. Si hay conflicto con trabajo activo o falta autorización para una transición, **STOP y reportar**.
> 4. Registrar decisiones y evidencias en archivos versionados; Issue #2 es el canal humano. No loops, polling ni agentes autónomos.
>
> ## 1. Objetivo y alcance
> Añadir al módulo existente de solicitudes de nuevas categorías una acción administrativa **«Analizar con IA»**. Debe permitir estudiar la petición real del afiliado, buscar categorías vigentes del catálogo CPV, sugerir candidatos, permitir al administrador elegir **una o varias categorías** y producir un informe técnico comparativo con **fuentes externas verificables**. La IA asesora; **nunca aprueba, publica, modifica o vincula automáticamente** categorías ni cambia el estado de una solicitud.
>
> Integrar con las **dos solicitudes existentes pendientes de pruebas** sin alterar sus datos ni estados. Verificar su existencia, identificadores y estados contra la fuente real, en modo lectura; no asumirlos desde el chat.
>
> ## 2. Descubrimiento dirigido, antes de codificar
> Inspeccionar solamente modelos/tablas, relaciones, recursos Filament, policies, estados y servicios relacionados con solicitudes de categorías y taxonomía. Verificar las integraciones de IA/búsqueda existentes y sus contratos antes de elegir arquitectura. Reutilizar componentes cuando sea seguro; no tocar n8n/CIRA productivos. Confirmar los nombres reales de tablas y permisos; no inventarlos. Documentar el diseño y los puntos de integración.
>
> ## 3. Interfaz administrativa
> En detalle de solicitud: mostrar nombre, descripción y justificación original; acción «Analizar con IA»; buscador del catálogo vigente por texto/sinónimos/jerarquía, y búsqueda semántica si la infraestructura real lo soporta; sugerencias de IA distinguibles de resultados de catálogo; selector múltiple de categorías reales (ID estable, nombre oficial, ruta jerárquica, descripción), editable por el administrador; ejecución explícita, estado de progreso/errores, informe y versiones anteriores. Poder repetir el análisis con selección distinta sin sobrescribir resultados anteriores. No inventar categorías ni mostrar candidatos no publicados como vigentes.
>
> ## 4. Motor y fuentes
> Servicio desacoplado de proveedor, ejecutado en backend, con salida JSON validada por esquema. Entradas: instantánea de solicitud, categorías elegidas con ID/ruta/alcance, contexto sectorial, fuentes recuperadas. Separar **recuperación de candidatos** de **juicio de equivalencia**. Consultar realmente fuentes públicas técnicas (organismos, estándares, asociaciones profesionales, documentación especializada), con URL, título, entidad, fecha de consulta y, si está disponible, fecha de publicación; comprobar URLs cuando sea viable. **No inventar citas** ni presentar conocimiento paramétrico del modelo como investigación externa. Si la búsqueda externa falla/no está disponible, etiquetar el análisis como limitado y explicitar qué no se verificó. Tratar textos externos y del afiliado como datos no confiables (prompt injection).
>
> Informe: resumen ejecutivo; interpretación técnica y alcance de servicio; tabla por categoría con coincidencias, diferencias, actividades cubiertas/no cubiertas y relación (equivalente, parcial, más amplia, más específica, relacionada, insuficiente); recomendación razonada (reutilizar una o varias, pedir aclaración, proponer evaluación de categoría nueva); referencias y limitaciones. No usar porcentajes de «confianza» no calibrados.
>
> **Caso de aceptación:** «Ingeniería de Costos» vs «Planificación, Programación y Control de Costos de Proyectos»: detectar solapamiento importante pero no equivalencia completa sin comprobar alcance del afiliado; no hardcodear conclusión.
>
> ## 5. Seguridad, costes y persistencia
> Autorizar **en servidor** solo a roles administrativos existentes expresamente habilitados; no ampliar permisos por defecto. Claves API solo en secretos de entorno, nunca en navegador/git/logs. Minimizar datos personales enviados a terceros; timeout, límites de tamaño/frecuencia/tokens/costo, reintentos acotados, manejo de fallos y controles de salida. **No activar consumo de API de pago ni cambiar credenciales sin autorización explícita**: usar mocks/configuración inactiva hasta recibirla.
>
> Persistir análisis append-only por ejecución: solicitud, actor, fecha, IDs y snapshot de categorías, versión de prompt, proveedor/modelo, estado, salida estructurada, fuentes, errores sanitizados, tokens/costo si disponibles. Mantener historial y trazabilidad; no alterar estados ni taxonomía por efectos colaterales. Migraciones aditivas y reversibles, sin operaciones destructivas.
>
> ## 6. Pruebas y aceptación
> Pruebas automatizadas con proveedor/buscador simulados: autorización positiva/negativa; recuperación de categorías **reales**; selección múltiple; integridad de IDs y jerarquía; análisis de relaciones de alcance; citas trazables y ausencia de fuentes inventadas; fallos/timeouts/API sin mutar solicitud; reanálisis con historial intacto; bloqueo de publicación automática; aislamiento de las dos solicitudes reales; protección de prompt injection y ausencia de secretos en logs. Probar flujo Filament y regresiones pertinentes. Documentar manual de uso y limitaciones, costos/configuración y evidencia de tests.
>
> ## 7. Gates y entrega
> No merge a `main`, no push que desencadene despliegue, no deploy staging/producción, no cambios a n8n, no cambios masivos/publicación de taxonomía, no operaciones destructivas ni rotación de secretos sin autorización explícita. Si un push es bloqueado por permisos, **reportar y no rodear el bloqueo**. No interrumpir revisiones de seguridad pendientes. Antes de implementar, si hay conflicto de rama/tarea, pedir resolución. Al terminar: código + tests + documentación + registro de decisiones y evidencia; actualizar handoff con `review_base_sha`, `review_head_sha` y `handoff_commit_sha` distintos según protocolo; publicar entrega en Issue #2 por canal autorizado; estado **READY_FOR_REVIEW** y STOP.
>
> **Respuesta esperada al recibir esta asignación:** confirmar rama/HEAD/tarea nueva materializada o indicar bloqueo preciso. **Respuesta final tras pruebas:** `READY_FOR_REVIEW / Issue #2 / HEAD <sha>`.
