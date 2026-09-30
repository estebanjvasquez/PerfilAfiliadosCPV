# Tarea activa

**TASK-0004** — Phase C2: Reviewed Immutable Payload Application (Issue #2 comentario `5886148283`).
**Implementación C2: CLOSED/APPROVED. Despliegue a staging + validación: PASS** (comentario
`5914592664`, confirmando la ronda 6).

**Estado:** STANDING_BY — nada pendiente de esta sesión; esperando la apertura formal de la
siguiente fase funcional (wiring de la UI de revisión humana C2 en Filament) por instrucción
explícita del orquestador. Esta sesión NO debe actuar sobre los 10 candidatos/2 relaciones reales, ni
volver a correr la suite completa de tests contra los bind mounts del contenedor de staging en vivo,
hasta que esa apertura formal ocurra.

Comentarios del hilo completo (todos con texto verbatim en
[`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md)):
`5890113782`, `5890195271`, `5892711739`, `5909267134`, `5913324183`, `5913574545`, `5914592664`.

Ver `PROTOCOL.md` antes de tocar esta tarea. Precondición verificada: Phase C1 (TASK-0001 +
TASK-0003) sigue `APPROVED` (comentario `5886125405`, HEAD revisado `ce11d36`).

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
