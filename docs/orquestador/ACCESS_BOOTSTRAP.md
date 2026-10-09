# CPV — Accesos y persistencia de credenciales

**Versión:** 1.0
**Fecha:** 2026-10-09
**Propósito:** que el orquestador autónomo y **cada sesión nueva del agente de desarrollo** recuperen
sus accesos sin que el propietario los reconfigure a mano cada vez.

> ## ⚠️ ESTE ARCHIVO NO CONTIENE NINGÚN SECRETO Y NUNCA DEBE CONTENERLO
>
> **Los repositorios del proyecto son públicos.** Este archivo es world-readable.
>
> Aquí sólo hay: **nombres** de variables, **dónde vive** cada valor, **cómo se verifica** que funciona
> y **qué gate** gobierna su cambio. Jamás un valor.
>
> Si alguna vez se escribe un secreto en este repositorio, el procedimiento no es borrar el commit:
> es **rotar el secreto** (GATE D) y luego limpiar. Un secreto empujado a un repo público se debe
> considerar comprometido de forma inmediata e irreversible.

---

## 1. Modelo en una frase

**El repositorio guarda el mapa. Cada ejecutor guarda sus propias llaves.**

| Ejecutor | Dónde viven sus valores |
|---|---|
| GitHub Actions (deploy) | *Repository secrets* de GitHub |
| Cloudflare Worker (runtime) | *Worker secrets* de Cloudflare |
| Agente de desarrollo local | almacén local fuera del repo (§3) + `.env` de Laravel |
| Orquestador autónomo | su propio almacén de secretos / variables de entorno (§6) |

Ningún ejecutor lee las llaves de otro. El repo sólo dice **qué nombre** espera cada uno.

---

## 2. Inventario de capacidades

Qué se puede hacer, con qué credencial, y de dónde sale el valor.

| # | Capacidad | Credencial (nombre) | Dónde vive el valor | Estado |
|---|---|---|---|---|
| 1 | `git fetch` / `push` a GitHub | — | Windows Credential Manager (vía Git Credential Manager) | ✅ ya persistente |
| 2 | Leer Issue #2 y la API de GitHub | — (anónimo) | no requiere credencial: los repos son públicos | ✅ siempre disponible |
| 3 | **Escribir** comentarios en Issue #2 | `CPV_GITHUB_TOKEN` | almacén local (§3) / secretos del orquestador | ⚠️ **no disponible para el agente** (§5) |
| 4 | `artisan` y Eloquent contra Supabase | `DB_PGSQL_*` | `.env` del repo (gitignored) | ✅ ya persistente |
| 5 | Postgres directo (psql / scripts) | `DB_PGSQL_*` | mismos valores que (4) | ✅ ya persistente |
| 6 | SSH a staging (Contabo) | clave privada OpenSSH | `%USERPROFILE%\.ssh\` en la máquina del propietario | ✅ local |
| 7 | Deploy automático a staging | `CONTABO_HOST`, `CONTABO_USER`, `CONTABO_SSH_KEY` | **GitHub repository secrets** | ✅ ya configurado |
| 8 | Regresión 32-query / `POST /debug-search` | `DEBUG_TOKEN` | **Worker secret de Cloudflare** — plaintext **no recuperable** | ⚠️ requiere GATE D |
| 9 | Deploy / secretos del Worker (`wrangler`) | `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ACCOUNT_ID` | almacén local (§3) | ⬜ no configurado |
| 10 | API de gestión de Supabase | `SUPABASE_ACCESS_TOKEN` | almacén local (§3) | ⬜ opcional |
| 11 | Flujo n8n / CIRA (UAT) | credenciales de n8n | propiedad del propietario | 🔒 fuera del agente |

Leyenda: ✅ funciona · ⚠️ limitación conocida · ⬜ no configurado, se configura si se necesita ·
🔒 deliberadamente fuera del alcance del agente.

### 2.1 Notas por credencial

- **(1) Git push.** Ya sobrevive entre sesiones porque lo guarda Windows, no la sesión. **Límite
  conocido:** el PAT almacenado **no tiene scope `workflow`**, así que cualquier push que toque
  `.github/workflows/**` es rechazado. Es un límite, no un fallo: ampliarlo es GATE D.
- **(2) Lectura de GitHub.** Como los repos son públicos, leer Issue #2 no necesita token:
  `curl.exe -s "https://api.github.com/repos/estebanjvasquez/PerfilAfiliadosCPV/issues/2/comments?per_page=10&page=<n>"`.
  Esto es lo que hace funcionar el *polling* de revisiones de `AUTONOMOUS_DEV_LOOP.md` §11 **sin
  ninguna credencial**.
- **(3) Escritura en Issue #2.** Requiere un PAT con scope `repo` (o `public_repo`). Ver §5.
- **(4)/(5) Base de datos.** `.env` ya existe y está en `.gitignore`. **No hay base local separada:
  estos valores apuntan a la misma Supabase que usa staging** (ver `PROJECT_KNOWLEDGE.md` §3).
- **(8) `DEBUG_TOKEN`.** Por diseño el único plaintext está en el almacén de Cloudflare y en el
  `sessionStorage` del navegador del administrador que escribió `/debug-on <key>`. **No es recuperable
  por API**, y está explícitamente prohibido intentar leerlo por una API que sólo expone metadatos.
  Si una tarea necesita la regresión, el camino autorizado es que el **propietario** la corra, o que
  autorice una rotación (GATE D).

---

## 3. Almacén local del agente (fuera del repositorio)

**Ubicación:** `%USERPROFILE%\.cpv\credentials.env` → `C:\Users\esteb\.cpv\credentials.env`

**Por qué fuera del repo:** un archivo dentro del árbol de trabajo puede acabar en un `git add -A`, en
un stash, en un diff, o en un repo que —como este— es público. Fuera del repo eso es imposible por
construcción, no por disciplina.

Formato: `NOMBRE=valor`, uno por línea, `#` para comentarios. Plantilla con **sólo nombres** en
[`credentials.env.example`](credentials.env.example).

### 3.1 Creación (una sola vez, la ejecuta el propietario)

```powershell
New-Item -ItemType Directory -Force -Path "$env:USERPROFILE\.cpv" | Out-Null
Copy-Item "C:\Proyectos\GitHub\PerfilAfiliadosCPV\docs\orquestador\credentials.env.example" `
          "$env:USERPROFILE\.cpv\credentials.env"
notepad "$env:USERPROFILE\.cpv\credentials.env"   # rellenar valores a mano
```

Endurecer permisos para que sólo el usuario pueda leerlo:

```powershell
icacls "$env:USERPROFILE\.cpv\credentials.env" /inheritance:r /grant:r "$($env:USERNAME):(R,W)"
```

El propietario rellena los valores **él mismo**. El agente no pide que se peguen en el chat.

### 3.2 Protección contra commit accidental

La protección principal es la ubicación: el almacén está **fuera del árbol de trabajo**, así que no
existe ningún `git add` que pueda alcanzarlo.

Como defensa en profundidad hay además reglas de ignorado en **`.git/info/exclude`** de este clon
(`.cpv/`, `credentials.env`, `*.local.env`, `.claude/settings.local.json`, `.dev.vars*`). Al vivir en
`.git/info/`, aplican también a los worktrees `wt-task-0010a` y `wt-task-0010b`, que comparten el
mismo directorio `.git`.

> **Pendiente deliberado, no olvido.** Esas mismas reglas *deberían* estar en el `.gitignore` de la
> raíz para que viajen a cualquier clon futuro. No se añadieron todavía porque `.gitignore` **no**
> matchea el `paths-ignore` del workflow (`docs/**`, `audit/**`, `**.md`), y la semántica es
> todo-o-nada sobre el push completo: commitearlo por sí solo **dispararía un rebuild y redespliegue
> completo de staging** sin que ninguna línea de runtime haya cambiado.
>
> **Acción:** plegar estas seis líneas en el próximo commit que ya toque runtime (por ejemplo la
> integración de TASK-0010), donde el deploy ocurre de todos modos y es gratis.

---

## 4. Cómo cada sesión nueva recupera los accesos

El problema real: **PowerShell 5.1 no conserva estado entre llamadas de herramienta.** Fijar
`$env:` en una llamada no sirve para la siguiente. Por eso la persistencia no puede depender de
"exportar una vez al principio de la sesión".

**La solución es un cargador que se invoca al inicio de cada comando:**

```powershell
. C:\Proyectos\GitHub\PerfilAfiliadosCPV\docs\orquestador\bootstrap_access.ps1 -Quiet; <comando real>
```

Ese `dot-source` hace tres cosas en una línea:
1. añade `git` y `php` al `PATH` (el otro problema recurrente del entorno);
2. carga el almacén local en `$env:*`;
3. no imprime ningún valor.

Verificación de qué hay disponible, sin exponer valores:

```powershell
. C:\Proyectos\GitHub\PerfilAfiliadosCPV\docs\orquestador\bootstrap_access.ps1
```

Imprime una tabla `NOMBRE = SET | MISSING`. Con `-Verify` añade pruebas de conectividad no destructivas
(GitHub, Worker, staging) y reporta sólo `PASS`/`FAIL`.

### 4.1 Opción alternativa, más cómoda y menos segura

Claude Code inyecta automáticamente el bloque `env` de `.claude/settings.local.json` en **cada**
llamada, sin prefijo. Es más cómodo, pero deja secretos en texto plano **dentro del árbol del repo**,
que es exactamente lo que §1 evita.

**Decisión del propietario (GATE D).** No se implementa por defecto. Si se adopta, `.gitignore` ya
cubre `.claude/settings.local.json`.

---

## 5. Limitación actual: el agente no puede comentar en Issue #2

Confirmado por el orquestador en el comentario `6079826097`, y confirmado otra vez el 2026-10-09:

- el agente **lee** Issue #2 sin credencial (repo público);
- el agente **no escribe** en Issue #2;
- el agente **sí** hace commit/push y actualiza `audit/orchestrator_handoff.json`.

**Causa exacta, medida:** no hay `gh` CLI instalado, y el PAT que usa `git` está en Windows Credential
Manager, pero el clasificador de permisos del sandbox del agente **bloquea leerlo** (motivo
`Credential Exploration`). Es una barrera de seguridad del entorno del agente, no una restricción de
GitHub ni un permiso faltante en el token.

### 5.1 Fallback vigente (no requiere al propietario como mensajero)

Es el de `AUTONOMOUS_DEV_LOOP.md` §11, y funciona porque la lectura es anónima:

1. el agente hace commit/push y escribe en el handoff `review_state`, `review_head`, `review_task`,
   `review_issue`, `review_requested_at`;
2. el orquestador detecta el nuevo HEAD y publica su auditoría en Issue #2;
3. el agente **lee** ese comentario sin credencial y aplica `CORRECTIONS_REQUIRED`.

El canal de ida es el repositorio; el de vuelta es Issue #2. El loop cierra.

### 5.2 Si se quiere habilitar la escritura directa del agente

Dos piezas, ambas decisión del propietario:

1. **La credencial:** añadir `CPV_GITHUB_TOKEN` al almacén local (§3), con un PAT de scope `repo`
   (o `public_repo`, suficiente para comentar en un repo público). Conviene que sea un **token
   distinto** del de `git push`, con el mínimo scope y caducidad corta.
2. **El permiso del sandbox:** una regla de permiso para `PowerShell` en los *settings* de Claude Code,
   porque sin ella el agente no puede leer ni su propia variable de entorno de token mediante los
   comandos que el clasificador marca como exploración de credenciales.

Con ambas, publicar un comentario es un solo comando, usando el script ya versionado
[`post_issue_comment.ps1`](post_issue_comment.ps1):

```powershell
.\docs\orquestador\post_issue_comment.ps1 -BodyFile .\mi_comentario.md
.\docs\orquestador\post_issue_comment.ps1 -BodyFile .\mi_comentario.md -WhatIf   # ensayo
```

El script toma el token de `$env:CPV_GITHUB_TOKEN` (cargado por `bootstrap_access.ps1`), **nunca** por
línea de comandos; devuelve sólo el id y la URL del comentario creado; y **aborta** si el cuerpo
coincide con un patrón de secreto, porque el Issue es público. Si no hay token, falla con
`BLOCKED_NO_GITHUB_WRITE_CREDENTIAL` y recuerda el fallback de §5.1 en lugar de intentar otra vía.

---

## 6. Bootstrap del orquestador autónomo

El orquestador **no** debe heredar las llaves del agente ni leer `.env` de nadie. Usa su propio
almacén (variables de entorno del proceso, GitHub Actions secrets, o el *secret manager* de su
plataforma) con estos nombres:

| Nombre | Obligatorio | Para qué |
|---|---|---|
| `CPV_GITHUB_TOKEN` | **sí** | publicar auditorías y veredictos en Issue #2 |
| `CPV_REPO` | sí | `estebanjvasquez/PerfilAfiliadosCPV` |
| `CPV_WORKER_REPO` | sí | `estebanjvasquez/perfilafiliados-mcp` |
| `CPV_BRANCH` | sí | `feature/upgrade-filament-v3` |
| `CPV_ISSUE` | sí | `2` |
| `SUPABASE_ACCESS_TOKEN` | no | verificar estado vivo de datos (sólo lectura) |
| `DB_PGSQL_*` | no | verificación directa de conteos (sólo lectura) |

El mapa legible por máquina está en [`access_map.json`](access_map.json), para que el orquestador no
tenga que parsear este Markdown.

### 6.1 Secuencia de arranque del orquestador

1. `GET /repos/{CPV_REPO}/branches/{CPV_BRANCH}` → HEAD remoto actual.
2. Leer, en este orden, desde el repo en ese HEAD:
   `SESSION_RESUME.md` → `AUTONOMOUS_DEV_LOOP.md` → `current_task.md` →
   campos `current_*` y `review_*` de `audit/orchestrator_handoff.json` →
   `PROJECT_KNOWLEDGE.md` (sólo si necesita conocimiento técnico).
3. `GET /repos/{CPV_REPO}/issues/2/comments` (última página) → último veredicto publicado.
4. Comparar `review_head` del handoff contra el HEAD remoto:
   - distintos y `review_state = READY_FOR_REVIEW` → **hay trabajo que auditar**;
   - iguales y ya existe veredicto para ese HEAD → nada que hacer.
5. Auditar leyendo **código real** en ese HEAD, no el resumen del agente.
6. Publicar en Issue #2 con uno de los veredictos: `PASS / CLOSED`, `CORRECTIONS_REQUIRED`,
   `BLOCKED_EXTERNAL`, `OWNER_GATE_REQUIRED`.
7. Si el repo cambió durante la auditoría, repetir desde (1): no auditar un HEAD ya superado.

### 6.2 Reglas que el orquestador autónomo hereda

- No puede conceder autorizaciones que son del propietario. Un gate A–F **se escala, no se resuelve**.
- Una autorización del propietario dada en conversación **no** es el acto de gobierno: debe publicarse
  como `ORCHESTRATOR AUTHORIZATION RECORD` en Issue #2, y **ese id** es lo que se cita en
  `--authorized-by`. El orquestador no debe redactar un comentario como si lo hubiera escrito el
  propietario.
- Nunca escribir un secreto en Issue #2 ni en el repo (los dos son públicos).
- `CORRECTIONS_REQUIRED` debe ser ejecutable por el agente sin intervención del propietario.

---

## 7. Exposición pública: hallazgo y recomendación

**Hallazgo (verificado el 2026-10-09 vía API de GitHub):** `PerfilAfiliadosCPV` y
`perfilafiliados-mcp` son **públicos**. Por lo tanto ya son legibles por cualquiera:

- la IP del VPS, las URLs de staging, la ruta `/opt/perfilafiliados`;
- el project id de Supabase y el id de Hyperdrive;
- los nombres de los secretos de Actions y la topología completa de despliegue;
- los 68 comentarios de gobierno de Issue #2, con conteos de datos y decisiones.

**Ningún secreto está expuesto** — eso se mantuvo a lo largo de todo el proyecto, y los barridos de
patrones sobre `docs/**`, `audit/**` y `*.md` sólo devolvieron falsos positivos (líneas en prosa que
enumeran los propios patrones de búsqueda). Pero la **superficie de reconocimiento** sí está publicada.

**Recomendación, para decisión del propietario (GATE F):**

1. **Poner `PerfilAfiliadosCPV` en privado.** Es un panel administrativo de una organización real; no
   hay motivo aparente para que su código y su hilo de gobierno sean públicos.
2. Si se requiere que siga público, mover los identificadores de infraestructura fuera de `docs/`
   (a secretos o a un repo privado) y dejar en `SESSION_RESUME.md` sólo nombres lógicos.
3. Decidir explícitamente si Issue #2 debe seguir siendo público. Si se hace privado el repo, el Issue
   deja de ser legible sin credencial, **y entonces el polling anónimo de §5.1 deja de funcionar**:
   en ese caso `CPV_GITHUB_TOKEN` pasa de opcional a obligatorio también para **leer**.

Esto se registra como recomendación. **No se ejecuta ningún cambio de visibilidad sin autorización.**

---

## 8. Checklist para una sesión nueva del agente

```powershell
# 1. Sincronizar y situarse
. C:\Proyectos\GitHub\PerfilAfiliadosCPV\docs\orquestador\bootstrap_access.ps1 -Quiet
cd C:\Proyectos\GitHub\PerfilAfiliadosCPV; git fetch origin; git status --short

# 2. Ver qué accesos hay (sin exponer valores)
. C:\Proyectos\GitHub\PerfilAfiliadosCPV\docs\orquestador\bootstrap_access.ps1 -Verify
```

Luego leer en el orden de `PROJECT_KNOWLEDGE.md` §9 y entrar al loop si hay tarea activa autorizada.

**Al terminar cualquier ronda:** working tree limpio, commit + push, `current_task.md` y
`orchestrator_handoff.json` actualizados, y **cero secretos persistidos**.
