# Correo del sistema: configuración, aprovisionamiento y activación

> **Estado: ACTIVACIÓN GLOBAL DEL CORREO PENDIENTE.**
> Se espera que el propietario entregue el proveedor, la configuración y las credenciales, y que autorice explícitamente una activación controlada.
> Mientras tanto, staging está en **modo sin envío** (`MAIL_MAILER=log`, verificado el 2026-10-15 con `mail_status_readonly.php`). Ningún correo sale del servidor.

Este documento describe el mecanismo **real** que hoy usa la aplicación. Solo contiene **nombres** de variables y ubicaciones lógicas, **nunca valores**. Las credenciales viven fuera de git (ver §3).

## 1. Una sola configuración global

Toda la aplicación envía con **el mailer por defecto de Laravel**. No hay un segundo almacén SMTP ni credenciales en código.

- **Archivo que traduce variables a configuración:** `config/mail.php`.
  - `default` ← `MAIL_MAILER`. El valor por defecto del archivo es `smtp`; en staging es `log`.
  - `mailers.smtp`:
    - `transport` = `smtp`;
    - `host` ← `MAIL_HOST`;
    - `port` ← `MAIL_PORT`;
    - `encryption` ← `MAIL_ENCRYPTION`;
    - `username` ← `MAIL_USERNAME`;
    - `password` ← `MAIL_PASSWORD`;
    - `local_domain` ← `MAIL_EHLO_DOMAIN` (opcional).
  - `from.address` ← `MAIL_FROM_ADDRESS`; `from.name` ← `MAIL_FROM_NAME`.
  - Otros mailers definidos y **no usados hoy**: `ses`, `mailgun`, `postmark`, `sendmail` (`MAIL_SENDMAIL_PATH`), `log` (`MAIL_LOG_CHANNEL`), `array`, `failover`. Los mailers de proveedor (`ses`, `mailgun`, `postmark`) toman sus credenciales de `config/services.php` y necesitan su paquete; elegir uno de ellos es parte de la decisión del proveedor.
- **Versión instalada:** Laravel 12 con `symfony/mailer`. Las variables de arriba son las que lee `config/mail.php` de este repositorio. La plantilla de nombres está en `.env.example`.

### Flujos que envían correo (inventario)

| Flujo | Cómo envía | Usa el mailer por defecto | ¿Lo sobrescribe? |
|---|---|---|---|
| Verificación de correo al registrarse | evento `Registered` → `SendEmailVerificationNotification` (`EventServiceProvider`); el panel exige correo verificado (`EnsureEmailIsVerified` en `AdminPanelProvider`) | sí | no |
| Aviso de solicitud de revisión de categoría | `TaxonomyCategoryRequestService` → `Mail::to(...)->send(TaxonomyCategoryRequestMail)` | sí | no |
| Recuperar contraseña | **no está habilitado** en el panel (sin `->passwordReset()`) | — | — |

No hay ninguna llamada a `Mail::mailer(...)` ni configuración que cambie el mailer en tiempo de ejecución. **No hay sobrescrituras.**

### Dos cosas distintas que no hay que confundir

| | Qué es | Dónde vive | Quién lo cambia |
|---|---|---|---|
| **Transporte global** (cómo sale el correo) | `MAIL_*`: modo, servidor, credenciales y remitente | `.env` del entorno (§3), fuera de git | el operador del servidor |
| **Destinatario de solicitudes** (a quién avisar) | correo responsable de las solicitudes de categoría (configuración de negocio) | base de datos, singleton de configuración; se edita en **Taxonomía CPV → Configuración de solicitudes** | Super Admin, desde el panel |

Configurar el destinatario **no** activa el envío, y activar el envío **no** fija el destinatario.

## 2. Consecuencias del modo sin envío

- **Avisos de solicitudes:** se registran como **simulados** (o como **fallidos** si no hay destinatario). La solicitud se guarda igual.
- **Verificación de correo:** un usuario que se registra **no recibe** el correo de verificación, y el panel exige un correo verificado. El panel no ofrece hoy una acción para que la Cámara marque a un usuario como verificado. **Hay que resolverlo al activar el correo** (ver §6).

## 3. Dónde se aprovisionan los valores

- **Fuente persistente (staging):** el archivo `.env` del directorio de despliegue en el VPS de staging. Está fuera de git (el repo lo ignora). Lo mantiene el propietario o el operador del servidor. Producción tendrá su propio `.env` equivalente cuando exista.
- **Cómo llega al contenedor:** `docker-compose.yml` declara `env_file: .env` para el servicio `app`, así que el contenedor recibe esas variables como entorno.
- **Caché generada, NO es el almacén:** el despliegue ejecuta `php artisan config:cache`, que escribe `bootstrap/cache/config.php` con la configuración **resuelta**, valores incluidos. Ese archivo es un artefacto generado: no se edita a mano, no se usa como fuente, no se copia y no se versiona (`bootstrap/cache` está montado desde el host y fuera de git). Si cambia el `.env`, hay que regenerar la caché, porque si no la app sigue usando los valores viejos.
- **El repositorio público** contiene solo nombres (`.env.example`, este documento). Las credenciales no van a git, ni a informes, ni a comentarios del Issue, ni a la ayuda en pantalla.
- **No confundir con los secretos del workflow de despliegue** (`CONTABO_*` en GitHub), que solo sirven para entrar al servidor por SSH. No contienen ni deben contener credenciales de correo.

## 4. Cómo aplica un cambio un operador autorizado

1. Editar el `.env` del directorio de despliegue con las variables `MAIL_*`, en el servidor y sin copiar los valores a ninguna otra parte.
2. Regenerar la configuración dentro del contenedor de la app, como `www-data`:
   `docker compose exec -T -u www-data app php artisan config:cache`
   Otra opción: volver a ejecutar el workflow de despliegue, que también ejecuta `config:cache`.
3. Validar con el chequeo seguro (§5). No hace falta reiniciar el contenedor: PHP-FPM lee la caché en cada petición.

## 5. Validación segura (sin valores)

```
docker compose exec -T -u www-data app php < docs/deploy/mail_status_readonly.php
```

Informa:
- `mailer_por_defecto`, `transporte` y `envia_fuera_del_servidor`;
- `configuracion_cacheada`;
- remitente: `configurado`/`falta`;
- para smtp: host, puerto, cifrado, usuario y contraseña, cada uno como `configurado`/`falta`.

**Nunca imprime valores**, no envía correo, no abre conexiones y no toca la base de datos.

**No usar** como validación `env`, `printenv`, `cat .env`, `php artisan config:show mail`, `tinker` ni la lectura de `bootstrap/cache/config.php`: todos exponen valores.

## 6. Activación futura: requisitos previos (no autorizada todavía)

1. **Decisiones del propietario:** proveedor (correo corporativo o servicio transaccional), dirección y dominio del remitente, y destinatario responsable.
2. **Dominio del remitente:** SPF, DKIM y DMARC configurados para que los correos no caigan en no deseado.
3. **Aprovisionamiento seguro** de las credenciales en el `.env` del entorno (§3–§4). Nunca por chat ni por el Issue.
4. **Prueba controlada:** un envío a una dirección del propietario, confirmando que llega. El panel solo puede afirmar que el servidor «aceptó» el mensaje; la llegada al buzón la confirma una persona.
5. **Riesgos a revisar antes de activar:**
   - La reserva de 10 minutos del reintento de avisos **no garantiza un único envío** si el proceso cae después de que el servidor aceptó el mensaje, o si el transporte falla de forma ambigua.
   - Los avisos **simulados** no se pueden reintentar hoy.
   - Hay que decidir si se reintenta la solicitud **#1**.
   - Los usuarios registrados durante el modo sin envío no pudieron verificar su correo.
6. **Vuelta atrás:** poner `MAIL_MAILER=log` en el `.env` y ejecutar `config:cache` (§4). La app vuelve a no enviar, y los avisos quedan como simulados.
