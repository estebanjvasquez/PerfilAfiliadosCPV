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

## Coordinación y comunicación (regla operativa)

- **Issue #2 es el canal preferente y persistente** para asignaciones autorizadas, entregas, revisiones, correcciones y decisiones entre el orquestador y el desarrollador. Un mensaje de chat no sustituye su registro.
- Antes de actuar, identificar el comentario asignado y comprobar estado vigente, rama, HEAD y permisos; no interpretar un SHA del checkpoint como HEAD vivo. Consultar `docs/orquestador/PROTOCOL.md` y `current_task.md` **solo en la medida necesaria** para esa tarea.
- El **orquestador**, si tiene conexión y autorización, publica directamente el comentario y verifica el enlace confirmado; si falla, informa el error concreto. No exige al propietario trasladar mensajes manualmente cuando puede publicarlos.
- El **desarrollador** no empieza trabajo nuevo sin asignación explícita, materializa la tarea conforme al protocolo y publica su entrega en Issue #2. Si la publicación falla, registra `BLOCKED` sin simular entrega.
- Publicar una instrucción **no demuestra recepción ni ejecución**. `READY_FOR_REVIEW` no autoriza continuar a la siguiente tarea, salvo que una asignación explícita vigente defina fases y gates concretos.
- Los permisos y las prohibiciones de `PROTOCOL.md` prevalecen: sin merges, despliegues, taxonomía, operaciones destructivas, cambios de credenciales o secretos sin autorización específica.
- Esta regla no activa loops, polling, agentes autónomos ni lectura masiva del hilo; leer solo el comentario asignado y referencias directas. Las automatizaciones autorizadas por separado no alteran los gates.
