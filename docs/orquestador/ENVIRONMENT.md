# CPV — Capacidades por entorno y ejecutor

Esta página se consulta bajo demanda, no en el arranque. Se creó el 2026-10-16 (`6210552318`).

- **Qué hay aquí:** nombres lógicos y estados.
- **Qué no hay:** valores de credenciales.
- **Rutas absolutas:** las de ejecutables o claves de SSH de una máquina concreta van en el registro privado local del ejecutor, fuera de git. En Claude Code, es su memoria de proyecto.

> **«Hay Python» no es una propiedad de la sesión: es de un entorno.** Antes de usar una herramienta, mire en qué entorno corre el comando.
>
> `NOT_ON_PATH` no significa «no instalado en ningún lado».

## 1. Entornos

| Id | Entorno | Quién lo usa |
|---|---|---|
| **WIN** | Equipo Windows del desarrollador: cuenta local, PowerShell 5.1 y Git Bash, con el repo clonado bajo un directorio de proyectos | la sesión del agente de desarrollo |
| **VPS** | VPS de staging (Contabo, Linux, `root` por SSH). La app corre en Docker Compose | despliegue, smokes de solo lectura, runner aislado |
| **TEST** | Contenedores desechables del runner aislado (`infra/contabo-test-runner`, en el VPS): PHP 8.2 con intl, Postgres efímero y Playwright | PHPUnit y chequeos de navegador |
| **DOC** | Contenedor desechable para PDF y documentos (por ejemplo `pdftotext` o render de páginas) | lectura del PDF del manual (2026-10-15) |
| **GPT** | Conector y nube de ChatGPT (orquestador) | revisión y registros en el Issue #2. Su disponibilidad no depende de WIN |

## 2. Herramientas

| Herramienta | ¿Requerida? | WIN (2026-10-16 08:12Z) | VPS (2026-10-16 08:14Z) | Vía segura / alternativa |
|---|---|---|---|---|
| git | sí | AVAILABLE 2.55.0 | AVAILABLE (checkout de la app) | — |
| PowerShell | sí (WIN) | AVAILABLE 5.1 | n/a | Git Bash para scripts POSIX |
| ssh | sí, para VPS | AVAILABLE OpenSSH 9.5p2 | n/a | — |
| node | opcional | AVAILABLE v24.19.0 | NOT_CHECKED | parseo de JSON también con `ConvertFrom-Json` |
| php | opcional en WIN | AVAILABLE 8.2.33, **sin `ext-intl`** | dentro del contenedor `app` | PHPUnit **solo en TEST** |
| PHPUnit | sí, para tests | no se usa: la base compartida es remota y no hay intl | no hay (`--no-dev`) | **TEST**: `run_tests.sh <sha> --filter …`. La suite completa, en un solo proceso, pasó los 128M de memoria por defecto (2026-10-16, 533 tests, pico de 135 MB); el runner pasa `-d memory_limit=512M` (`457d445`) |
| docker | opcional en WIN | AVAILABLE 29.8.1 (cliente; daemon NOT_CHECKED) | AVAILABLE 29.7.2 | — |
| gh | **no** | NOT_ON_PATH (también lo vio el propietario el 2026-10-15) | NOT_ON_PATH | lectura: API REST anónima; escritura: `post_issue_comment.ps1` |
| python / python3 | **no** | **STORE_ALIAS**, código de salida nativo **9009**: `python --version` responde «Python was not found… Microsoft Store» (medido de nuevo 2026-10-16 ~08:55Z) | AVAILABLE `python3` 3.12.14 | PowerShell, node, o un contenedor DOC/TEST |
| py (lanzador) | no | NOT_ON_PATH | n/a | — |

Para comprobarlo de nuevo en WIN, sin instalar nada: `docs\orquestador\check_tools.ps1 -Name <tool> -Version`.

### 2.1 Evidencia sobre Python

- **WIN:** es un alias de la Store, no un intérprete. Hay tres pruebas:
  - `audit/chat_memory_intent_audit_2026-09-28.md` §1 lo registra: «solo un stub de Microsoft Store»;
  - en esta sesión, una llamada a `python` terminó con el mensaje de la Store: exit 49 en Git Bash, que es 9009 mod 256;
  - `check_tools.ps1` lo confirma con exit 9009.
  - El estado no se deduce de la ruta: un Python instalado desde la Store también vive en `WindowsApps`. `check_tools.ps1` solo marca **AVAILABLE** cuando el comando sale con 0 y da la versión esperada, y marca **STORE_ALIAS** cuando responde con el mensaje del alias.
- **Por qué `.claude/settings.json` permite comandos `python …`:** esos permisos son **históricos** y vienen de otra máquina y otra cuenta (rutas `C:/Users/EstebanVasquez/…/OneDrive…`, `C:\xampp\php`).
  - Permitir un comando no prueba que esté instalado ni que funcione hoy.
  - Si Python funcionaba en esa máquina: **UNKNOWN**, no hay evidencia.
- **VPS:** `python3` 3.12.14. Allí corría el ejecutor autónomo, hoy suspendido (`audit/orchestrator_suspended_2026-10-14.md`).
- **DOC/TEST:** el PDF del manual se leyó con `pdftotext` en un contenedor desechable (`audit/mail_docs_user_manual_2026-10-15.md`). Qué imagen se usó: **UNKNOWN**.
- **Conclusión:** para una tarea que pida Python, use la vía del entorno que corresponde (VPS o contenedor), o PowerShell/node. No instale ni cambie el PATH.

## 3. Accesos: presencia, autenticación y permiso son cosas distintas

| Capacidad | Mecanismo (solo nombres) | Presente | Autentica | Permiso para la acción |
|---|---|---|---|---|
| Leer el Issue #2 y los comentarios | API REST anónima (el repo es público) | siempre | no hace falta | lectura |
| Comentar en el Issue #2 | `CPV_GITHUB_TOKEN` del almacén local del ejecutor, por `post_issue_comment.ps1` | `bootstrap_access.ps1` → SET/MISSING | verificado 2026-10-16 (comentario `6210758420`) | issues:write. **No** sirve para Actions (`workflow_dispatch` da 403) |
| git fetch y push | Git Credential Manager de Windows | `git ls-remote origin` | lectura verificada 2026-10-16 | el push a la rama de despliegue puede rechazarlo el clasificador de permisos de la sesión (2026-10-16); la PAT no tiene `workflow` |
| SSH al VPS | identidad OpenSSH del usuario de WIN (nombre en el registro privado) | `ssh … true` | verificado 2026-10-16 | root en el VPS; las mutaciones se rigen por la instrucción de la tarea |
| Desplegar | workflow «Deploy a Contabo» al hacer push a `feature/upgrade-filament-v3` (secretos del repo) | — | — | lo dispara un push a esa rama, nada más |

SET no prueba que el token sea válido. Un `git fetch` correcto no prueba que el push esté permitido.

## 4. Persistencia: misma máquina o máquina nueva

- **Misma máquina, sesión nueva:** se reutilizan los almacenes que persisten:
  - el almacén local de credenciales (cargado con el bootstrap, **por comando**);
  - Git Credential Manager;
  - las identidades SSH;
  - la memoria privada del ejecutor.
  - Las variables exportadas **no** sobreviven de una llamada de herramienta a otra.
- **Otra computadora:** el repositorio **no** transfiere secretos, y nada es portable de forma automática. Checklist (por nombre; el propietario aprovisiona cada punto por un canal aprobado):
  1. Clonar el repo e instalar git y OpenSSH. Las herramientas opcionales solo si la tarea las pide.
  2. Iniciar sesión en Git Credential Manager con una cuenta que tenga acceso al repo.
  3. Crear el almacén local de credenciales desde `credentials.env.example`, con `CPV_GITHUB_TOKEN` (issues:write) aprovisionado por el propietario.
  4. Aprovisionar una identidad SSH autorizada en el VPS. El propietario la agrega; la clave privada no se copia por git ni por chat.
  5. Conectar los conectores (claude.ai o ChatGPT) desde la cuenta propia de cada servicio.
  6. Verificar sin exponer valores: `bootstrap_access.ps1 -Verify` y `check_tools.ps1 -Name git,ssh -Version`.
  7. Registrar las rutas absolutas en el registro privado de esa máquina, no en el repo.
- **No:** copiar credenciales a git, al chat, al Issue o a un almacenamiento compartido no aprobado. Tampoco crear, rotar o ampliar permisos sin una instrucción explícita.
