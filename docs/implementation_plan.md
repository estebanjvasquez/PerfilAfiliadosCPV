# Plan de implementación — PerfilAfiliadosCPV / Taxonomía CPV / CIRA

**Fecha de redacción:** 2026-09-23, tras la auditoría de completitud de Phase 3
(`audit/phase3_completion_audit.md`) y la reconstrucción de `docs/task.md`.

Este plan refleja el trabajo **real** que queda, verificado contra código y base de datos en vivo
— no reproduce planes históricos que puedan estar obsoletos. Se reescribe entre fases (ver
`docs/task.md`, sección "Header").

---

## Fase A — Verificación / estabilización (bloqueante para todo lo demás)

**Status (2026-09-23): PASS — CERRADA.** Ver `audit/phase3_runtime_validation.md` para el detalle
completo. El usuario autorizó explícitamente generar valores nuevos de `DEBUG_TOKEN`/`MCP_TOKEN`
(nunca se recuperó el valor anterior) tras confirmarse que no eran recuperables de ningún entorno
desplegado. Con eso: regresión ejecutada (32/32 OK, 0 `ENGINE FAILURE`), `/mcp` autenticado
verificado end-to-end contra Supabase real, Shield corregido (455→471, aditivo), smoke test
funcional completado hasta el máximo legítimo sin bypasear autenticación. Cero mutaciones de
Phase 3 confirmadas antes y después. **Fase B es ahora el próximo trabajo real.**

**Objetivo:** cerrar los huecos de verificación que quedaron `BLOCKED` por falta de credenciales,
antes de decidir cualquier trabajo de escritura sobre Phase 3.

**Dependencias:** ninguna — es el punto de partida.

**Tareas:**
1. Obtener `DEBUG_TOKEN` del Worker `perfilafiliados-mcp` (o un token de Cloudflare con permiso
   `Workers Scripts: Edit` para rotarlo) — desbloquea las tareas 2 y 3.
2. Correr `perfilafiliados-mcp/scripts/regression-suite.mjs` contra el Worker desplegado,
   guardar el resultado como línea base (`--save baseline.json`).
3. Verificar `/mcp` y `/debug-search` con una llamada real autenticada, confirmando el camino
   completo Worker → Hyperdrive → Supabase → evidencia → respuesta.
4. Correr el checklist funcional completo de `docs/migracion.md` en staging (login/Turnstile, alta
   de empresa, exports, perfil de Breezy) — no solo versiones/migraciones.
5. Verificar el conteo real de permisos de Filament Shield contra pgsql (brecha documentada:
   163 vs 285 esperados en `docs/migracion.md`).

**Tests:** la propia suite de regresión (tarea 2) es el test de aceptación de esta fase.

**Rollback/safety:** ninguna escritura en esta fase — es enteramente de verificación.

**Acceptance criteria:**
- [x] Regresión corrida y guardada como línea base — **32/32 OK**, `audit/regression_baseline_2026-09-23.json`
- [x] `/mcp`/`/debug-search` verificados con una llamada real — **200 en ambos, con datos reales de Supabase**
- [x] Checklist funcional de staging completo hasta el máximo legítimo sin bypasear auth (login,
      Turnstile, protección de panel — verificados; alta de empresa/exports/perfil requieren
      sesión real, quedan `BLOCKED` por diseño, no se bypasea autenticación para probarlos)
- [x] Conteo de permisos de Shield confirmado y **corregido**: 455 → 471 (aditivo, 0 duplicados).
      Las 3 páginas legacy (`page_CompletionView`, `page_SectorsView`, `page_GerenciaDashboard` —
      nombre real PascalCase, no snake_case) ahora tienen permiso generado y `super_admin` lo tiene

---

## Fase B — Completar el diseño de Phase 3 (Canonical Concept Builder)

**Status (2026-09-23): DONE (código + tests).** Ver `audit/phase3_phase_b.md`. Las 3 tareas de
abajo se implementaron: `proposeConceptRelations()`/`validateConceptRelationProposal()` (B1),
`predictAffectedCompanies()`/`predictedImpactForConcept()` (B2, adjunto a cada resultado de
`dryRun()` como `predicted_impact`), `resolveNewConceptProposal()`/`findPossibleDuplicateConcepts()`
(B3, camino nuevo, `approve()` existente intacto). Dry-run: 6/8 → 8/8. 24 tests nuevos, todos
dentro de `DatabaseTransactions`. Regresión re-corrida: sin cambios reales (1 falso positivo
transitorio explicado y confirmado resuelto). Cero mutaciones de producción.

**Pendiente real (no bloqueante para Fase C):** wiring de UI en Filament para que un revisor humano
use `resolveNewConceptProposal()` desde el panel (hoy solo la capa de servicio está lista/testeada).

**Objetivo:** cerrar los 2 gaps de diseño encontrados en la auditoría antes de tocar código de
escritura.

**Dependencias:** Fase A no es estrictamente necesaria para esta (es diseño, no requiere el
Worker en vivo), pero es más seguro tenerla completa primero.

**Tareas:**
1. Diseñar el campo "relación concepto↔concepto propuesta" para `dryRun()` — decidir si
   `CanonicalConceptBuilderService` debe empezar a proponer instancias de
   `taxonomy_concept_relations` (usando el catálogo ya seedeado de 5 tipos), o si eso queda para
   una fase posterior separada.
2. Diseñar el campo "empresas afectadas predichas" para `dryRun()` — probablemente reutilizando
   lógica ya existente del lado del Worker TS (`countDirectCompanies`/evidence aggregation), no
   reinventándola en PHP.
3. Diseñar e implementar el camino de aprobación para "proponer concepto nuevo"
   (`suggested_concept_id === null`) en `CandidateConceptApprovalService` — hoy retorna
   `NOT_SUPPORTED_PROPOSE_NEW_CONCEPT`.

**Tests:** extender `CandidateConceptApprovalServiceTest.php` con casos de "proponer concepto
nuevo"; extender el corpus de validación (`ValidationCorpusTest.php`) si el scoring cambia.

**Rollback/safety:** cambios de código puro, sin escritura de datos de producción — bajo riesgo.

**Acceptance criteria:**
- [ ] `dryRun()` expone los 8 campos esperados (hoy expone 6)
- [ ] "Proponer concepto nuevo" tiene un camino de aprobación real, testeado

---

## Fase C — Diseñar e implementar `--apply` (write-mode real)

**Objetivo:** implementar desde cero el modo de escritura de
`taxonomy:build-canonical-concepts --apply` — hoy es un guard clause sin código detrás.

**Dependencias:** Fase A (línea base de regresión) y Fase B (dry-run completo) deben estar
cerradas antes de empezar esto.

**Tareas:**
1. Transacciones por lote (no una transacción gigante para toda la corrida).
2. Idempotencia (reusar/saltar si ya existe una fila equivalente, mismo patrón que
   `CandidateConceptApprovalService`).
3. Protección de duplicados (constraints únicos + `lockForUpdate()` donde corresponda).
4. Audit logging vía `TaxonomyAuditLogger` (mecanismo ya establecido, reusar, no crear uno
   paralelo).
5. Provenance explícito por fila escrita (qué corrida, qué señales, cuándo).
6. Versionado — decidir el mecanismo (columna de versión, o snapshot completo antes/después).
7. Rollback documentado y probado (no solo "en teoría revertible").

**Tests:** tests de integración con `DatabaseTransactions`, casos de: escritura exitosa,
escritura duplicada (debe ser no-op), escritura con conflicto (debe rechazar o marcar para
revisión, nunca sobreescribir silenciosamente), rollback real ejecutado y verificado.

**Rollback/safety:** ESTA es la fase de mayor riesgo de todo el plan. Ningún `--apply` real corre
contra producción hasta que el conteo de `taxonomy_term_cpv_relations` (línea base: 9.749) se
verifique sin pérdida antes/después en un entorno de prueba primero.

**Acceptance criteria:**
- [ ] Los 7 ítems de seguridad de escritura tienen evidencia de test, no solo de diseño
- [ ] Corrida de prueba contra un snapshot de staging, con conteos antes/después verificados
- [ ] Documentación de rollback probada al menos una vez en staging

---

## Fase D — Población controlada (primera corrida real)

**Objetivo:** primera población real de `taxonomy_concept_relations`/
`taxonomy_candidate_concept_links`, con supervisión humana en cada paso — nunca bulk-apply sin
revisión.

**Dependencias:** Fases A, B, C completas y con autorización explícita del usuario para tocar
datos de producción (no implícita por completar las fases anteriores).

**Tareas:**
1. Correr `dryRun()` completo contra producción (solo lectura).
2. Revisión humana de los candidatos generados (usando los campos completados en Fase B).
3. Aprobación manual vía `CandidateConceptApprovalService` (camino ya existente y seguro) para un
   lote piloto pequeño — NO `--apply` masivo en la primera corrida.
4. Verificar conteos antes/después, sin sorpresas.
5. Recién después de un piloto exitoso, evaluar si `--apply` en lote tiene sentido para el resto.

**Tests:** conteo de relaciones antes/después (debe ser estrictamente aditivo); regresión de
búsqueda corrida de nuevo después del piloto (comparar contra la línea base de la Fase A).

**Rollback/safety:** backup de las tablas de taxonomía antes de cualquier corrida piloto.

**Acceptance criteria:**
- [ ] Piloto aprobado manualmente, sin usar `--apply` masivo
- [ ] Conteos verificados sin pérdida
- [ ] Regresión sin degradación respecto a la línea base

---

## Fase E — Crawler (contenido web de empresas)

**Objetivo:** implementar el crawler que alimenta `WEB CONTENT → CAPABILITY EVIDENCE →
TERM/CONCEPT RESOLUTION`, distinto del crawler de términos ya existente (TAXV2-12).

**Dependencias:** Fase D debe estar en curso o completa — no tiene sentido crawlear evidencia para
alimentar un pipeline de conceptos que todavía no sabe escribir de forma segura.

**Tareas:** por definir — depende de decisiones de producto no tomadas aún (qué fuentes
crawlear, con qué frecuencia, qué constituye "evidencia de capacidad" verificable).

**Acceptance criteria:** por definir junto con el diseño.

---

## Fase F — Integración crawler → taxonomía

**Objetivo:** conectar la evidencia del crawler (Fase E) al pipeline de resolución de
términos/conceptos, reusando la cola de revisión existente (`taxonomy_candidate_terms` para
términos nuevos, `taxonomy_candidate_concept_links` para vínculos) — no crear una cola paralela.

**Dependencias:** Fase E.

---

## Fase G — Retrieval híbrido (refinamiento)

**Objetivo:** una vez poblado el grafo de conceptos (Fase D), evaluar si el retrieval híbrido
(`hybrid-search.ts`) se beneficia de nuevas señales basadas en relaciones concepto↔concepto
tipadas (hoy no las consume, porque no existen datos).

**Dependencias:** Fase D con datos reales poblados.

---

## Fase H — RRF / re-ranking (ajuste)

**Objetivo:** recalibrar pesos de RRF (`LIST_WEIGHTS` en `hybrid-search.ts`) si la Fase G agrega
nuevas señales de evidencia basadas en el grafo de conceptos.

**Dependencias:** Fase G.

---

## Fase I — Benchmark / gate de producción

**Objetivo:** antes de considerar cualquier cambio de Fases D-H como apto para el tráfico real de
producción, correr la suite de regresión completa + benchmark de latencia, con umbrales explícitos
de aceptación acordados con el cliente.

**Dependencias:** todas las fases anteriores.

---

## Nota sobre alcance de este plan

Las Fases E-I son deliberadamente de bajo detalle — dependen de decisiones de producto y de los
resultados de las Fases A-D, que son las que tienen trabajo concreto y verificable pendiente hoy.
No expandir E-I con tareas específicas hasta que D esté en curso, para no fijar un plan detallado
sobre supuestos que probablemente cambien.
