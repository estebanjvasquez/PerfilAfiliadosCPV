# Tarea activa

**TASK-0005** — UI de revisión humana C2 en Filament + hardening del trigger de despliegue
(Issue #2 comentario `5914793857`). Abierta desde HEAD `63cf811`.

**Estado: CLOSED / APPROVED** (comentario `5928773263`, HEAD revisado
`dbd410a3b5603bba8acc48091ebb0601f411f3bd`). Ambas correcciones del re-audit `5917275454` quedaron
`PASS`; evidencia de tests y despliegue aceptada; invariantes y gates heredados confirmados sin
invalidar.

**STANDING_BY — nada pendiente de esta sesión.** La UI de revisión humana queda técnicamente lista
para una revisión controlada de la cola real, pero esa revisión **NO está autorizada todavía**. Según
el comentario de cierre y la instrucción explícita del usuario en esta ronda:

- **Cola real: NO AUTORIZADA.** No procesar, congelar, rechazar ni resolver contexto sobre los 10
  candidatos ni las 2 relaciones reales.
- **APPLY / publicación: NO AUTORIZADO.**
- **Producción / merge a `main`: NO AUTORIZADO.**
- Esperar la apertura formal de la siguiente fase por instrucción explícita del orquestador.

## Riesgo adyacente registrado para una tarea futura (bloqueante antes de producción)

El comentario de cierre confirmó la observación que esta sesión registró por cuenta propia y le puso
un gate explícito: editar endpoints/tipo de una relación ya `approved` puede mutar taxonomía
publicada, porque el guard de `TaxonomyConceptRelation::booted()` solo revalida cuando el status
**pasa a** `approved` (`isDirty('status')`). Es **preexistente** y quedó fuera de las dos correcciones
pedidas, así que no bloqueó el cierre de TASK-0005 — pero el orquestador indicó que **DEBE**
resolverse antes de habilitar edición administrativa general de relaciones publicadas o antes de
cualquier rollout a producción de esta UI de gobernanza de taxonomía. No implementado en esta ronda:
no fue pedido y haría falta una tarea propia.

## Historial del re-audit `5917275454` (ambas correcciones, ahora PASS)

Los dos hallazgos corregidos fueron:

1. **Fuga de autorización entre tipos de origen** en las propuestas revisadas: la policy usaba OR,
   así que quien veía candidatos podía abrir propuestas de relación y viceversa, y el listado no
   estaba filtrado. Ahora `view()` resuelve por `proposal_type` y `getEloquentQuery()` filtra las
   filas por los tipos que el usuario puede ver (lo que también cierra la URL directa, porque
   `ViewRecord` resuelve contra ese mismo query). Super-admin sin bypass, por el modelo de permisos
   normal.
2. **Edit/Delete legacy sobre relaciones en revisión C2**: un revisor podía mutar o borrar una
   relación candidata al margen del ciclo inmutable, destruyendo evidencia en lugar de producir un
   REJECT congelado. Nuevo predicado `isUnderC2Review()` usado por `canEdit()`/`canDelete()` (cierra
   acción **y** ruta `/edit` con 403) más un guard `deleting` a nivel de modelo. El CRUD
   administrativo se conserva acotado a filas cuyo ciclo ya terminó.

**Tests del re-audit: 138 passed, 1 failed** (el fallo es el gap local preexistente de `ext-intl`,
verde en staging). Incluye 4 tests preexistentes de `TaxonomyConceptRelationValidationTest`
actualizados porque codificaban el comportamiento que la corrección 2 pidió cerrar — cada uno
preservando la propiedad que protege, documentado en el archivo. Invariantes 10/2/142/79/9749/0 sin
cambios.

Detalle completo de ambas correcciones en la sección 10 del audit. Estado de la ronda 1
(implementación, hardening del workflow, despliegue y smoke iniciales) en
[`audit/phase5_task0005_c2_review_ui_2026-09-30.md`](../../audit/phase5_task0005_c2_review_ui_2026-09-30.md);
texto verbatim de la tarea en
[`tasks/0005-c2-human-review-ui.md`](tasks/0005-c2-human-review-ui.md).

Resumen de lo entregado:

- **A/B — UI de freeze:** una acción única `freezeReview` por resource (candidatos: `MAP_TO_EXISTING`
  / `CREATE_NEW` / `CONTEXT_REQUIRED` / `REJECT`; relaciones: `PUBLISH_RELATION` / `REJECT`), cableada
  exclusivamente a `ReviewedProposalService::freeze()`. Cada decisión exige input humano explícito;
  `CREATE_NEW` no tiene fallback implícito al nombre sugerido por el Builder (la sugerencia se muestra
  solo como evidencia). Las acciones legacy `approve`/`reject`/`resolveNewConcept` fueron removidas.
- **C — visibilidad:** nuevo `TaxonomyReviewedProposalResource` de solo lectura (List + View, sin
  páginas de create/edit/delete) con origen, decisión, revisor, timestamps, referencia de
  autorización, entorno objetivo, fingerprints y estado de ejecución.
- **D — frontera de autorización:** cero botón/ruta de Apply/Publish alcanzable por un revisor.
  `apply()` sigue siendo un paso separado, solo por servicio/artisan.
- **E — guardas legacy intactas:** ninguna guarda de TASK-0004 fue debilitada;
  `CandidateConceptApprovalService` sigue sin poder publicar rodeando C2.
- **F/H — tests:** 24/25 en la UI nueva (el único fallo es el gap local preexistente de `ext-intl`,
  verde en staging) + 99/99 sin regresiones en los archivos relacionados no editados. Todo con
  fixtures desechables dentro de `DatabaseTransactions`, en entorno local seguro — **no** se reusó el
  procedimiento de contenedor efímero sobre los bind mounts de staging en vivo.
- **G — hardening del deploy:** `paths-ignore` (`docs/**`, `audit/**`, `**.md`) en
  `deploy-contabo.yml`, con la semántica todo-o-nada y los casos representativos documentados en el
  propio workflow. `tests/**` NO se ignora, por decisión deliberada y conservadora.
- **Invariantes:** 10/2/142/79/9749/0 verificados antes de empezar, después de los tests y después del
  despliegue. **Los 10 candidatos y 2 relaciones reales quedaron intactos** — ningún
  freeze/apply/reject/context-resolve corrió contra ellos.

Ver `PROTOCOL.md` antes de tocar esta tarea. Precondiciones verificadas: Phase C1 (TASK-0001 +
TASK-0003) sigue `APPROVED` (comentario `5886125405`, HEAD revisado `ce11d36`); TASK-0004 / Phase C2
sigue `CLOSED/APPROVED` con despliegue de staging `PASS` (comentarios `5914592664` / `5914676402`).

## TASK-0004 (cerrada, histórico)

**Phase C2: Reviewed Immutable Payload Application** (Issue #2 comentario `5886148283`).
**Implementación C2: CLOSED/APPROVED. Despliegue a staging + validación: PASS** (comentario
`5914592664`, confirmando la ronda 6; HEAD `63cf811` aceptado como checkpoint documental en
`5914676402`).

Comentarios del hilo completo (todos con texto verbatim en
[`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md)):
`5890113782`, `5890195271`, `5892711739`, `5909267134`, `5913324183`, `5913574545`, `5914592664`.

## Ronda 7 (comentario `5914592664`) — confirmación, sin cambios de código

El comentario confirma `PASS` para el despliegue/validación de staging de la ronda 6 (ningún cambio
adicional de código ni de evidencia requerido) y dos instrucciones para más adelante, ninguna abierta
todavía:

1. **Hardening operacional del procedimiento de test-en-staging** (no reabre TASK-0004): antes de
   volver a correr la suite completa contra el host compartido de staging, hace falta un camino de
   ejecución de tests que no pueda escribir en los bind mounts de la app en vivo (`bootstrap/cache`,
   `storage`, etc.), forzar `APP_ENV=testing` a nivel de proceso/contenedor, no correr scripts de
   `package:discover` contra el filesystem del contenedor que sirve tráfico, y agregar guardas de
   smoke-test antes/después + limpieza automática de contenedores efímeros. **No implementado en esta
   ronda** - es trabajo operacional para cuando se vuelva a necesitar correr tests en staging, no algo
   pedido para ejecutar ahora.
2. **Próxima fase funcional (wiring de la UI de revisión humana C2 en Filament):** el propio
   comentario dice explícitamente que solo se abre "awaiting/opened only by explicit orchestrator
   instruction" - **todavía no está abierta**. El usuario, en su mensaje de esta ronda, instruyó
   exactamente lo mismo: detenerse y esperar esa apertura formal, sin actuar sobre los 10
   candidatos/2 relaciones reales.

**Ninguna acción de código, despliegue, ni de servidor se tomó en esta ronda** - es puramente de
reconocimiento/registro del comentario y actualización de este documento.

## Resumen de la ronda 6 (comentario `5913574545`) — despliegue a staging + validación

Detalle completo (checkpoint pre-despliegue, despliegue, validación 1-9, incidente y corrección,
análisis de los 3 fallos): `audit/phase5_staging_deployment_2026-09-30.md`.

- **Despliegue:** ya había ocurrido automáticamente vía el workflow existente de GitHub Actions
  (dispara en cada push a `feature/upgrade-filament-v3`) - verificado que el run para HEAD `4f1b02e`
  completó con éxito antes de iniciar cualquier validación.
- **Checkpoint pre-despliegue:** todos los invariantes de DB coincidieron exactamente (10/2/142/79/
  9749/0), sin migraciones pendientes, target de DB confirmado como la misma instancia compartida de
  Supabase, `ext-intl` confirmado cargado en el servidor.
- **Incidente real (causado y corregido en esa ronda):** un contenedor efímero de pruebas (necesario
  para tener PHPUnit disponible, ausente en la imagen `--no-dev` de staging) sobrescribió, vía un
  bind mount compartido (`bootstrap/cache/`), la caché de auto-discovery de paquetes del contenedor
  REAL que sirve tráfico - staging quedó caído (HTTP 500) unos minutos. Diagnosticado y corregido de
  inmediato (regenerar la caché desde el propio `vendor/` del contenedor real) - el usuario fue
  informado de forma transparente e inmediata y autorizó explícitamente la corrección antes de que se
  ejecutara. Staging restaurado y verificado en HTTP 200. Ningún código, dato, ni fila real fue
  tocado por el incidente ni su corrección. El comentario `5914592664` confirmó que esto NO invalida
  la implementación C2, pero exige el hardening operacional listado arriba antes de reutilizar el
  procedimiento.
- **Suite completa de taxonomía en el servidor real (`ext-intl`, HEAD `4f1b02e`): 188/191 PASS (605
  assertions), 275.45s.** El test bloqueado en TODAS las rondas anteriores por el gap de `ext-intl`
  ahora pasa limpio. `ReviewedProposalServiceTest` (evidencia C2 directa): **41/41 PASS**, sin
  ninguna excepción.
- **Los 3 fallos restantes** tienen causa raíz precisa e identificada (precedencia de `APP_ENV` entre
  la variable de entorno real del contenedor y el `<env>` no forzado de `phpunit.xml`) - ninguno es
  una regresión de C2/taxonomía, los 3 están en archivos de TASK-0003 no tocados por ninguna ronda de
  TASK-0004. El comentario `5914592664` confirmó explícitamente esta lectura.
- **Invariantes de DB después de toda la suite: sin cambios** (10/2/142/79/9749/0) - los 10
  candidatos/2 relaciones reales permanecieron intactos durante todo el despliegue y validación.
- **Búsqueda:** re-verificado sobre el código REALMENTE DESPLEGADO que `BuildEmpresaSearchDocuments`
  no lee ninguna tabla de candidatos/propuestas. Worker verificado accesible (health check público,
  sin tokens). No se re-corrió la regresión de 32 queries (heredada, no invalidada).

## Tareas anteriores (histórico, no activas)

| Tarea | Estado | Archivo |
|---|---|---|
| TASK-0001 | APPROVED | (Fase C1, sin archivo de tarea propio - ver `audit/phase3_phase_c_apply.md`) |
| TASK-0002 | APPROVED | [`tasks/0002-review-503.md`](tasks/0002-review-503.md) |
| TASK-0003 | APPROVED | [`tasks/0003-phase-c-corrections.md`](tasks/0003-phase-c-corrections.md) |
| TASK-0004 (ronda 1) | CORRECTIONS_REQUIRED | [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md) |
| TASK-0004 (ronda 2, correcciones) | CORRECTIONS_REQUIRED (narrow, ronda 3) | mismo archivo, sección "Re-audit" |
| TASK-0004 (ronda 3, correcciones A/B/C) | CORRECTIONS_REQUIRED (final semantic defects, ronda 4) | mismo archivo, sección "Re-audit — comentario `5892711739`" |
| TASK-0004 (ronda 4, defectos 1/2) | IMPLEMENTATION PASS / ENVIRONMENT_GATE_PENDING (ronda 5) | mismo archivo, sección "Re-audit — comentario `5909267134`" |
| TASK-0004 (ronda 5, evidencia de entorno) | DEPLOYMENT AUTHORIZED (ronda 6) | mismo archivo, sección "Re-audit — comentario `5913324183`" |
| TASK-0004 (ronda 6, despliegue a staging + validación) | PASS WITH FOLLOW-UP HARDENING (ronda 7) | mismo archivo, sección "Autorización de despliegue — comentario `5913574545`"; detalle completo en `audit/phase5_staging_deployment_2026-09-30.md` |
| TASK-0004 (ronda 7, confirmación PASS) | STANDING_BY | mismo archivo, sección "Confirmación de despliegue + hardening pendiente — comentario `5914592664`" |
| TASK-0004 (ronda 8, checkpoint documental `63cf811`) | CLOSED | mismo archivo; sin acción de código (el comentario `5914676402` instruyó esperar la apertura formal de TASK-0005) |
| TASK-0005 (ronda 1, implementación + hardening + staging) | CORRECTIONS_REQUIRED (ronda 2) | [`tasks/0005-c2-human-review-ui.md`](tasks/0005-c2-human-review-ui.md); detalle en `audit/phase5_task0005_c2_review_ui_2026-09-30.md` |
| TASK-0005 (ronda 2, correcciones 1 y 2) | **CLOSED / APPROVED** (comentario `5928773263`) | mismo archivo, sección "Re-audit — comentario `5917275454`"; detalle en la sección 10 del audit |
