# TASK-0030 — CIRA: correcciones de la UAT de TASK-0029 (vistas excluyentes y diagnóstico de latencia), solo staging

| Campo | Valor |
|---|---|
| Estado | **IN_PROGRESS** (2026-11-16) |
| Fuente | Issue #2 `6919847012` (estebanjvasquez, 2026-11-16T14:52:14Z): «CORRECTIONS_REQUIRED — TASK-0029 UAT» |
| Antecedente | TASK-0029 (entrega `6915259431`, HANDOFF `ac93eb6`), que conserva su identificador y su historia. **TASK-0029 no está aprobada para producción** |
| Rama de documentación | `fix/sec-01-r4-01-role-editor-preserve`, BASE `ac93eb6` |
| Componentes | Flujo n8n `zbVLoCdR09IA9yQK` (presentación); Worker `perfilafiliados-mcp` solo si hace falta y con aislamiento verificado |
| Gates | sin producción, `main`, DDL, regeneración de `empresa_search_documents`, escrituras masivas, taxonomía, roles, datos de empresas, credenciales, kiosco, cambios de modelo o prompt; STOP al entregar |

## Comentario fuente (íntegro)

## CORRECTIONS_REQUIRED — TASK-0029 UAT — instrucciones de corrección y autorización de despliegue SOLO a staging

**Origen:** entrega `DEPLOYED_FOR_OWNER_UAT` Issue #2 comentario **6915259431**, HANDOFF `ac93eb6`. **Observación directa del propietario:** al consultar **«empresas de perforacion»**, la respuesta tarda aproximadamente **12 segundos** en aparecer; al seleccionar «Solo capacidades» sigue apareciendo abajo un desplegable de «Empresas con experiencias relacionadas». **TASK-0029 NO está aprobada para producción.**

### Asignación y protocolo
Esta es una **nueva asignación explícita de corrección derivada de la UAT**. No reescribir ni reutilizar el propósito o identificador de TASK-0029: conservarla como antecedente y **materializar una tarea nueva con el siguiente identificador consecutivo disponible**, determinado en el repositorio, no supuesto. Registrar vínculo con TASK-0029 y este comentario. Antes de actuar leer `AGENTS.md`, `docs/orquestador/SESSION_RESUME.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/current_task.md`, la tarea nueva y solo las referencias directas necesarias; comprobar `git status -sb`, rama, HEAD y cambios existentes. Preservar trabajo ajeno; usar worktree si procede. No ejecutar loops, polling o agentes autónomos.

### 1. UX funcional — dos vistas mutuamente excluyentes
- Sustituir las opciones actuales «Solo capacidades» / «Capacidades + experiencias» por **«Solo capacidades»** (por defecto) y **«Empresas con experiencias relacionadas»**.
- En «Solo capacidades» mostrar **exclusivamente** tarjetas del grupo de capacidades; **no** renderizar debajo el desplegable/listado del grupo de experiencias. La existencia de experiencias se puede señalar de forma no invasiva, pero sin desplegar el segundo grupo dentro de esta vista.
- En «Empresas con experiencias relacionadas» mostrar **exclusivamente** tarjetas del grupo de experiencias, no una combinación de ambos grupos. Evitar duplicados dentro de la lista. Definir y documentar cómo tratar empresas que tienen ambas evidencias: la vista de experiencias puede mostrarlas si realmente tienen experiencias coincidentes, con su evidencia específica; **nunca** presentarlas como fabricantes/vendedores por inferencia.
- Mantener fuentes, categorías y procedencia `self_declared`/`validated`/`suggested` diferenciadas, explicaciones de puntajes, enlaces a perfiles, votos y `search_log_id`, módulo de evaluación y registros. Si no hay capacidades, mostrar un mensaje claro y ofrecer cambiar a experiencias, **sin cambiar de modo automáticamente**. Si no hay experiencias, mostrar su estado vacío.
- Comprobar accesibilidad, teclado, `aria-pressed`, responsive móvil/escritorio, ausencia de desbordamiento y etiquetas legibles.

### 2. Rendimiento — diagnóstico medido antes de optimizar
- Reproducir «empresas de perforacion» en staging con una sesión nueva, documentando consulta exacta, hora, entorno, número de resultados y tiempos percibidos.
- Medir por separado latencia del navegador/red, webhook y ejecución n8n por nodo, llamadas al Worker `search_empresas`, consultas a BD, embeddings y generación del modelo `gpt-6-luna`, formateo y renderizado. Distinguir tiempo hasta primer contenido y tiempo hasta tarjetas completas, si el flujo lo permite.
- Comparar con la línea base TASK-0029 y consultas «balancin», «macolla» y «gandola». Identificar cuello de botella **con evidencia**, no atribuir los 12 s al Worker sin medir.
- Implementar únicamente optimizaciones acotadas, justificadas, con pruebas de equivalencia funcional y mediciones antes/después. **No** cambiar modelo, prompts, ranking, semántica ni infraestructura compartida por simple suposición. Si una optimización exige nuevos permisos, cambios de BD, otro flujo o infraestructura fuera de alcance, detener esa parte y solicitar gate aparte. No prometer un umbral de latencia no medido.

### 3. Alcance y autorizaciones
Se **autoriza expresamente** corregir la presentación del flujo CIRA Test en n8n, y solo si es necesario y se verifica el aislamiento, ajustes compatibles del Worker de staging; realizar pruebas aisladas y **desplegar los cambios exclusivamente a staging** tras preflight, backup, diff mínimo, verificación de versión viva, rollback ensayado y comprobación de que ningún consumidor de producción comparte el componente afectado. Si el aislamiento no se demuestra, **STOP/BLOCKED**, pedir autorización específica y no editar el componente compartido. Preservar el modo sin `grupos` y las funciones actuales.

**No autorizado:** producción, `main`, migraciones/DDL, regeneración masiva de `empresa_search_documents`, escrituras o borrados masivos en BD, taxonomía, roles, datos reales de empresas, credenciales, kiosco, modificaciones no relacionadas de n8n ni cambios de modelo IA. No bypass de gates. No borrar evidencias ni historial.

### 4. Regresión y entrega
- Probar alternancia entre modos con «empresas de perforacion», «balancin», «macolla», «gandola», «mantenimiento de balancines», consultas sin resultados y casos de empresa con ambas evidencias; comprobar que ninguna vista mezcla tarjetas de la otra, ni aparece desplegable inferior redundante.
- Preservar compatibilidad de registros y evaluaciones TASK-0027, votos por tarjeta y búsqueda, perfil y explicación del puntaje. Repetir la batería de 55 consultas y regresión del camino por defecto; documentar cualquier desviación y el motivo.
- Pruebas E2E en staging escritorio y móvil, con capturas y medidas de latencia antes/después. No crear evaluaciones institucionales reales sin autorización.
- Versionar tarea, decisiones, informe de auditoría, evidencias, `current_task.md`, `SESSION_RESUME.md` y `audit/orchestrator_handoff.json` conforme a PROTOCOL, distinguiendo BASE_SHA, TESTED_HEAD, REVIEW_HEAD y HANDOFF_SHA.
- Publicar en **Issue #2** un comentario **`DEPLOYED_FOR_OWNER_UAT`** con número de comentario, HEAD, versiones n8n/Worker, URL staging, pruebas, latencias, límites y rollback; si falla un gate publicar **`BLOCKED`** con evidencia. **STOP** para aceptación manual del propietario; no pasar a producción.

**Referencia de entrega original:** https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6915259431

## Avance

- 2026-11-16 ~15:00Z: tarea materializada. Siguiente: medición de latencia antes de cambiar nada.
