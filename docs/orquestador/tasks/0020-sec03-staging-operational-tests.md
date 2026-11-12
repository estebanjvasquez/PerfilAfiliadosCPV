# TASK-0020 — SEC-03: pruebas operativas en staging (funcionales y adversariales)

| Campo | Valor |
|---|---|
| ID | `TASK-0020` (siguiente libre: sin archivo, rama ni commit con 0020 en ninguna rama remota) |
| Fuente | Instrucción del propietario **en el chat** de esta sesión, 2026-11-12: «1: aprobado hacer las pruebas / 2: aprobado hacer las pruebas». No hay comentario de asignación en el Issue #2 |
| Alcance | Los pendientes 2 y 3 del veredicto `6821212610` de TASK-0019: (1) pruebas funcionales de recuperación por correo y RIF, Turnstile y cambio de correo con cuentas y buzones sintéticos; (2) prueba adversarial del borde de staging (Host ajeno, `CF-Connecting-IP` suplantado) y separación de cupos entre dos clientes |
| Estado | **IN_PROGRESS** (2026-11-12) |
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

### Parte 1 — funcional (correo, RIF, Turnstile, cambio de correo)

Pendiente de decisiones del propietario: qué buzones sintéticos usar, quién resuelve Turnstile en el navegador, y la
creación y posterior borrado de las cuentas y la empresa sintéticas en la base de staging.
