# Tarea activa

**TASK-0004** — Phase C2: Reviewed Immutable Payload Application (Issue #2 comentario `5886148283`),
**ronda 5: evidencia de entorno** (comentarios `5890113782` + `5890195271` + `5892711739` +
`5909267134` + `5913324183`)

Archivo: [`tasks/0004-phase-c2-immutable-apply.md`](tasks/0004-phase-c2-immutable-apply.md) (incluye
el texto verbatim de los cinco comentarios del re-audit)

**Estado:** READY_FOR_REVIEW — `TASK-0004 C2 IMPLEMENTATION = PASS; FINAL CLOSURE =
ENVIRONMENT_GATE_PENDING` (per el propio comentario `5913324183`)

Ver `PROTOCOL.md` antes de tocar esta tarea. Precondición verificada: Phase C1 (TASK-0001 +
TASK-0003) sigue `APPROVED` (comentario `5886125405`, HEAD revisado `ce11d36`) — no invalidada por
esta ronda.

## Qué pidió el re-audit (ronda 5, comentario `5913324183`) y qué se hizo

El comentario confirmó explícitamente que la implementación C2 está en `PASS` completo - los 2
defectos semánticos de la ronda 4 (CREATE_NEW reviewed-vs-suggested, CONTEXT_REQUIRED term_id drift)
están correctamente cerrados, y ninguna corrección anterior (HIGH-1/HIGH-2/A/B/C/las de ronda 4) se
reabrió. Lo único pendiente para el cierre final: **evidencia limpia de la suite completa de
taxonomía en un entorno válido/CI, sin cambiar la semántica C2.**

**Ningún cambio de código se hizo esta ronda** - es puramente investigación de entorno y
documentación, según la decisión explícita del usuario (ver abajo).

### Investigación de entorno

Se investigó la causa raíz del bloqueo de Docker Desktop que viene repitiéndose desde la ronda 2
(motor inalcanzable/error 500). Encontrado con `wsl --status`:

```
WSL2 is unable to start since virtualisation is not enabled on this machine.
Please ensure the "Virtual Machine Platform" optional component is enabled and
virtualisation is turned on in your computer's firmware settings.
```

**La virtualización de hardware está deshabilitada a nivel de firmware/BIOS de esta máquina** - eso
bloquea POR IGUAL a Docker Desktop (necesita WSL2 o Hyper-V para su motor) y a WSL2 directo (que
tampoco tiene ninguna distribución instalada). No es corregible desde esta sesión ni con privilegios
de administrador de Windows - requiere acceso físico al firmware/BIOS de la máquina y un reinicio.
Detalle completo en `audit/phase4_c2_corrections_2026-09-29.md`, sección "Ronda 5".

### Camino alternativo evaluado (no ejecutado)

El propio comentario `5913324183` autoriza explícitamente agregar configuración de CI/entorno (sin
tocar semántica C2) como camino de cierre - un workflow de GitHub Actions (PHP con `intl` en
`ubuntu-latest`) resolvería el gap de `ext-intl` Y el bloqueo local de una sola vez, corriendo contra
la misma instancia compartida de Supabase. Eso requeriría agregar las credenciales de `.env` como
secretos nuevos del repositorio de GitHub - una decisión sobre configuración/credenciales compartidas
de CI/CD que esta sesión presentó al usuario en vez de ejecutar unilateralmente.

### Decisión del usuario

Presentadas 3 opciones (configurar CI de GitHub Actions con secretos nuevos; que el usuario habilite
virtualización en firmware/BIOS y se reintente Docker; o documentar el hallazgo y detenerse), **el
usuario eligió explícitamente la tercera: reportar el estado actual y detenerse.** No se configuró
CI, no se tocó ningún secreto del repositorio, no se hizo ningún cambio adicional de entorno más allá
del diagnóstico de arriba.

## Evidencia (sin cambios de código esta ronda - se reafirma lo ya reportado en rondas 3/4)

- **[A] `ReviewedProposalServiceTest`: 41/41 PASS (120 assertions)** — evidencia de la ronda 4,
  directamente relevante a los 2 defectos que este comentario confirmó cerrados. No re-corrida esta
  ronda (sin cambios de código que la afecten).
- **[A] Suite de taxonomía completa: 187/189 PASS (595 assertions), 5619.25s** — evidencia de la
  ronda 3 (HEAD `835fdae`), sigue siendo la última corrida real. Sigue bloqueada por el mismo gate de
  entorno (gap de `ext-intl`), ahora con causa raíz precisa (virtualización de firmware deshabilitada,
  no solo "Docker no arranca").
- **[A] DB invariants**: `taxonomy_candidate_concept_links=10`, `taxonomy_concept_relations=2`,
  `taxonomy_term_concepts=142`, `taxonomy_canonical_concepts=79`, `taxonomy_term_cpv_relations=9749`,
  `taxonomy_reviewed_proposals=0` — sin cambios (ningún candidato/relación real tocado; ningún test
  nuevo corrido esta ronda).
- **[A] Regresión de 32 queries**: heredada de `ce11d36`/comentario `5886125405`, no invalidada — el
  propio comentario confirma explícitamente que no hace falta re-correrla ("Current diff does not
  modify live search/ranking/published taxonomy, so rerun is NOT required").
- **[A] Phase C1, TASK-0002**: `APPROVED`, no tocadas.
- **[D] Migraciones de esquema**: ninguna - ronda sin cambios de código.

## Nota de entorno (actualizada esta ronda con causa raíz)

**Causa raíz identificada:** virtualización de hardware deshabilitada en firmware/BIOS - bloquea
Docker Desktop (necesita WSL2/Hyper-V) y WSL2 directo por igual. Confirmado con `wsl --status`
("virtualisation is not enabled on this machine... enabled in your computer's firmware settings") y
`wsl --list --verbose` (sin distribuciones instaladas). No corregible desde esta sesión - requiere
acceso físico al firmware de la máquina. El gate de suite completa permanece
`ENVIRONMENT_BLOCKED` (per el propio comentario `5913324183`), con la última evidencia real siendo
187/189 PASS (ronda 3). Camino de cierre disponible pero no ejecutado (decisión del usuario): CI de
GitHub Actions con secretos de DB nuevos - ver sección de arriba.

## Fuera de alcance de esta ronda (documentado, no oculto)

- Ningún cambio de código C2 - el comentario no pidió ninguno, solo evidencia de entorno.
- Configuración de CI de GitHub Actions - evaluada y presentada al usuario, no ejecutada por
  decisión explícita suya.
- Cualquier cambio de firmware/BIOS - fuera del alcance de esta sesión por completo (requiere acceso
  físico a la máquina).
- Ninguna aplicación real autorizada contra los 10 candidatos/2 relaciones ni contra ningún dato
  compartido/producción.

**STOP.** No se llamó `freeze()`/`apply()` contra ningún candidato/relación real, no se tocaron los
10 candidatos/2 relaciones de TASK-0001, no se mergeó a `main`, no se tocó ningún secreto/configuración
de CI. La decisión de cierre final queda en manos del orquestador/usuario.

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
| TASK-0004 (ronda 5, evidencia de entorno) | READY_FOR_REVIEW | mismo archivo, sección "Re-audit — comentario `5913324183`" |
