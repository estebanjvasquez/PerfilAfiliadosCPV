# TASK-0012 — Recuperación de acceso: correo y RIF

| Campo | Valor |
|---|---|
| Identificador | **TASK-0012** (verificado libre: no aparece en `docs/` de ninguna rama local o remota, ni en mensajes de commit, nombres de rama o worktree, ni en `audit/orchestrator_handoff.json`; `tasks/` llega a 0011) |
| Fuente | Issue #2, comentario [`6602168914`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6602168914) (propietario, 2026-11-03 11:06Z). Texto íntegro al final de este archivo |
| Referencias directas | `6440840198` (TASK-0011 APPROVED, estado de referencia; TASK-0011 no se reabre ni se modifica) |
| Rama | `fix/sec-01-r4-01-role-editor-preserve` (rama canónica del estado vigente, `SESSION_RESUME.md` §1) |
| BASE | `73b5f2d9373e98c643df983e91a708856b1207b9` |
| Estado | **IN_PROGRESS** (2026-11-03) |
| Gates | sin merge, sin despliegue, sin cambios en cuentas ni datos reales; sin enviar correos reales sin autorización |

Cambios locales del propietario en `AGENTS.md`, `CLAUDE.md`, `docs/orquestador/PROTOCOL.md` y
`docs/orquestador/SESSION_RESUME.md`: conservados, sin commitear, no restaurados ni descartados.

**Nota de ramas:** staging corre `feature/upgrade-filament-v3` @ `7831e01` («apply TASK-0011 UX fixes to staging
baseline», aplicado por el propietario). Esa rama no tiene R3/R4 de seguridad; los archivos de autenticación
(`Login.php`, `AdminPanelProvider.php`, `User.php`, `config/auth.php`) son idénticos en ambas. El trabajo va en la rama
canónica; pasarlo a staging es una decisión del propietario.

---

## Bloque 1 — Flujos actuales y modelo de identidad

### Diagnóstico (fuente: código en BASE; staging `7831e01` en solo lectura, transacción READ ONLY + rollback, solo agregados)

**D1. Hoy no existe ninguna recuperación de contraseña.**
- `AdminPanelProvider` registra `->login()`, `->registration()` y `->emailVerification()`, pero **no**
  `->passwordReset()`. No hay ruta de solicitud ni de restablecimiento (`routes/web.php` tampoco las tiene).
- `App\Filament\Pages\Login` redefine `getPasswordFormComponent()`. Por eso, aunque se activara el reset de Filament, el
  enlace «¿Olvidaste tu contraseña?», que Filament pone como *hint* de ese campo, no aparecería.
- El manual lo dice hoy (`manual_usuario.md` §«Si olvidó su contraseña»): «no ofrece hoy una opción para recuperar la
  contraseña… contacte a la Cámara». Toda recuperación depende hoy de un administrador.

**D2. El usuario es el correo.** El login de Filament autentica con `email` + `password`
(`Filament::auth()->attempt`). Ningún texto lo dice: la etiqueta es solo «Correo electrónico».

**D3. El correo distingue mayúsculas al entrar.** Postgres compara `email = ?` de forma exacta. En staging, **18 de 367**
correos guardados no están en minúsculas o tienen espacios. Esas cuentas solo entran si escriben el correo exactamente
igual, y un teléfono que pone mayúscula inicial lo impide. No hay duplicados sin distinguir mayúsculas (0), así que la
búsqueda insensible es inequívoca hoy.

**D4. Modelo de identidad (staging, 2026-11-03):**

| Hecho | Valor |
|---|---|
| Usuarios / sin verificar / sin rol | 367 / 21 / 37 |
| Roles (cuentas) | `super_admin` 4, `afiliados` 324, `filament_user` 0, `lectura` 1, `reportes` 1; ninguna cuenta con 2 roles; 0 permisos directos |
| Empresas / RIF vacío / RIF sin normalizar / RIF compartido | 406 / 0 / 0 / 0. Los 406 tienen la forma letra + 8–9 dígitos |
| Vínculos `empresa_user` | 2199: `super_admin` 1624 (4 cuentas), una cuenta **sin rol** 229, `reportes` 1, `afiliados` 345 (314 cuentas) |
| Afiliados por número de empresas | 309 con 1, 4 con 2, **1 con 6 o más**; 10 afiliados sin empresa |
| Empresas por buzones afiliados elegibles (regla R3) | 0: 128 · 1: 217 · 2: 56 · 3 o más: 5 |
| Tabla de tokens | `password_resets` existe (nombre antiguo), 0 filas; `config/auth.php`: expira a los 60 min, *throttle* de 60 s |

- **Las cuentas internas sí están vinculadas a empresas, y de forma automática:** `Empresa::boot()` inserta, al crear
  cada empresa, un vínculo para **cada** cuenta con `role_id = 1` (super_admin). Además hay una cuenta interna sin
  rol con 229 empresas. El vínculo `empresa_user` no basta para saber quién es afiliado.
- **Un RIF puede tener 0, 1 o varias cuentas afiliadas:** 128 empresas no tienen ningún buzón elegible.

**D5. Correo.** Staging usa `smtp`, el relay de Google Workspace, autorizado por IP; está activo desde el 2026-10-26 y
la verificación TLS se corrigió el mismo día. El remitente está configurado, la cola es `sync` y la caché `file`. El
`log` documentado antes ya no es el transporte de staging. Con `log`, el cuerpo del correo, con su enlace, quedaría en
el log.

**D6. Si solo se activara el reset de Filament, quedaría un oráculo de cuentas** (`RequestPasswordReset::request()`,
Filament 3.3.55):
- La notificación de fallo muestra el estado del *broker*. «No encontramos un usuario con ese correo» delata que la
  cuenta no existe, y «espere antes de reintentar» (`RESET_THROTTLED`) solo ocurre si existe.
- Con la cola `sync`, el envío SMTP solo ocurre para una cuenta existente: la diferencia de tiempo delata la cuenta.
- El límite es solo de 2 por minuto por IP y no hay Turnstile.
- No existe `resources/lang/es/passwords.php`: los estados del *broker* salen en inglés.

### Decisiones

- **R1 — Recuperación por correo** (canal directo, sin intervención de administradores).
  - Usa el `->passwordReset()` de Filament con páginas propias:
    - respuesta única y genérica siempre: existe o no, con límite o sin él;
    - la búsqueda del correo no distingue mayúsculas;
    - el envío se difiere hasta después de responder, para que el tiempo no delate la cuenta;
    - Turnstile, como en el login y el registro;
    - límites por IP y por correo normalizado, además del *throttle* de 60 s por cuenta del *broker*.
  - Token del *broker* de Laravel:
    - de un solo uso y con hash en la base;
    - vence a los 60 min;
    - un token nuevo reemplaza al anterior.
  - El enlace fija el correo del titular: quien lo abre solo puede cambiar la contraseña de esa cuenta.
  - Política de contraseña igual a la del registro (`Password::default()`, confirmación).
  - Al cambiarla, se renueva `remember_token`, y `AuthenticateSession` cierra las sesiones abiertas con la clave vieja.
- **R2 — «No recuerdo el correo de acceso» (RIF)** es una ayuda, no un selector de cuentas.
  - El RIF se normaliza igual que en `Empresa::setRifAttribute` y se valida con la forma letra + 8–9 dígitos.
  - A cada buzón elegible, sin repetir correos, le llega su propio enlace y un mensaje que explica que su usuario es ese
    correo y que solo cambia su propia clave.
  - La pantalla muestra siempre el mismo texto: no lista, no cuenta y no enmascara correos.
  - Tiene los mismos límites (por IP y por RIF) y el mismo envío diferido.
  - Si la empresa tiene más buzones elegibles que el tope configurado, no se envía nada. Se registra un aviso sin
    identidades y la persona recibe la misma respuesta, que remite a la Cámara.
- **R3 — Elegibilidad por RIF**, todas las condiciones leídas en el momento de la solicitud y nunca por id de rol:
  1. vinculada a la empresa en `empresa_user`;
  2. correo verificado, porque solo así consta que el titular controla el buzón;
  3. tiene el rol de afiliado por **nombre** (configurable, `afiliados`), **ningún otro rol** y ningún permiso directo;
  4. vinculada a no más de N empresas (configurable, por defecto 3). Esto excluye las cuentas internas o masivas, como
     la de 229 empresas o la afiliada con 6 o más, que conservan la vía por correo.
  - Si el rol no existe, nadie es elegible: la regla falla cerrada.
- **R4 — Sin enlaces en los logs.** Si el transporte efectivo es `log`, no se envía el correo de recuperación y se
  registra un aviso sin el enlace. Se exceptúan `local` y `testing`.
- **R5 — Login.**
  - El texto aclara que el usuario es el correo y ofrece «¿Olvidaste tu contraseña?».
  - El correo se resuelve sin distinguir mayúsculas solo cuando la coincidencia es única; si no, se usa tal cual. La
    contraseña se comprueba igual que hoy, así que el login no se debilita.
- **R6 — Respaldo.** Sin buzón elegible o sin acceso al buzón, la persona contacta a la Cámara, como hoy. El texto
  genérico de ambas pantallas lo dice.

### Pendiente del bloque 1
- Entrega real de correo: solo con autorización del propietario (ver bloque 3).

---

## Bloque 2 — Implementación

*(en curso)*

---

## Bloque 3 — Pruebas, correo y documentación

*(pendiente)*

---

## Comentario fuente (íntegro, `6602168914`)

> [ORCHESTRATOR — OPEN TASK-0012 / UX + SECURITY: ACCOUNT RECOVERY]
>
> **Fuente:** solicitud del propietario en este chat (2026-11-03): durante pruebas varios usuarios no recuerdan su contraseña o su usuario; solicitan recuperación por correo o RIF de empresa. Referencia de estado actual: Issue #2 6440840198 aprobó TASK-0011. No reabrir ni modificar TASK-0011; esta es una tarea nueva.
>
> **Objetivo:** ofrecer a afiliados una recuperación comprensible de acceso, con correo como canal directo y una opción que ayude a quien solo recuerda el RIF, sin revelar cuentas ni permitir cambiar la contraseña del usuario equivocado. En esta aplicación el email es el identificador de inicio de sesión; comunicarlo como tal. La recuperación no debe depender de intervención rutinaria de administradores si el usuario controla el buzón registrado.
>
> ## CONTINUIDAD Y MANTENIMIENTO (OBLIGATORIO)
> 1. Seguir el arranque y las reglas de AGENTS.md, docs/orquestador/PROTOCOL.md, docs/orquestador/SESSION_RESUME.md y docs/orquestador/current_task.md; comprobar rama, HEAD y cambios sin descartar trabajo.
> 2. Materializar como docs/orquestador/tasks/0012-account-recovery.md, preservando este comentario como fuente. Verificar que TASK-0012 no haya sido usado en otro estado/historial. Actualizar current_task.md como puntero sin borrar el checkpoint/historial de TASK-0011.
> 3. Mantener allí diagnóstico, decisiones, evidencia, pruebas y pendientes. Actualizar el handoff y detenerse en READY_FOR_REVIEW. Sin merge, despliegue ni cambios en cuentas/datos reales.
>
> ## PLAN DE IMPLEMENTACIÓN (tres pasos)
>
> ### 1. Confirmar los flujos y el modelo de identidad
> - Inspeccionar rutas/páginas de login, reset, broker, modelo User, vínculos empresa_user, verificación de correo, roles y configuración de envío. Reproducir qué puede hacer hoy un usuario y qué mensaje recibe; no inferir solo desde paquetes/vistas.
> - Confirmar explícitamente: email es el nombre de usuario; un RIF puede tener cero, una o varias cuentas; si los usuarios internos/administradores se agregan automáticamente a empresas, identificar cómo distinguirlos y excluirlos de cualquier recuperación iniciada por RIF.
> - Verificar entrega de correo real en el entorno previsto. Staging estaba documentado con transporte log; el enlace estándar no sirve al usuario si no llega al buzón. No exponer enlaces/tokens en logs de producción. Si falta configuración de correo, documentar el bloqueo y el requisito operativo con una prueba de entrega inocua, sin cambiar credenciales ni enviar mensajes reales sin autorización.
>
> ### 2. Implementar recuperación segura y clara
> - En login, ofrecer «¿Olvidaste tu contraseña?» por email y dejar claro que el correo registrado es también el usuario. Mantener el flujo estándar de token de un solo uso, vencimiento, invalidación/rotación segura, política de contraseñas, limitación de solicitudes y respuestas genéricas que no confirmen si una cuenta existe.
> - Añadir «No recuerdo el correo de acceso» por RIF como ayuda, no como mecanismo que elige una cuenta o cambia directamente su contraseña. Normalizar/validar RIF conforme al modelo. Si existen varias cuentas afiliadas elegibles, enviar a cada buzón registrado una notificación individual con su propio enlace de reset y explicación de que cada usuario cambia solo su clave; deduplicar emails. La respuesta visible debe ser genérica y no listar, contar ni enmascarar correos/cuentas.
> - Auditar las reglas de empresa_user: excluir explícitamente cuentas administrativas/internas y cuentas que no sean recuperables como afiliados; no confiar solo en un ID fijo de rol. Si no se puede determinar de forma robusta quién es elegible o quién controla el email, no enviar masivamente: proponer fallback seguro vía soporte de Cámara y documentarlo antes de ampliar alcance.
> - Limitar abuso del endpoint RIF/email (rate limit por origen y clave normalizada; evaluar Turnstile acorde a los flujos existentes), evitar que el RIF genere un vector de spam y no permitir enumeración de empresas, usuarios o roles. Mantener autorización/gates sin debilitar el login ni la verificación del correo.
>
> ### 3. Verificar los escenarios críticos y documentar operación
> - Pruebas focalizadas para email existente/inexistente, RIF inexistente, una y múltiples cuentas afiliadas, emails duplicados, afiliado con email no verificado, vínculo con cuentas internas/admin, reintento/rate limit, token vencido/reutilizado, y reset válido; comprobar respuestas indistinguibles donde corresponda y que solo el titular del token cambia su clave.
> - Verificar la experiencia en escritorio/móvil y textos en español; cubrir también el caso «no recuerdo mi email/usuario».
> - Registrar qué puede probarse en runner y qué requiere correo del entorno; incluir evidencia de entrega sin secretos. Actualizar manual de usuario/administrador si cambia el procedimiento.
>
> **ACEPTACIÓN:** el titular puede restablecer su propia contraseña por email; el RIF ayuda a llegar a los buzones afiliados sin revelar identidades ni permitir escoger otra cuenta; cuentas internas quedan excluidas del flujo RIF; límites contra enumeración/abuso están probados; los correos llegan en el entorno habilitado; fallos y fallback quedan explicados en español. Si la elegibilidad segura por RIF no se puede demostrar, entregar esa conclusión y el fallback como decisión pendiente, sin implementar un envío inseguro.
>
> **ENTREGA:** trabajar en bloques 1→2→3 con pocos ciclos; reportar causa y decisión sobre RIF, alcance afiliado/admin, pruebas, verificación de correo, commit/SHAs y limitaciones. Terminar en READY_FOR_REVIEW conforme al protocolo.
