# TASK-0016 — Análisis IA de solicitudes de categorías CPV

| Campo | Valor |
|---|---|
| Identificador | **TASK-0016**. Se verificó libre: `tasks/` llega a 0015 y no aparece en `current_task.md`, en el handoff, en los mensajes de commit, en las ramas ni en `docs/` de ninguna rama remota |
| Fuente | Issue #2, comentario [`6791532623`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6791532623) (propietario, 2026-11-11 08:55Z). El texto íntegro está al final de este archivo |
| Relación | Funcionalidad nueva. No cambia el propósito ni el identificador de TASK-0010A/0010C (módulo de solicitudes), ni de TASK-0013/0014/0015, que siguen en revisión en otras sesiones |
| Rama de código | `feat/category-request-ai-analysis`, en el worktree `C:\Proyectos\GitHub\PerfilAfiliadosCPV-wt-ia`. Parte de `origin/feature/upgrade-filament-v3` @ `d8e2aae`, que es lo que corre staging, con TASK-0014/0015. Sin *upstream*: un `git push` sin argumentos no puede llegar a la rama que despliega |
| Registro | este archivo, `current_task.md` y el handoff, en la rama canónica `fix/sec-01-r4-01-role-editor-preserve` (mismo esquema que TASK-0014) |
| BASE | `d8e2aae46151642f50f2f29fcc1ff546ebd76cbf` |
| Estado | **IN_PROGRESS** (2026-11-11) |
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

*(diseño, implementación y evidencia: en curso)*

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
