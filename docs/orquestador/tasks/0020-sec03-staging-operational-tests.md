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

(se completa durante la ejecución)
