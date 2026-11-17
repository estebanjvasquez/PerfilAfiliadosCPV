# TASK-0033 — Preparación pre-UAT institucional de CPV (solo análisis, pruebas aisladas y documentación)

| Campo | Valor |
|---|---|
| Estado | **ASSIGNED** → IN_PROGRESS (2026-11-17 ~11:00Z) |
| Fuente | Issue #2 `6945631895` (estebanjvasquez, 2026-11-17T10:37:07Z): asignación expresa del propietario |
| Referencia directa | Issue #2 `6945403230` (estebanjvasquez, 2026-11-17T10:27:55Z): UAT manual del propietario de TASK-0032 fases A y B, aceptación funcional en staging con reserva de rendimiento y de relevancia (I.O.E.D./Geohidra en experiencias) |
| Antecedentes | TASK-0032: fases A y B en staging; aceptación funcional del propietario con reservas (`6945403230`); **no se reabre para implementación**. TASK-0030: aceptación parcial de UX, no APPROVED global. `PENDIENTES_CONSOLIDADO_2026-11-11.md`: corte histórico, no estado vivo |
| Comentario posterior | `6945848443` (10:47:04Z): propuesta documental del protocolo en la rama `docs/orchestrator-communication-protocol-20261117` (`b3e6dfb`); no es STOP/HOLD ni asignación; no se integra ni se cambia de rama |
| Rama | `fix/sec-01-r4-01-role-editor-preserve`, BASE `2cdb53dd199b0c2e3a783754b46a54da4f56668b` |
| Entregables | E1 inventario de pendientes; E2 matriz de seguridad y bloqueos preproducción; E3 paquete institucional de UAT; E4 diagnóstico de latencia CIRA (solo lectura); E5 decisiones de la Cámara; E6 informe ejecutivo y GO/NO-GO de UAT institucional. Índice pre-UAT que los enlaza |
| Autoriza | Lectura de código y documentos, SELECT de solo lectura, inspección de logs/configuración sin revelar secretos, análisis, pruebas aisladas realmente inocuas y documentación versionada |
| No autoriza | Deploy en staging o producción; merge a `main`; edición de n8n o Worker; prompt/modelo; W1; DDL/migraciones; escrituras o regeneración de BD; taxonomía, roles, empresas, credenciales, caché, kiosco; tráfico de producción; loops/polling; datos personales reales en evidencias |
| Etiquetas de evidencia | **verificado en fuente viva** (con fecha y comando); **documentado anteriormente**; **pendiente de comprobar**; **bloqueado por acceso**; **decisión del propietario/cliente** |
| Cierre | READY_FOR_REVIEW — SIN DEPLOY en Issue #2; STOP |

## Comentario fuente (íntegro): `6945631895`

## ASIGNACIÓN EXPRESA DEL PROPIETARIO — preparación pre-UAT institucional de CPV — SOLO análisis, pruebas aisladas y documentación

**Decisión:** el propietario autoriza una **tarea nueva**, independiente de TASK-0032, para preparar las pruebas con el personal de la Cámara Petrolera de Venezuela. Esta asignación NO aprueba producción ni reabre implementaciones pendientes. Referencia de UAT manual: comentario **6945403230**. Antecedentes directos: TASK-0032 (fases A/B en staging), `docs/orquestador/PENDIENTES_CONSOLIDADO_2026-11-11.md` (corte histórico, no estado vivo), `docs/orquestador/SESSION_RESUME.md` y `current_task.md`.

### Preflight y materialización
1. Leer `AGENTS.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/SESSION_RESUME.md`, `docs/orquestador/current_task.md` y solo las referencias directas necesarias. Comprobar `git status -sb`, rama, HEAD, remoto, cambios ajenos y comentarios recientes del Issue #2 antes de trabajar. No descartar ni incorporar cambios ajenos.
2. Comprobar el siguiente identificador consecutivo **libre** en `docs/orquestador/tasks/`; crear un archivo **nuevo** (sin reutilizar TASK-0032 ni inventar estados), actualizar `current_task.md`, `SESSION_RESUME.md`, auditoría y handoff conforme al protocolo. La tarea solo queda materializada al estar versionada y pusheada.
3. Tratar todo dato histórico como tal. Diferenciar **verificado en fuente viva**, **documentado anteriormente**, **pendiente de comprobar**, **bloqueado por acceso** y **decisión del propietario/cliente**. No declarar PASS, CLOSED o APPROVED por inferencia.

### Seis entregables obligatorios
**E1 — Inventario consolidado y actualizado de pendientes.** Matriz por área y tarea (CIRA, SEC-01/03 y restantes rondas SEC, TAXONOMÍA, TASK-0012/0014/0015/0016, perfiles, PDF/exportaciones, panel, infraestructura y producción): ID/fuente, evidencia y fecha, estado actual verificado o desconocido, riesgo, dependencia, responsable de decisión, prioridad y siguiente acción. Contrastar el consolidado del 11/11 con las entregas posteriores; conservar antecedentes sin sobrescribirlos. Distinguir pendientes técnicos, UAT del propietario, UAT de Cámara y gates de producción.

**E2 — Matriz de seguridad y bloqueos preproducción.** Reconciliar hallazgos SEC-01, SEC-03, SEC-02/04/05 planificados, reservas de TASK-0026/0027, recuperación por correo/RIF, permisos, vistas públicas, controles de exportación y caché de Cloudflare. Marcar evidencias de mitigación y pruebas faltantes, severidad, impacto, gate y orden de cierre. No probar ataques sobre producción ni rotar credenciales. No dar por cerrada seguridad sin evidencia.

**E3 — Paquete institucional de UAT.** Documento listo para personal no técnico de la Cámara con objetivos, roles, requisitos previos, entorno de staging, instrucciones paso a paso, casos con resultado esperado y captura de resultado real, severidad de incidencia, evidencia y criterio de aceptación/firma. Cubrir CIRA (perforación, balancín, macolla, gandola, camiones maquinaria pesada, búsqueda directa y detalle I.O.E.D/Geohidra, negativos, ortografía/sinónimos, capacidades frente a experiencias, relevancia de resultados, votos y evaluación), taxonomía/diccionario, perfiles/afiliación, PDF/exportación, autenticación/recuperación y módulo de evaluación CPV-01..08. Separar casos del propietario/admin de los que puede ejecutar el personal; no exponer datos sensibles ni hacer cambios reales de afiliados. Definir criterios de aceptación y bloqueantes claros.

**E4 — Diagnóstico acotado de latencia CIRA, SOLO lectura.** Baseline reproducible en staging con muestras suficientes, medir navegador/red, n8n (por nodo), agente/modelo y Worker, p50/p95 y variabilidad; documentar método, timestamps, IDs de ejecución y diferencias entre latencia percibida y n8n. Usar registros existentes y, solo si hay aislamiento real y sin escritura en registros/votos/evaluaciones reales, pruebas sintéticas aisladas. No generar tráfico descontrolado ni cargas significativas. Comparar con UAT manual 12,78 / 10,74 / 8,24 / 10,74 / 10,08 s y benchmark aislado V1b sin atribuir causalidad sin prueba. Entregar diagnóstico y opciones con beneficio/riesgo/costo; **NO implementar W1 ni cambiar modelo/Worker/n8n**.

**E5 — Documento de decisiones de la Cámara.** Listado verificable, con fuentes y fecha, de empresas sin sector principal (antecedente 194/406), servicio «X» (antecedente 250 vínculos/236 empresas, 109 con único sector), clasificación de capacidades frente a experiencias, términos/alias, resultados dudosos y sugerencias de categorías. Preparar preguntas concretas y formatos para respuesta del cliente; no modificar taxonomía ni datos. Regla: `chamber_empresa` registra afiliaciones a **otras cámaras**, NO pertenencia a CPV; las empresas del Perfil de Afiliados son afiliadas CPV según el criterio de estado verificado, no por presencia en esa tabla. Si cifras actuales no se pueden verificar con SELECT de solo lectura, etiquetarlas como históricas.

**E6 — Informe ejecutivo de preparación y plan de acciones.** Ordenar trabajo en (A) puede prepararse/corregirse documentalmente antes de UAT, (B) requiere nueva asignación y gate técnico, (C) requiere decisión de la Cámara, (D) bloquea producción. Proponer secuencia, estimación razonada, dependencias, riesgos y criterio objetivo de **GO/NO-GO para iniciar UAT institucional**, distinto de GO a producción. No asignar implementaciones implícitas.

### Restricciones / gates
- **Autorizado:** lectura de código y documentos, SELECT de solo lectura, inspección de logs/configuración sin revelar secretos, análisis, pruebas aisladas realmente inocuas y documentación versionada. Si una prueba implica escritura en servicios compartidos, registros reales, correo a terceros o alteración de estado, **NO ejecutarla**; documentar el gate requerido.
- **Prohibido sin nueva autorización:** deploy en staging o producción, merge a `main`, edición de n8n o Worker, cambios de prompt/modelo, W1, DDL/migraciones, escrituras o regeneración de BD, taxonomía, roles, empresas, credenciales, caché, kiosco o tráfico de producción; loops, polling, ejecución autónoma indefinida. No usar datos personales reales en evidencias.
- **Preservar TASK-0032:** UAT funcional del propietario aceptada en staging con reserva de rendimiento/relevancia; no aprobación global de TASK-0030/0029 ni de producción. Versiones reportadas: n8n `2d0a1ee8`, Worker `8ce4e0ff`, Laravel `5e6e3fc`; comprobar antes de citarlas como actuales.
- Acotar tiempo y costo; si faltan accesos o hay divergencia de fuentes, registrar `BLOCKED` para el subapartado y continuar solo con otros análisis seguros. Evitar leer toda la historia o el hilo indiscriminadamente.

### Cierre
Entregar seis documentos/matrices enlazados desde un índice pre-UAT, con evidencia verificable y distinción clara de hechos/recomendaciones; actualizar archivo de tarea, `audit/`, `docs/orquestador/current_task.md`, `SESSION_RESUME.md` y `audit/orchestrator_handoff.json` con SHAs BASE/REVIEW/HANDOFF. Publicar aquí **READY_FOR_REVIEW — SIN DEPLOY** con identificador real de tarea, rama, HEAD, tests/consultas realizadas, enlaces y bloqueos. **STOP** hasta revisión del propietario/orquestador. Este comentario constituye la asignación humana; la materialización en repo debe hacerla el ejecutor según PROTOCOL.md.

## Avance

- 2026-11-17 ~11:00Z: preflight (rama, HEAD `2cdb53d` = remoto, cambios ajenos sin tocar: `AGENTS.md`, `CLAUDE.md`, `PROTOCOL.md`, `SESSION_RESUME.md`, `docs/uat/RESULTADOS_BUSCADOR_LOTE001.xlsx`); Issue #2 sin STOP/HOLD/BLOCKED posteriores; materialización.
