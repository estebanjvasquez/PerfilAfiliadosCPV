# CPV-SESSION-CONTINUITY: arranque acotado e inventario de ejecutores

- **Instrucción:** Issue #2 `6210552318` (propietario, 2026-10-16 07:38Z).
- **Rama:** `docs/session-continuity`, que sale de `0f8bdad` (REVIEW_HEAD de CPV-HOME-BRANDING-AND-LAYOUT). Así el checkpoint conserva el estado del producto. Solo documentación y scripts de documentación.
- **Desplegado:** NO.
- **Hecho en:** una sola sesión en primer plano. Sin subagentes, sin revisiones de IA, sin sondeos. No se instaló nada, no se cambió el PATH y no se tocaron credenciales ni permisos.

## 1. Qué carga realmente la herramienta (Claude Code, equipo Windows actual)

| Fuente | ¿Se carga sola? | Hallazgo |
|---|---|---|
| `CLAUDE.md` en la raíz y en los directorios ancestros | sí | **no existía** ninguno, ni en la raíz, ni en `C:\Proyectos`, `C:\Proyectos\GitHub`, `C:\` ni en el perfil del usuario. Ahora existe en la raíz |
| Memoria privada del ejecutor (`MEMORY.md`) | sí | 1.097 B, local y fuera de git. Es solo un índice |
| `.claude/settings.json` | sí, como **permisos** (no como instrucciones) | 11.939 B. Incluye reglas `python …`, `python -m pip install …`, `C:\xampp\php\…` y rutas `C:/Users/EstebanVasquez/…/OneDrive…` de **otra máquina y otra cuenta**. Son inertes aquí y no prueban que una herramienta exista hoy |
| `.claude/commands/*.md` (comandos del proyecto) | se listan como comandos invocables | `retomar-proyecto` mandaba `git checkout staging` + `git pull`, leer `docs/task.md` completo y planes de `C:\Users\EstebanVasquez\…`. Era una ruta de arranque **contradictoria y riesgosa**. Se reescribió. `cerrar-proyecto` lleva ahora una nota que apunta al checkpoint |
| `AGENTS.md` | lo leen otros agentes (Codex, entre otros) | no existía. Ahora existe |

No se escanearon transcripciones ni el disco.

## 2. Archivos de arranque

| Archivo | Antes | Ahora |
|---|---|---|
| `CLAUDE.md` | — | 19 líneas / 1.044 B |
| `AGENTS.md` | — | 16 líneas / 697 B |
| `docs/orquestador/SESSION_RESUME.md` | 439 líneas / 26.889 B (estado mezclado con la historia del 2026-10-08, que incluía órdenes de arrancar el loop) | **116 líneas / 7.292 B** |
| `docs/orquestador/current_task.md` | 1.504 líneas / 114.367 B | **41 líneas / 2.051 B** |

- **Arranque:** `CLAUDE.md` + `SESSION_RESUME.md` = **8.336 B** de documentación del proyecto, más el comentario asignado.
- **SESSION_RESUME.md §1** separa los SHAs, cada uno con su fecha de observación:
  - el HEAD remoto de la rama de despliegue;
  - el checkout que corre en el VPS;
  - la imagen del contenedor;
  - la imagen de recuperación;
  - BASE, TESTED_HEAD y REVIEW_HEAD de la tarea.
- También registra la autorización `6210321522` y la rama de producto en curso, sin tocarla.

## 3. Archivo histórico (se movió con `git mv`, sin borrar nada)

- `docs/orquestador/history/SESSION_RESUME_hasta_2026-10-16.md`: el `SESSION_RESUME.md` anterior, completo.
- `docs/orquestador/history/current_task_hasta_2026-10-16.md`: el `current_task.md` anterior, completo.

Los enlaces que apuntaban a `SESSION_RESUME.md` y `current_task.md` siguen funcionando, porque las rutas son las mismas con contenido nuevo. Los dos archivos nuevos enlazan a su versión archivada. Los informes históricos no se reescribieron.

## 4. Instrucciones de arranque contradictorias que se corrigieron

- **`ACCESS_BOOTSTRAP.md`:**
  - el propósito ya no presenta el archivo como arranque del orquestador;
  - §6 lleva una marca **HISTÓRICO/suspendido**;
  - en §8, el checklist que decía «entrar al loop si hay tarea activa autorizada» se reemplazó: el arranque es `SESSION_RESUME.md` §0 y el bootstrap se usa por comando, solo si hace falta. Las referencias a presencia y permisos y a una máquina nueva apuntan a `ENVIRONMENT.md`. El handoff ya no es canal de entrega.
- **`PROJECT_KNOWLEDGE.md`:**
  - el propósito ahora es «bajo demanda»;
  - §7 tiene fecha en «php bloqueado por Application Control», que se registró el 2026-10-09. El 2026-10-16, PHP 8.2.33 responde, todavía sin intl;
  - en §9, el orden de lectura (que incluía el protocolo autónomo, `current_task.md` completo y el handoff) pasa a «solo §0», con el resto bajo demanda.
- **`AUTONOMOUS_DEV_LOOP.md`:** tiene ahora un aviso inicial: **SUSPENDIDO desde 2026-10-14, referencia histórica opcional**.
- **`.claude/commands/retomar-proyecto.md`:** se reescribió para que no cambie de rama, no haga pull ni lea historia.
- **`audit/orchestrator_handoff.json`** pesa **515.767 B**. El arranque lo excluye de forma explícita: solo se leen campos concretos.

## 5. Inventario por entorno (`docs/orquestador/ENVIRONMENT.md`)

Los entornos son WIN, VPS, TEST, DOC y GPT. Para cada herramienta se registra si es requerida, el estado, la versión observada, la fecha y la alternativa. Las rutas absolutas no van al repo: quedan en la memoria privada del ejecutor.

Comprobación barata y dirigida en WIN, con `Get-Command`, `where.exe` y un `--version` con tiempo límite de 10 s:

| Herramienta | WIN 2026-10-16 08:12Z | VPS 08:14Z |
|---|---|---|
| gh | NOT_ON_PATH | NOT_ON_PATH |
| python / python3 | **STORE_ALIAS** («Python was not found… Microsoft Store») | `python3` 3.12.14 |
| py | NOT_ON_PATH | — |
| git / ssh / node / php / docker | 2.55.0 / OpenSSH 9.5p2 / v24.19.0 / 8.2.33 / 29.8.1 | docker 29.7.2 |

**Dónde funcionó Python, según la evidencia:**
- **WIN:** nunca en esta máquina. Hay un stub de la Store desde al menos el 2026-09-28 (`audit/chat_memory_intent_audit_2026-09-28.md`).
- **VPS:** sí. Ahí corría el ejecutor, hoy suspendido.
- **Contenedor desechable:** `pdftotext` para el PDF (2026-10-15); la imagen exacta es UNKNOWN.
- **Máquina anterior** (`EstebanVasquez`): **UNKNOWN**. Los permisos solo muestran que esos comandos se permitieron.

gh no es requisito. Para leer se usa la API anónima y para comentar `post_issue_comment.ps1`.

**Script nuevo, `docs/orquestador/check_tools.ps1`:** no interactivo, acotado, idempotente. Informa solo nombres y estados, no instala nada y no hace llamadas de red. Se validó ejecutándolo y con el parser de PowerShell (0 errores).

## 6. Accesos

En `ENVIRONMENT.md` §3–§4:
- presencia, autenticación y permiso por acción, por separado. SET no prueba un token válido; `fetch` no prueba que el push esté permitido;
- en la misma máquina se reutilizan los almacenes persistentes, con el bootstrap por comando;
- una máquina nueva necesita su propio aprovisionamiento. Hay un checklist de 7 pasos por nombre: el repo no transfiere secretos.

No se crearon, rotaron ni copiaron valores.

## 7. STARTUP_DRY_RUN

`docs/orquestador/STARTUP_DRY_RUN.json` lista:
- los archivos que la herramienta carga sola;
- las lecturas de arranque con rango y bytes;
- los comandos;
- lo que se excluye;
- la siguiente acción autorizada.

Se validó con `ConvertFrom-Json`. Los enlaces relativos de los archivos nuevos se comprobaron con un chequeo de existencia: 0 rotos. No se lanzó ninguna sesión de IA ni corrida de tests para probarlo.

## 8. Publicación

- Rama `docs/session-continuity`.
- El único workflow (`deploy-contabo.yml`) se dispara con un push a `feature/upgrade-filament-v3` o por `workflow_dispatch`, así que publicar esta rama **no despliega**.
- No se hizo push a la rama de despliegue, ni merge, ni ninguna mutación en el servidor como parte de esta tarea.

## 9. Relación con el despliegue de CPV-HOME-BRANDING-AND-LAYOUT (misma sesión, aparte)

- El propietario aprobó en el chat el despliegue de `0f8bdad`.
- Se hicieron las precondiciones de solo lectura (`audit/runs/home_branding_deploy_2026-10-16/pre_state.txt`, `smoke_0010c_pre.json` 18/18) y se creó la imagen de recuperación `perfilafiliados-app:pre-branding-c02d4ec`.
- El fast-forward a `feature/upgrade-filament-v3` **lo rechazó el clasificador de permisos** de la sesión. Se reporta y no se rodeó, así que staging sigue en `c02d4ec`. La siguiente acción del propietario está en `SESSION_RESUME.md` §2.
- El smoke posterior está preparado en `docs/deploy/smoke_home_branding_readonly.php`. Su sintaxis está comprobada; todavía no se corrió contra el código nuevo.

## 10. Límites

- No se puede probar la causa exacta del ~20%: no se importaron logs de sesión, como pide la instrucción.
- El daemon de Docker en WIN y node en el VPS quedaron como NOT_CHECKED.
