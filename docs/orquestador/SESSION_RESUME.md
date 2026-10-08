# CPV — Punto de reanudación maestro

**Fecha del checkpoint:** 2026-10-08  
**Proyecto:** PerfilAfiliadosCPV / CIRA / Taxonomía CPV  
**Repositorio principal:** `estebanjvasquez/PerfilAfiliadosCPV`  
**Rama activa:** `feature/upgrade-filament-v3`  
**HEAD aceptado al crear este checkpoint:** `bdfefa97cda9cc812ae86fb4080989ccbb2182f2`  
**Runtime Laravel desplegado en staging:** `004d24e98159bf152c741bd9f0ee698b43139d87`  
**Worker repo:** `estebanjvasquez/perfilafiliados-mcp`  
**Worker source HEAD conocido:** `29de993c12fbdadf0577c3630cc07611af020a46`

> Este archivo es el punto de entrada para retomar el proyecto sin releer toda la conversación ni todo
> el Issue #2. Si cualquier dato de aquí contradice GitHub o la base viva, prevalece la evidencia viva y
> debe registrarse la discrepancia antes de seguir.

---

## 1. Estado actual

### TASK-0007 — APPLY atómico de taxonomía

**CLOSED / PASS.** Cierre formal del orquestador: Issue #2 comentario `6035642794`.

Resultado publicado en staging:
- 12 propuestas `APPLIED`
- 3 propuestas históricas `SUPERSEDED`
- 0 `PENDING_APPLY`
- 0 `ABORTED`
- 10 candidate links = 3 `published` + 7 `context_required`
- 2 concept relations = 2 `rejected`
- canonical concepts = 82
- TERM→CONCEPT = 145
- TERM→CPV = 9.749
- regresión congelada = 32/32, 288 campos, 0 diffs
- targeted E2E `pipeline` / `refinery` / `refinería` = PASS

No ejecutar otro APPLY sin una nueva autorización explícita.

### TASK-0008 — UAT cliente + revisión taxonómica del pasante

**CLOSED / PASS.** Cierre formal del orquestador: Issue #2 comentario `6041102746`.

Paquete aceptado en `docs/uat/`:
1. `README.md`
2. `GUIA_PRUEBAS_BUSCADOR_CLIENTE.md`
3. `FORMATO_RESULTADOS_BUSCADOR_CLIENTE.csv`
4. `FORMATO_RESULTADOS_BUSCADOR_CLIENTE.md`
5. `GUIA_CLASIFICACION_RESULTADOS_UAT.md`
6. `RESUMEN_UAT_CLIENTE_TEMPLATE.md`
7. `GUIA_ASIGNACION_PASANTE_REVISION_TAXONOMICA.md`
8. `FORMATO_REVISION_TAXONOMICA_PASANTE.csv`
9. `FORMATO_REVISION_TAXONOMICA_PASANTE.md`
10. `RESUMEN_LOTE_REVISION_TAXONOMICA_TEMPLATE.md`

**Estado operativo actual:** esperando dos entradas externas:
- resultados de la UAT del buscador por parte del cliente;
- primer lote de calibración del pasante: 30–50 relaciones, estrictamente REVIEW-ONLY.

El pasante NO está autorizado a usar `Aprobar`, `Rechazar`, `Editar`, `Eliminar` ni acciones
masivas durante la calibración. Sus cinco decisiones se registran sólo en la planilla.

---

## 2. Próximo trabajo cuando llegue feedback

No abrir una corrección de búsqueda a ciegas.

### Si llega la planilla UAT del cliente

Abrir **TASK-0009 — UAT TRIAGE**:
- clasificar cada observación;
- separar `FALSO_POSITIVO`, `FALSO_NEGATIVO`, `RANKING`, `TERMINO_TAXONOMIA`,
  `MAPEO_CPV`, `DATOS_EMPRESA`, `EVIDENCIA_WEB_FALTANTE`, `CONSULTA_AMBIGUA`,
  `UI_USABILIDAD`, `OTRO`;
- no corregir nada en la misma ronda de diagnóstico;
- producir backlog priorizado y resumen de aceptación.

### Si llega el lote del pasante

Revisar primero la calibración:
- comparar `Decision` vs `Decision_Final`;
- excluir casos genuinamente ambiguos del denominador;
- calcular tasa de coincidencia;
- referencia de calidad: ≥90% en casos no ambiguos;
- buscar patrones de sobre-aprobación;
- registrar términos ambiguos y categorías faltantes.

Sólo después, si el propietario lo autoriza, abrir una tarea separada para permisos operativos
controlados del pasante. TASK-0008 NO concede esos permisos.

### Trabajo posterior ya identificado

- ampliar cobertura TERM→CPV gobernada;
- Fase E: crawler de contenido web de empresas;
- Fase F: integración crawler → evidencia/taxonomía;
- Fases G/H: refinamiento retrieval híbrido / ranking sólo con evidencia real;
- Fase I: benchmark y gate de producción.

---

## 3. Estado de datos que debe verificarse al reanudar

Último estado aceptado:

TERM→CPV:
- total: 9.749
- approved: 212
- candidate: 17
- needs_review: 9.282
- deprecated: 238

Reviewed proposals:
- APPLIED: 12
- SUPERSEDED: 3
- PENDING_APPLY: 0
- ABORTED: 0

Último `taxonomy_audit_log.id` aceptado: **5260**.

Al reanudar después de una pausa larga, hacer una lectura de solo consulta para confirmar que estos
valores no cambiaron antes de asumir continuidad.

---

## 4. Accesos y rutas conocidas

### GitHub

Conector confirmado con lectura y escritura:
- principal: `estebanjvasquez/PerfilAfiliadosCPV`
- Worker: `estebanjvasquez/perfilafiliados-mcp`
- hilo de gobierno: **Issue #2** del repo principal

Loop del desarrollador:
1. agente trabaja;
2. devuelve `READY_FOR_REVIEW / Issue #2 / HEAD <sha>`;
3. orquestador lee código/docs reales en ese HEAD;
4. orquestador publica auditoría completa directamente en Issue #2;
5. usuario pasa al agente sólo el comentario de revisión y pide nuevo HEAD.

### Supabase

Proyecto conectado:
- project id: `mrquhxwvcrbwbuqafjee`

Usar el conector para lectura/auditoría y sólo hacer mutaciones con autorización explícita.

### Staging

- app: `https://pruebas.camarapetrolera.app`
- UAT CIRA: `https://pruebas.camarapetrolera.app/cira-test/`
- VPS conocido: `66.94.121.98`

Último deploy Laravel aceptado:
- `004d24e98159bf152c741bd9f0ee698b43139d87`
- GitHub Actions run `37458287882`

### Worker / Cloudflare

- Worker: `perfilafiliados-mcp`
- endpoint: `https://perfilafiliados-mcp.sisteg.workers.dev`
- Hyperdrive: `perfilafiliados-taxonomy`
- Hyperdrive id: `f16a1ab0a9514504b80bd14138699d4c`

**No guardar secretos en este archivo.**
El `DEBUG_TOKEN` fue rotado para TASK-0007 y existe como secret del Worker, pero su plaintext no debe
considerarse recuperable desde GitHub ni desde este checkpoint. Si se necesita de nuevo, seguir un flujo
de autorización de secretos explícito.

No tocar la configuración Hyperdrive no relacionada `talento-cpv-db`.

### n8n / CIRA

La página de UAT llama al flujo clonado de pruebas. Antes de una sesión UAT confirmar que el workflow
está activo; si está detenido, todas las consultas pueden fallar igual y producir un falso diagnóstico
de caída del buscador.

---

## 5. Restricciones de continuidad

Hasta nueva autorización:
- no merge a `main`;
- no deploy a producción;
- no otro APPLY/replay;
- no aprobación masiva de TERM→CPV;
- no cambios de ranking/pesos para “mejorar” una consulta aislada;
- no cambios de secretos;
- no otorgar al pasante permiso operativo de publicación;
- no tocar baseline `audit/regression_baseline_2026-09-23.json`.

Toda corrección de ranking/search debe cerrar con nueva regresión contra la línea base congelada.

---

## 6. Cómo debe reanudar ChatGPT

Cuando se abra este proyecto desde otro dispositivo o desde la app de escritorio:

1. Abrir el proyecto **CPV - INVESTIGACIONES**.
2. Abrir esta conversación si está disponible; si se inicia un chat nuevo, indicar:
   **“Retoma CPV desde docs/orquestador/SESSION_RESUME.md; verifica HEAD, Issue #2 y estado vivo antes de actuar.”**
3. Leer solamente, en este orden:
   - este archivo;
   - `docs/orquestador/current_task.md`;
   - `audit/orchestrator_handoff.json`;
   - último comentario del orquestador en Issue #2.
4. Verificar el HEAD remoto de `feature/upgrade-filament-v3`.
5. Si se va a tocar taxonomía, hacer una lectura de solo consulta de los conteos de §3.
6. Sólo si hay discrepancias, reconstruir contexto desde comentarios anteriores.

No releer todo el Issue #2 por defecto.

---

## 7. Cómo debe reanudar el agente desarrollador

Al iniciar una nueva sesión del agente:

1. `git fetch origin`
2. cambiar a `feature/upgrade-filament-v3`
3. verificar que el working tree esté limpio
4. leer, en este orden:
   - `docs/orquestador/SESSION_RESUME.md`
   - `docs/orquestador/current_task.md`
   - `audit/orchestrator_handoff.json`
   - último comentario del orquestador en Issue #2
5. confirmar el HEAD remoto antes de modificar archivos
6. no reconstruir decisiones históricas si estos cuatro puntos son consistentes
7. si existe discrepancia entre documentación y repo/base viva, detenerse y reportarla
8. al terminar cualquier tarea:
   - dejar working tree limpio;
   - commit + push a la rama activa;
   - actualizar `current_task.md` y `orchestrator_handoff.json`;
   - nunca persistir tokens, contraseñas ni claves;
   - devolver únicamente `READY_FOR_REVIEW / Issue #2 / HEAD <sha>`.

---

## 8. Comentarios de gobierno clave

No hace falta leerlos todos al reanudar, pero son las referencias autoritativas:

- TASK-0007 owner APPLY authorization: `6032819854`
- TASK-0007 final closure: `6035642794`
- TASK-0008 open: `6035726459`
- TASK-0008 intern extension: `6035827197`
- TASK-0008 corrections: `6036293989`
- TASK-0008 final closure: `6041102746`

---

## 9. Regla maestra

**No confiar sólo en la memoria de una conversación o de un agente.**

La continuidad se establece con:
1. Git HEAD;
2. este checkpoint;
3. `current_task.md`;
4. `orchestrator_handoff.json`;
5. Issue #2;
6. estado vivo de Supabase cuando corresponda.

Esa combinación permite retomar el trabajo de forma rápida y auditable sin releer toda la historia.
