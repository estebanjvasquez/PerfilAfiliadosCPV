# Correcciones del orquestador

Autor: orquestador ChatGPT. Fecha: 2026-10-09.
Base: feature/upgrade-filament-v3 @ 99542c9072a00074b5d2c94c9607e089afa14691.
No modifica archivos del agente ni autoriza TASK-0010.

## C1 — Permiso del sandbox contradictorio
[DOCUMENTADO] ACCESS_BOOTSTRAP.md §5.0 dice que el token dedicado resolvió escritura SIN regla adicional del sandbox; §5.2 todavía exige esa regla. Reconciliar §5.2 con el resultado real. Distinguir PAT clásico (scopes) de token con permisos específicos (Issues: Read and write) según el token provisionado.

## C2 — Detección de revisión pendiente incompleta
[DOCUMENTADO] ACCESS_BOOTSTRAP.md §6.1 paso 4 omite el caso review_head igual al HEAD remoto SIN veredicto, que sí requiere revisión.
[INFERIDO — corrección requerida] Detectar por TASK + review_head y ausencia de veredicto válido posterior a review_requested_at. Verificar pertenencia a rama/alcance. Ante un HEAD posterior, clasificar el delta: un cambio sólo documental no invalida automáticamente evidencia de runtime.

## C3 — Retransmisión manual obsoleta
[DOCUMENTADO] SESSION_RESUME.md §4 conserva “usuario pasa al agente ... comentario”, contradiciendo §6/§8 y AUTONOMOUS_DEV_LOOP.md. Sustituir por lectura directa del Issue por el agente.

## C4 — Índice
[DOCUMENTADO] README.md describe ORCHESTRATOR_KNOWLEDGE.md como plantilla pendiente. Fue completado en 9744b7acff3436298acf66ff682e698180727ad0. El agente actualiza su índice.

## Aceptación
[INFERIDO — criterio de revisión] Commit sólo documental en rama autorizada, referencias coherentes, sin secretos, runtime, workflow, datos ni inicio de TASK-0010A/B. Publicar nuevo READY_FOR_REVIEW y leer revisión directamente de Issue #2.
