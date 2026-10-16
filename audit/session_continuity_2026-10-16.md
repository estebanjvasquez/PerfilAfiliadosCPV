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

## 11. Final closure (Issue #2 `6211767961`)

The review accepted the structure and asked for 9 bounded corrections. Status of each:

1. **Product state refreshed** from record `6211360371`. Inicio is **deployed at `0f8bdad`**, run `38873194879` success, with product checkpoint `68168129`. The obsolete "owner must push `0f8bdad`" instruction and the "blocked" claim are removed. The recorded timestamps are reused, and staging was not rechecked for this.
2. **Reconciliation:**
   - `feature/home-branding-layout` (`68168129`) was merged into this branch with `-X no-renames`, so its edits could not land on the archived files.
   - `SESSION_RESUME.md` and `current_task.md` keep the short form and were rewritten with the new state.
   - The smoke script takes the product version, which has the SPA check.
   - The deployment evidence and report §9 come in with the merge.
   - `history/**` is byte-identical to `235949b` (`git diff` is empty).
   - The product branch's long checkpoint stays in its own history at `68168129`.
   - `SESSION_RESUME.md`, `current_task.md` and `STARTUP_DRY_RUN.json` all state the same next action: product UAT and continuity acceptance.
3. **No recursive reading:** `SESSION_RESUME.md` §0.3, `CLAUDE.md` and `AGENTS.md` now say: read the assigned comment once; open only the direct references the task needs, once each, and extract only the relevant requirements; never follow comments transitively. §0 also separates "no broad audit at onboarding" from the normal, targeted source reads that implementation requires.
4. **Honest budget** in `STARTUP_DRY_RUN.json`:
   - project docs: **9,805 B** (`CLAUDE.md` 1,337 + `SESSION_RESUME.md` 8,468, 115 lines);
   - assigned input reported separately: **5,299 B** for `6211767961`;
   - private memory: 1,413 B;
   - total initial text inputs: **16,517 B**;
   - an explicit caveat that file size does not guarantee context or token cost.
5. **`check_tools.ps1`:**
   - **AVAILABLE** requires native exit code 0 **and** a version pattern specific to the tool;
   - **STORE_ALIAS** requires the alias *message*, not just a `WindowsApps` path;
   - non-zero exit, timeout or policy denial → **BLOCKED**;
   - exit 0 with an unexpected shape → **UNKNOWN**;
   - **FOUND** without `-Version`;
   - stdout and stderr are captured through `cmd /c`, which keeps `$LASTEXITCODE`.
   - Re-measured on 2026-10-16 ~08:55Z: `python`/`python3` STORE_ALIAS with exit **9009** (the earlier Git Bash exit 49 is 9009 mod 256); git, ssh, node, php and docker AVAILABLE with exit 0; `gh` and `py` NOT_ON_PATH. The parser reports 0 errors.
6. **Distribution:** `SESSION_RESUME.md` §1 names the canonical branch `docs/session-continuity`. It explains how to switch safely (clean tree → fetch/switch/`pull --ff-only`, or a separate `git worktree`; never discard changes) and that another computer needs its own provisioning. It also gives the exact one-line restart prompt.
7. **Not integrated into the deploy line.** The required carry-forward is recorded in `SESSION_RESUME.md` §1 and `current_task.md`: integrate the startup files in the next explicitly authorized runtime delivery. No push to `feature/upgrade-filament-v3`.
8. **Validation:**
   - the archives are unchanged;
   - credentials stay external;
   - verification is per capability;
   - the JSON parses;
   - relative links in the active startup files are checked;
   - the script behaviour is checked;
   - the manifest is regenerated.
9. **How it ran:** one foreground session. No agents, no AI reviewers, no polling, no tests, no reinstalls, no live mutation and no deployment.

**Tools by environment:**
- **Windows:** Python is a Store alias (exit 9009); `gh` is absent.
- **VPS:** `python3` 3.12.14 (observed 2026-10-16 08:14Z).
- **UNKNOWN:** the DOC container image, and whether Python worked on the previous machine.
