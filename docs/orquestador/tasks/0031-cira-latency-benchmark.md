# TASK-0031 — CIRA: diagnóstico y benchmark aislado de latencia (sin deploy)

| Campo | Valor |
|---|---|
| Estado | ~~IN_PROGRESS~~ → **READY_FOR_REVIEW — SIN DEPLOY** (2026-11-16 ~17:30Z) |
| Fuente | Issue #2 `6920929114` (estebanjvasquez, 2026-11-16T15:34:05Z): «UAT DEL PROPIETARIO — TASK-0030 + NUEVA ASIGNACIÓN: diagnóstico y comparación de rendimiento de CIRA (SIN DEPLOY)» |
| Antecedentes | TASK-0030 (entrega `6920381900`, HANDOFF `96943d3`): **aceptación parcial de UX**, no APPROVED global; TASK-0029 (`6915259431`). Ambas conservan su estado |
| Tipo | Análisis y experimentos aislados. **Sin deploy** |
| Rama de documentación | `fix/sec-01-r4-01-role-editor-preserve`, BASE `96943d3` |
| Gates | sin staging ni producción; sin editar el flujo n8n activo, el Worker vivo, ni el modelo o el prompt en vivo; sin `main`, DDL ni escrituras masivas; sin tocar taxonomía, roles, datos de empresas, credenciales, kiosco ni logs, votos o evaluaciones reales; STOP al entregar |

## Comentario fuente (íntegro)

## UAT DEL PROPIETARIO — TASK-0030 + NUEVA ASIGNACIÓN: diagnóstico y comparación de rendimiento de CIRA (SIN DEPLOY)

**Entrega revisada:** Issue #2 comentario **6920381900**, TASK-0030, HANDOFF `96943d3`; antecedente TASK-0029. Este comentario registra la observación del propietario y autoriza **únicamente una nueva tarea de análisis y experimentación aislada**, no cambios del flujo activo.

### Veredicto de UAT parcial
- `empresas de perforacion`: **15,40 s** de espera percibida; las vistas **no se mezclan**.
- `balancin`: **1,72 s**; las vistas **no se mezclan**.
- `macolla`: ofrece pasar a experiencias **sin mostrarlas automáticamente**.
- `gandola`: **13,59 s**; no se ha confirmado aún en esta UAT el etiquetado de categorías sugeridas.
- **Aceptación parcial de UX:** comportamiento de vistas excluyentes y estado vacío de `macolla` conforme a lo solicitado. **No es APPROVED global ni autoriza producción**; queda pendiente el resto de UAT, incluido etiquetado de `gandola`, votos/perfiles/evaluaciones si no se verificaron personalmente.
- Los tiempos son mediciones puntuales del navegador, no latencias desglosadas ni una regresión demostrada. No inferir causalidad por `balancin` rápido.

### Nueva tarea: diagnóstico y benchmark aislado de latencia
**Asignación expresa:** materializar una tarea **nueva**, con el siguiente ID consecutivo libre **verificado en el repositorio** (no reutilizar TASK-0030, no inventar número), registrar el antecedente y mantener su estado. Leer `AGENTS.md`, `docs/orquestador/PROTOCOL.md`, `docs/orquestador/SESSION_RESUME.md`, `docs/orquestador/current_task.md` y solo la tarea activa y referencias directas; comprobar rama, HEAD, `git status -sb` y cambios ajenos antes de actuar. Seguir gates y STOP.

**Objetivo:** encontrar una forma comprobable de reducir tiempo de respuesta de CIRA, especialmente para `empresas de perforacion` y `gandola`, **sin degradar precisión, cobertura, trazabilidad, separación capacidades/experiencias, ni costes desproporcionados**.

**Plan obligatorio:**
1. Congelar línea base verificando versiones vivas de n8n `zbVLoCdR09IA9yQK` (`7feb48bc` reportada), Worker (`8796f292` reportada), modelo/prompt y datos relevantes; no asumir que siguen iguales.
2. Reproducir las cuatro consultas de UAT en sesiones nuevas y repeticiones controladas, registrando p50/p95, frío/caliente, tiempo navegador hasta tarjetas, n8n por nodo, tiempo modelo `gpt-6-luna`, `resolve_search_intent`, Worker/BD/embeddings, registro y render. Usar identificadores de ejecución, timestamps, condiciones y tamaño de muestra. No generar cargas excesivas.
3. Comparar alternativas **solo en entorno aislado**, cuando técnicamente factible: optimización de prompt, resolver intención sin llamada adicional, simplificar/evitar `resolve_search_intent` cuando no aporte valor, otros modelos compatibles, y ajustes de Worker. Documentar los gates técnicos y de seguridad antes de experimentar; no alterar credenciales ni endpoints compartidos. Si falta aislamiento, reportar bloqueo para esa alternativa.
4. Ejecutar el corpus existente de **55 consultas** y las **31 etiquetadas por el cliente** contra baseline y cada variante; conservar clasificación de capacidades/experiencias, orden, etiquetas `self_declared/validated/suggested`, relevancia top 5, cobertura top 10, consultas vacías, votos/logs/evaluaciones y no inventar roles. Documentar diferencias por consulta y regresiones, especialmente `balancin`, `macolla`, `gandola`, `empresas de perforacion`, fabricación y negativas. Sin reetiquetar juicios del cliente.
5. Comparar calidad, latencia p50/p95, coste por búsqueda, estabilidad, complejidad operativa y reversibilidad. Proponer opción preferida con evidencia y plan de implementación/rollback **para autorización posterior**, indicando si se necesita cambiar n8n, Worker, modelo o prompt. No presentar ganancias teóricas como mediciones.
6. Versionar tarea, informe, resultados sanitizados, decisiones y handoff; actualizar `current_task.md`, `SESSION_RESUME.md`, `audit/orchestrator_handoff.json` con SHAs diferenciados y pruebas realizadas. Publicar **READY_FOR_REVIEW — SIN DEPLOY** en Issue #2 con ID de comentario, HEAD y enlaces de evidencia, o `BLOCKED` con causa. **STOP** hasta decisión del propietario.

### Gates y prohibiciones
**Autorizado:** inspección de solo lectura, benchmarks acotados y pruebas en copias/entornos realmente aislados, documentación versionada. **NO autorizado:** desplegar a staging ni producción; editar flujo n8n activo, Worker vivo, modelo o prompt en vivo; `main`; migraciones/DDL; regeneración o escrituras masivas de BD; cambios en taxonomía, roles, datos de empresas, credenciales, kiosco, logs/votos/evaluaciones reales. Evitar loops y polling. Si no se puede garantizar aislamiento, STOP y solicitar autorización específica.

**Referencia:** https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6920381900

## Avance

- 2026-11-16 ~15:40Z: tarea materializada. Siguiente: congelar la línea base viva.
- ~15:45Z: línea base viva: n8n `7feb48bc`, Worker `8796f292`, agente `gpt-6-luna`; `resolve_search_intent` llama a `gpt-5.6-luna` en el Worker (3 llamadas a modelos por búsqueda).
- 15:50–17:20Z: 10 copias aisladas de n8n (registro desactivado, borradas al terminar) con 501 búsquedas: V0 ×2, V1, V1b, M41, M54, V1M41 (55 corpus + UAT), más la UAT limpia de V0 y V1b. Worker W1 (`988f87f`, vista previa sin tráfico `3539deec`): equivalente 55/55 por defecto; en frío p50 −0,38 s y p95 −0,76 s.
- Resultado: V1b (sin `resolve_search_intent`, mismo modelo) reduce el p50 de 10,2 a 7,0 s en el corpus y de 10,2 a 6,2 s en la UAT, con la calidad igual a V0 y menos costo. Cambiar de modelo rinde menos y baja la calidad.
- **READY_FOR_REVIEW — SIN DEPLOY**: [`../../../audit/CIRA_TASK0031_BENCHMARK_LATENCIA.md`](../../../audit/CIRA_TASK0031_BENCHMARK_LATENCIA.md). STOP: queda a la espera de la autorización del propietario.
