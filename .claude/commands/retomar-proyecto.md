---
description: Retoma el trabajo en PerfilAfiliadosCPV con el arranque corto (SESSION_RESUME.md §0); no cambia de rama ni sincroniza nada
---

Retomá el contexto de PerfilAfiliadosCPV **sin** programar todavía. Este comando es solo de orientación.

> Reemplaza la versión anterior (hasta 2026-10-16, `6210552318`). Esa versión mandaba `git checkout staging` y
> `git pull`, y pedía leer `docs/task.md` completo y planes de otra máquina. Está en el historial de git.

1. Leé `docs/orquestador/SESSION_RESUME.md` completo y seguí su §0. No leas el historial, `audit/**` ni el
   hilo completo del Issue #2.
2. Corré `git status -sb`, `git branch --show-current` y `git rev-parse HEAD`. **No** cambies de rama, no hagas
   pull y no descartes cambios: si hay cambios sin commitear, reportalos tal cual.
3. Si el usuario indicó un comentario del Issue #2, leelo completo y solo ese.
4. Resumí en pocas líneas la rama, el HEAD, la tarea en curso y la siguiente acción exacta según el checkpoint,
   y esperá instrucciones.
