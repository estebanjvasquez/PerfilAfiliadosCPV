# PerfilAfiliadosCPV: arranque de sesión

Las instrucciones del usuario y del comentario del Issue #2 asignado tienen prioridad sobre este archivo.
Las reglas compartidas viven en **un solo lugar**: [`docs/orquestador/SESSION_RESUME.md`](docs/orquestador/SESSION_RESUME.md)
(corto, ≤8 KB). Este archivo no las repite.

Al empezar:

1. Leer `docs/orquestador/SESSION_RESUME.md` completo (§0 es el procedimiento de arranque).
2. Ver `git status -sb` y la rama y el HEAD actuales. No cambiar de rama, no hacer pull y no descartar cambios.
3. Leer completo, una vez, **solo** el comentario del Issue #2 que asigne el usuario (repo `estebanjvasquez/PerfilAfiliadosCPV`).
   De lo que cite, abrir solo las referencias directas que la tarea necesite. No recorrer comentarios en cadena.

Estos archivos de arranque viven en la rama de la tarea en curso (y en `docs/session-continuity`) hasta que se integren con una entrega autorizada
(`SESSION_RESUME.md` §1).

**No:**
- onboarding recursivo;
- leer completos el hilo del Issue #2, el historial (`docs/orquestador/history/`), `audit/**` o `audit/orchestrator_handoff.json`;
- loops automáticos, agentes o subagentes, sondeos;
- descubrir el entorno más allá de lo que la tarea necesite.

El protocolo autónomo está suspendido. El repo es **público**: aquí no se escribe ningún secreto.

## Canal de coordinación

Aplicar también `AGENTS.md` (sección «Coordinación y comunicación») y `docs/orquestador/PROTOCOL.md`. Toda asignación autorizada, entrega, revisión, corrección o decisión dirigida al desarrollador se registra preferentemente en [Issue #2](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2), con confirmación del enlace. No pedir al propietario que traslade mensajes si el orquestador puede publicarlos. Ninguna publicación equivale a ejecución ni habilita por sí sola una tarea posterior. Mantener el arranque acotado y los gates existentes.
