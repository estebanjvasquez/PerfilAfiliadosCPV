# PerfilAfiliadosCPV: instrucciones para agentes

Mismas reglas que `CLAUDE.md`. La única fuente es
[`docs/orquestador/SESSION_RESUME.md`](docs/orquestador/SESSION_RESUME.md), y prevalecen las instrucciones
del usuario y del comentario del Issue #2 asignado.

1. Leer `docs/orquestador/SESSION_RESUME.md` completo.
2. Ver `git status -sb`, la rama y el HEAD. No cambiar de rama ni descartar cambios.
3. Leer completo, una vez, solo el comentario del Issue #2 asignado. De lo que cite, abrir solo las referencias
   directas necesarias. No recorrer comentarios en cadena.

Rama canónica de estos archivos: la de la tarea en curso (`SESSION_RESUME.md` §1).

**No:**
- onboarding recursivo;
- leer completos el hilo, el historial, `audit/**` o el handoff JSON;
- loops, agentes o sondeos automáticos;
- descubrir el entorno sin que la tarea lo necesite;
- poner secretos en este repo público.
