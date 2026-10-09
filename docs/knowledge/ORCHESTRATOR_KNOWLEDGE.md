# Conocimiento del orquestador — CPV

Autor: orquestador ChatGPT. Fecha: 2026-10-09.
Alcance: contexto de negocio, continuidad y revisión. No constituye autorización nueva.
Referencias leídas: README y los cuatro AGENT_*.md en 2d53b0c54e3b80c63533d873bdfb31b8fb7ec4ac; documentos operativos en 99542c9072a00074b5d2c94c9607e089afa14691; Issue #2.
Las marcas [DOCUMENTADO] indican procedencia documental o conversacional, no una medición independiente de la base.

## 1. Contexto de negocio

[DOCUMENTADO — conversación del propietario, 2026-09-16] El problema inicial fue la inconsistencia de resultados del buscador semántico: empresas relevantes quedaban fuera de consultas como “tratamiento de aguas de perforación”. El propietario pidió extraer contenido relevante de la web declarada por cada afiliado (servicios, sectores y experiencia) y mantener la coexistencia con la taxonomía actual.

[INFERIDO — criterio de producto propuesto] Éxito significa encontrar afiliados cuya capacidad esté respaldada por información real, conservar el significado de capacidades compuestas y permitir revisión trazable. Un HTTP 200 o una regresión sin diferencias no demuestra relevancia comercial.

[DESCONOCIDO] No se dispone aquí de compromisos contractuales de fecha, SLA, aceptación o métricas acordadas con la Cámara. No se deben inventar ni publicar condiciones comerciales privadas.

## 2. Decisiones del propietario y alternativas

[DOCUMENTADO — conversaciones de revisión anteriores] El propietario identificó términos genéricos que no podían asignarse a una categoría concreta y pidió una salida explícita para lo genérico/ambiguo. También señaló que “pipeline” necesitaba considerar múltiples categorías y que la UI mostraba un subconjunto insuficiente. Estos antecedentes justifican preservar decisiones contextuales y verificar la cobertura de opciones antes de forzar un mapeo.

[DOCUMENTADO — historial visible y AGENT_TASK_HISTORY.md] La fase C materializó propuestas para revisión; no autorizó su publicación automática. El APPLY posterior de TASK-0007 tuvo su autorización específica. No extrapolar esa autorización a nuevas propuestas.

[DOCUMENTADO — reporte del propietario de 2026-10-09 y comentario 6084542694] TASK-0010A/B están autorizadas pero NO deben iniciarse todavía: primero debe funcionar el loop. Este documento no levanta la pausa.

[DOCUMENTADO — AUTONOMOUS_DEV_LOOP.md] El propietario no debe transportar mensajes técnicos entre ejecutores. Dentro del contrato vigente las correcciones ordinarias continúan sin pedir nueva autorización.

[INFERIDO — interpretación limitada] No hay evidencia suficiente para enumerar todas las alternativas históricamente descartadas. No afirmar que una tecnología o arquitectura fue rechazada si sólo consta como propuesta.

## 3. Criterio de auditoría

[DOCUMENTADO — AUTONOMOUS_DEV_LOOP.md §§4–5] La revisión se liga a TASK, rama y SHA exactos; compara contra el último checkpoint aceptado y lee los archivos reales. El resumen del agente es una pista, no la prueba.

[INFERIDO — criterio operativo del orquestador] PASS requiere cumplir el contrato y resolver los hallazgos bloqueantes con evidencia pertinente. CORRECTIONS_REQUIRED corresponde a defectos reproducibles, documentación operativa contradictoria, pruebas insuficientes para comportamiento nuevo o afirmaciones no respaldadas. BLOCKED_EXTERNAL describe un impedimento real externo. OWNER_GATE_REQUIRED se reserva a los gates A–F; una pausa vigente no se elimina al cambiar de sesión.

[DOCUMENTADO — protocolo de continuidad] Clasificación de evidencia:
- NEWLY EXECUTED: comprobada en esta ronda, indicando operación, SHA y resultado.
- INHERITED / VALID: resultado previo identificado y todavía aplicable al componente sin cambios.
- INVALIDATED / MUST RERUN: cambió código, datos o configuración relevante; la evidencia previa ya no basta.
- NOT APPLICABLE: la comprobación no corresponde al alcance.

[INFERIDO — aplicación a esta revisión] Las pruebas de runtime previas se conservan como evidencia heredada, no como tests ejecutados hoy. No se consultó la base ni se invocó CIRA en esta ronda documental. Una afirmación “sin secretos” heredada de un barrido no equivale a certificar todo el historial.

[MEDIDO — API GitHub, 2026-10-09] Se confirmaron ambas ramas en los SHA reportados y los comentarios 6084535525/6084542694. El commit 99542c9 cambia sólo current_task.md; 2d53b0c añade los seis Markdown de conocimiento. La última corrida de Actions consultada es 37458287882, del 2026-10-06, success, SHA 004d24e98159bf152c741bd9f0ee698b43139d87. Esto confirma ausencia de nuevo deploy vía Actions hasta esa consulta; no verifica el filesystem vivo del VPS.

## 4. Historia y autoridad

[DOCUMENTADO — AGENT_TASK_HISTORY.md y SESSION_RESUME.md] TASK-0007 y TASK-0008 tienen cierres publicados; sus antecedentes ya están en los archivos del agente y no se duplican aquí. Los conteos y resultados históricos son checkpoints, no lecturas actuales.

[DOCUMENTADO — comentario 6084542694] La escritura del agente en Issue #2 está disponible y supersede la indisponibilidad de 6079826097. El fallback sigue aplicando si falla esa capacidad.

[INFERIDO — límite de continuidad] El repositorio conserva conocimiento, no llaves ni procesos activos. Cambiar a ChatGPT de escritorio exige verificar los conectores de ese entorno. Poder leer y escribir en una sesión no demuestra polling persistente, recuperación tras reinicio ni funcionamiento de un orquestador cloud.

## 5. Roadmap y fundamento

[DOCUMENTADO — SESSION_RESUME.md] Secuencia identificada: E crawler; F integración de evidencia con taxonomía; G/H retrieval híbrido y ranking respaldado; I benchmark y gate de producción. Son trabajo posterior identificado, no autorización de inicio.

[INFERIDO — razón de las dependencias] Primero obtener fuentes relevantes y su procedencia; después relacionarlas con capacidades; luego evaluar cómo influye esa evidencia en recuperación y ranking. Optimizar pesos antes de contar con expectativas de cliente puede ocultar carencias de datos.

[INFERIDO — gate propuesto, pendiente de acuerdo] Una salida a producción debería exigir aceptación del cliente sobre casos esperados, regresión documentada, ausencia de hallazgos bloqueantes y autorización explícita del propietario. No existe en la evidencia revisada un umbral cuantitativo de aceptación comercial aprobado.

## 6. Responsabilidades y límites

[DOCUMENTADO — AUTONOMOUS_DEV_LOOP.md] El propietario autoriza gates; la Cámara aporta criterio de dominio; el agente implementa; el orquestador revisa y registra procedencia. Un comentario autenticado bajo la cuenta del propietario no es por sí mismo una autorización del propietario.

[DOCUMENTADO — reglas de la rama] Sólo se editan archivos ORCHESTRATOR_*.md aquí; discrepancias se registran en ORCHESTRATOR_CORRECTIONS.md. No rebase, force-push ni merge a feature/main desde esta contribución. Ninguna credencial se publica.

## 7. Respuestas a incógnitas

| Tema | Respuesta y grado de confianza |
|---|---|
| PHPUnit / TASK-0010 | [INFERIDO — recomendación] Ejecutar tests en un contenedor aislado o CI con dependencias de desarrollo y datos de prueba separados. Cubrir autorización, persistencia, validación y notificación de A; autenticación y coherencia del manual de B; después integración. No usar la base compartida como fixture. [DESCONOCIDO] Entorno aún no elegido/provisionado; 197/197 históricos no validan código futuro. |
| Calidad de búsqueda | [INFERIDO — recomendación] La UAT debe aportar casos y afiliados esperados para construir un conjunto de evaluación revisado por el cliente. Medir falsos positivos/negativos y orden de resultados tras acordar criterios. [DESCONOCIDO] Métrica y umbral aún no aprobados. |
| Repos públicos | [DOCUMENTADO — recomendación anterior del orquestador] Probar lectura/escritura autenticadas antes de cambiar visibilidad. [DESCONOCIDO] No consta autorización para privatizar estos dos repos; no confundirlos con SISTEG-Agent-Orchestrator. |
| Identidad automatizada | [DOCUMENTADO — protocolo] Declarar autor real en cada comentario. [INFERIDO — recomendación] GitHub App o identidad técnica dedicada con permisos mínimos. No crear identidad ni cambiar privilegios en esta ronda. |
| Credencial del agente | [DOCUMENTADO — ACCESS_BOOTSTRAP.md §5.0] Token dedicado ya provisionado; no hizo falta ampliar reglas del sandbox. §5.2 contradice esta conclusión: corrección solicitada aparte. |
| n8n / estado de datos | [DESCONOCIDO] No verificados en esta ronda. Mantener el preflight correspondiente antes de UAT o trabajo sobre datos. |

## 8. Reanudación rápida

[DOCUMENTADO — protocolo y pausa vigente] Leer checkpoint, current_task, handoff y veredicto más reciente para el SHA; verificar ramas remotas. Después leer este archivo y las preguntas abiertas sólo si hace falta contexto. Mantener TASK-0010A/B pausadas.

[INFERIDO — condición propuesta para demostrar el loop] Confirmar que el agente lee directamente el comentario de revisión correcto y responde con un nuevo handoff ligado al SHA; verificar que hay un ejecutor/monitor realmente configurado para reanudación y credenciales vigentes. Esta ronda no certifica ejecución autónoma permanente.
