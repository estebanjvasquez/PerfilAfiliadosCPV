# TASK-0020 — SEC-03: pruebas operativas en staging (funcionales y adversariales)

| Campo | Valor |
|---|---|
| ID | `TASK-0020` (siguiente libre: sin archivo, rama ni commit con 0020 en ninguna rama remota) |
| Fuente | Instrucción del propietario **en el chat** de esta sesión, 2026-11-12: «1: aprobado hacer las pruebas / 2: aprobado hacer las pruebas». No hay comentario de asignación en el Issue #2 |
| Alcance | Los pendientes 2 y 3 del veredicto `6821212610` de TASK-0019: (1) pruebas funcionales de recuperación por correo y RIF, Turnstile y cambio de correo con cuentas y buzones sintéticos; (2) prueba adversarial del borde de staging (Host ajeno, `CF-Connecting-IP` suplantado) y separación de cupos entre dos clientes |
| Estado | IN_PROGRESS (2026-11-12) → READY_FOR_REVIEW (2026-11-12 ~11:25Z; partes 1 y 2 ejecutadas; datos sintéticos borrados) → **APPROVED con reservas** (`6822634878`, propietario, 2026-11-12 11:42Z) |
| Entorno | solo staging (`feature/upgrade-filament-v3` = `9d0c24d`, HSTS `max-age=86400`) |
| Límites | sin despliegue, sin cambios de código, configuración, Cloudflare, nginx ni túnel; sin cuentas ni correos de clientes; bajo volumen y solo con IPs propias (el servidor y el equipo del ejecutor); sin aumentar HSTS; sin SEC-01 ni producción |

## Plan

- **Parte 2 (adversarial, ejecutable sin más datos):**
  - Host ajeno en el borde (`Host` distinto con SNI de staging; subdominio inexistente de la zona);
  - `X-Forwarded-Host` del cliente;
  - acceso directo al origen;
  - Host ajeno tras el borde (`127.0.0.1:8080`, como lo entregaría cloudflared);
  - `CF-Connecting-IP` y `X-Forwarded-For` suplantados por el cliente (observados en nginx);
  - cupo por origen de la recuperación con dos clientes (servidor y equipo del ejecutor), con pedidos que no pasan
    Turnstile y no consumen el cupo por correo (P3).
- **Parte 1 (funcional):** necesita buzones sintéticos que el propietario pueda leer y un Turnstile resuelto por una
  persona. Se pide esa decisión al propietario antes de ejecutarla.

## Progreso

### Parte 2 — adversarial en staging (2026-11-12 ~10:45–11:00Z): **PASS**

Peticiones de bajo volumen desde dos clientes propios: el servidor (A) y el equipo del ejecutor (B). La llegada al
origen se comprobó con capturas en el bridge de la app, filtradas por un marcador por petición. Solo se imprimieron
códigos, nombres de cabecera y comparaciones; las capturas se borraron. Staging siguió en `9d0c24d`; 0 respuestas 5xx y
0 errores nuevos en el log durante la ventana.

| Prueba | Resultado | Hallazgo |
|---|---|---|
| Host ajeno con el SNI de staging, por el borde | Cloudflare **403**; no llega al origen (0 llegadas) | F-SEC03-01/02 |
| Subdominio inexistente de la zona | Cloudflare **530**; no llega al origen | F-SEC03-02 |
| `X-Forwarded-Host` y `X-Forwarded-Port` del cliente, con el Host legítimo | Llegan a nginx (Cloudflare los reenvía). La app los **ignora**: `/admin` → 302 al host canónico; la página de recuperación no menciona el host ajeno (13 referencias, todas canónicas) | F-SEC03-01 |
| Host ajeno o parecido (`evil.<host>`) tras el borde (`127.0.0.1:8080`, como cloudflared) | La app responde **400** (`TrustHosts`); el host legítimo, 200 | F-SEC03-02 |
| Acceso directo al origen desde fuera (puertos 80, 443, 8080 y 9000) | Sin conexión (se agota el tiempo) | borde |
| `CF-Connecting-IP` enviado por el cliente | Cloudflare **bloquea con 403**; no llega al origen | F-SEC03-03 |
| `X-Forwarded-For` falso | Llega como «falso, real», con `CF-Connecting-IP` = cliente real. En el contenedor vivo, con esa misma entrada, `ip()` = **cliente real** | F-SEC03-03 |
| `True-Client-IP` falso | Llega, pero la app no lo lee; `ip()` = cliente real (contenedor vivo) | F-SEC03-03 |
| `X-Real-IP` falso | No llega al origen | F-SEC03-03 |
| Cupo por origen con dos clientes («¿Olvidaste tu contraseña?», correo sintético inexistente, sin token de Turnstile) | A: 5 errores de Turnstile; el 6.º, «Demasiadas solicitudes»; sigue frenado después. **B, en la misma ventana: no frenado** (2 errores de Turnstile) | F-SEC03-03 (y orden de P3: el cupo por origen corre antes de Turnstile) |

Efecto de las pruebas: solo los cupos de las IP de A y B (ventana de 15 min). El cupo por correo no se consumió (el
correo sintético no existe y Turnstile falló antes). Sin correos enviados ni cuentas tocadas.

Riesgo aceptado (no es hallazgo nuevo): un proceso con root en el propio servidor puede conectarse a `127.0.0.1:8080` y
fijar `CF-Connecting-IP`. Es el mismo nivel de confianza que cloudflared.

### Parte 1 — funcional en staging (2026-11-12 10:47–11:25Z): **PASS**, con un tramo no ejecutado

**Decisiones del propietario (chat, 2026-11-12):**
- buzones: alias `+cpv-sec03-N` del Gmail del propietario (la dirección no se publica aquí);
- Turnstile: lo resuelve el propietario en su navegador; no se desactivó ni se usaron claves de prueba;
- datos: crear y, al terminar, borrar solo las filas sintéticas.

**Datos sintéticos** (creados en una transacción, 10:47Z):
- 2 cuentas `ZZZ-SEC03 Prueba 1/2` con rol `afiliados`, verificadas, alias `+cpv-sec03-1` y `+cpv-sec03-2`;
- la empresa `ZZZ-SEC03 EMPRESA SINTETICA`, con RIF ficticio `J-99903001-0` (libre antes de crearlo).

Observación: `Empresa::create` con un usuario autenticado vinculó automáticamente a la empresa, además de la cuenta, a
**4 usuarios del personal**. Ninguno era elegible por RIF (`eligibleForRif` = 2), así que no recibieron correo. Es
comportamiento de la aplicación, no de SEC-03.

| Paso | Quién | Evidencia | Resultado |
|---|---|---|---|
| 1. Recuperación por correo de la cuenta 1 | propietario (navegador + Turnstile) | El enlace del correo real empieza por `https://pruebas.camarapetrolera.app/` (confirmado por el propietario). Servidor: contraseña cambiada a las 10:50:47Z, `remember_token` rotado y token consumido | **PASS** (F-SEC03-01 con correo real) |
| 2. Recuperación por RIF | propietario | 2 correos, uno por alias, cada uno con su usuario («Este mensaje es solo para usted…»). Servidor: 1 token por cuenta (10:52:08Z), guardados como hash bcrypt; ningún otro token en la base | **PASS** |
| 3a. P3, pedidos sin Turnstile válido para la cuenta 2 | ejecutor (script, sin token) | 5 pedidos desde 2 IP propias: el cupo por correo de la cuenta 2 quedó en **0 intentos**; el cupo por origen sí se contó | **PASS** (F-SEC03-04) |
| 3b. P3, pedido válido para la cuenta 2 | propietario | Servidor: token nuevo a las 11:06:57Z, que reemplazó al del RIF; cupo por correo en 1. Antes de la corrección este pedido habría sido «Demasiadas solicitudes» | **PASS** |
| 4. P4, cambio del correo de la cuenta 1 en Usuarios (panel) | propietario | Servidor: correo `+cpv-sec03-3` a las 11:08:26Z; `email_verified_at` = **NULL**; el token pendiente del correo anterior (RIF, paso 2) se **borró** | **PASS** (F-SEC03-05) |
| 5. Enlace del RIF del correo anterior | propietario | Error: enlace no válido, caducado o ya utilizado | **PASS** |
| 6. Ingreso con el correo nuevo | propietario | Pantalla «Verifique su correo electrónico» para `+cpv-sec03-3`; el correo de verificación llegó con un enlace que empieza por `https://pruebas.camarapetrolera.app/` | **PASS** en la exigencia de verificar. **No ejecutado:** abrir el enlace (0 visitas a la ruta de verificación; la cuenta quedó sin verificar). Ese tramo está cubierto en aislamiento y por los controles de TASK-0017 |

**Observaciones:**
- UX: la pantalla de verificación dice «Su cuenta está creada… avise a la Cámara que se registró», texto pensado para
  registros nuevos; confunde tras un cambio de correo. No es de seguridad.
- El servidor sale a Cloudflare por IPv6, así que en la prueba de dos clientes de la parte 2 el cliente A era su IPv6.
  Sigue siendo una IP propia; la conclusión no cambia.

### Limpieza (2026-11-12 ~11:25Z, autorizada por el propietario)

Antes, inventario de todas las filas que apuntaban a los ids sintéticos (claves foráneas reales y columnas `model_id`,
`user_id`, `empresa_id`): solo `empresa_user` (6), `model_has_roles` (2) y `password_resets` (1). Borrado en una
transacción, por id, con comprobación previa de nombre y RIF, que se abortaba si algún conteo difería:

| Tabla | Antes | Borradas | Después = línea base |
|---|---|---|---|
| `users` | 370 | 2 | 368 |
| `empresas` | 407 | 1 | 406 |
| `empresa_user` | 2205 | 6 (2 sintéticas y 4 automáticas del personal) | 2199 |
| `model_has_roles` | 332 | 2 | 330 |
| `password_resets` | 1 | 1 | 0 |

Sin alias ni RIF sintético restantes. Las claves del limitador de las IP de prueba vencen solas (≤15 min / 1 h). No se
modificó ningún dato real.

## Resultado

| Hallazgo | Staging (TASK-0019 + TASK-0020) |
|---|---|
| F-SEC03-01 | **Verificado con correo real**: enlaces de recuperación y verificación con el host de staging; `X-Forwarded-Host` del cliente ignorado |
| F-SEC03-02 | **Verificado**: el borde bloquea Hosts ajenos (403/530); tras el borde, la app los rechaza (400) |
| F-SEC03-03 | **Verificado**: `CF-Connecting-IP` no se puede suplantar (Cloudflare 403); `X-Forwarded-For` y `True-Client-IP` falsos sin efecto; cupos separados entre dos clientes |
| F-SEC03-04 | **Verificado**: pedidos sin Turnstile no consumen el cupo por correo; el pedido válido posterior se acepta |
| F-SEC03-05 | **Verificado**: el cambio de correo anula la verificación, borra los tokens del correo anterior e invalida su enlace. Abrir el enlace de verificación del buzón nuevo: no ejecutado en staging |
| F-SEC03-06 | Verificado en TASK-0019 (HTTPS en el borde; HSTS de 1 día) |

Pendiente, con aprobación propia: etapas siguientes de HSTS y producción. SEC-03 no se declara cerrado aquí: el cierre
es decisión del propietario.

## Veredicto 2026-11-12: APPROVED con reservas (`6822634878`)

El propietario aprobó la **ejecución de las pruebas operativas** de TASK-0020 (entrega `6822576777`). La aprobación **no**
autoriza despliegues, cambios de infraestructura, ampliar HSTS ni producción. Staging sigue en `9d0c24d` con HSTS
`max-age=86400`.

Reservas y seguimiento:

1. **No ejecutado en staging:** abrir el enlace de verificación del buzón nuevo y confirmar su consumo y el estado final.
   Está cubierto en aislamiento, pero **no** cuenta como UAT completada en staging. Queda pendiente de una
   asignación o autorización específica.
2. **Trazabilidad:** la tarea se materializó con instrucciones explícitas del propietario en el chat (2026-11-12), sin
   comentario previo de asignación en el Issue #2. No hay asignación retrospectiva; este veredicto acepta la entrega y
   no cambia el origen de la autorización.
3. **Deuda funcional (UX), no vulnerabilidad:** la pantalla «Su cuenta está creada…» confunde cuando se pide verificar
   un correo cambiado.
4. **Observación funcional para revisión independiente:** `Empresa::create` vinculó automáticamente a 4 usuarios del
   personal; no recibieron correo por RIF. No se modifica la lógica ahora.
5. Sigue el riesgo de restauración de TASK-0019: la FK `contact_empresa_contact_id_foreign` tiene 2 filas huérfanas.
6. Los resultados sustentan la verificación operativa de F-SEC03-01..05 (F-SEC03-06, en TASK-0019, con HSTS de 1 día).
   No autorizan cerrar todos los riesgos de seguridad del proyecto ni pasar a producción.

STOP: SEC-01 sigue detenida. No se inicia ninguna tarea nueva, ni HSTS, `main` o producción, sin decisión y asignación
independientes.
