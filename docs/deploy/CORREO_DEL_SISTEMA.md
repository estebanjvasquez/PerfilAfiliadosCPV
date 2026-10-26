# Correo del sistema: configuración, aprovisionamiento y activación

> **Estado: CORREO ACTIVADO en staging el 2026-10-26**, con autorización explícita del propietario.
> - **Transporte:** `smtp` global por el relay del proveedor de correo del dominio, autorizado por la IP del
>   servidor. No hay usuario ni contraseña SMTP; TLS por STARTTLS.
> - **Remitente:** `perfilafiliados@camarapetrolera.org`.
> - **Cómo se activó:** el único cambio en vivo fue `config:cache` en el contenedor `app`, cuyo entorno ya tenía
>   esos valores. Antes se comparó en privado la configuración resuelta: solo cambiaban claves `mail.*`.
> - **Prueba:** se envió **una** prueba controlada al buzón del propietario y el proveedor la aceptó. La llegada
>   al buzón la confirma el propietario.
> - Las secciones §4–§6 conservan el procedimiento. La vuelta atrás a `log` sigue siendo la de §6.

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
| Verificación de correo al registrarse y «Pedir un nuevo enlace» | desde `6215451160`: `->emailVerification()` de Filament en `AdminPanelProvider`. Registro y reenvío → `User::sendEmailVerificationNotification()`: un aviso con el enlace firmado del panel (vence según `auth.verification.expire`, 60 min) | sí | no |
| Aviso de solicitud de revisión de categoría | `TaxonomyCategoryRequestService` → `Mail::to(...)->send(TaxonomyCategoryRequestMail)` | sí | no |
| Recuperar contraseña | **no está habilitado** en el panel (sin `->passwordReset()`) | — | — |

No hay ninguna llamada a `Mail::mailer(...)` ni configuración que cambie el mailer en tiempo de ejecución. **No hay sobrescrituras.**

### Verificación de cuentas mientras el correo no está activo (`6215451160`)

- **Qué ve el usuario:** un usuario sin verificar ve **Verifique su correo electrónico**, sin el 500 de antes, y no puede abrir ninguna otra página del panel.
  - Con el transporte `log` o `array`, la pantalla y el reenvío **no dicen que el correo se envió**. Dicen que el mensaje no llegará todavía y que se comunique con la Cámara.
  - El criterio es el mismo de las solicitudes de categoría: `TaxonomyCategoryRequestService::transportSendsExternally()`.
  - No se muestra el modo ni la configuración.
- **Con `log`, el aviso queda escrito en el log del servidor**, con su enlace firmado. Esto vale para todo correo en ese modo. Ese log no se publica.
- **Esta ronda no activa el envío.** Mientras no se configure el correo (la decisión del proveedor es aparte), una cuenta nueva no puede verificarse sola.
  - La entrega no tiene aquí un procedimiento manual de verificación para la Cámara. No se verificó ninguna cuenta real.

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
- **Caché generada, NO es el almacén:** el despliegue ejecuta `php artisan config:cache`, que escribe `bootstrap/cache/config.php` con la configuración **resuelta**, valores incluidos. Ese archivo es un artefacto generado: no se edita a mano, no se usa como fuente, no se copia y no se versiona (`bootstrap/cache` está montado desde el host y fuera de git). Si cambia el `.env`, hay que **recrear el contenedor y después regenerar la caché dentro de él** (§4). Regenerarla en el contenedor viejo vuelve a escribir los valores viejos.
- **El repositorio público** contiene solo nombres (`.env.example`, este documento). Las credenciales no van a git, ni a informes, ni a comentarios del Issue, ni a la ayuda en pantalla.
- **No confundir con los secretos del workflow de despliegue** (`CONTABO_*` en GitHub), que solo sirven para entrar al servidor por SSH. No contienen ni deben contener credenciales de correo.

## 4. Cómo aplica un cambio un operador autorizado (cambio FUTURO, no autorizado todavía)

Esta sección describe el procedimiento para cuando el propietario autorice un cambio de configuración de correo. **No se ha ejecutado** y no se ejecuta como parte de esta documentación.

### 4.1 Dos cosas distintas que hay que refrescar

| | Qué es | Qué lo actualiza | Qué **NO** lo actualiza |
|---|---|---|---|
| **Entorno del contenedor** | las variables `MAIL_*` que ve el proceso, tomadas de `env_file: .env` **al crear** el contenedor | crear un contenedor nuevo: `docker compose up` cuando lo **recrea**, o `docker compose run --rm` (contenedor efímero) | editar el `.env` en el host; `docker compose restart` (reinicia el **mismo** contenedor, con el entorno viejo) |
| **Caché de configuración de Laravel** | `bootstrap/cache/config.php`, en un montaje **compartido** desde el host; es lo que la app usa de verdad mientras exista | `php artisan config:cache` ejecutado **en un contenedor que ya tiene el entorno nuevo** | `config:cache` ejecutado en el contenedor viejo: vuelve a escribir la configuración **vieja** |

Comprobado el 2026-10-15 con un proyecto Compose desechable y valores marcadores no secretos (Compose 5.5.0 del VPS; detalle en el informe de esta ronda). Al editar el `.env`, `exec` y `restart` seguían viendo el valor viejo, mientras que `run --rm`, `up -d` (que recreó el contenedor al detectar el cambio) y `up -d --force-recreate` veían el nuevo.

Datos de esta aplicación que importan aquí:
- **La configuración está cacheada** en staging, así que la app **no** lee las variables de entorno en cada petición: lee `config.php`. Cambiar el entorno sin regenerar la caché no cambia el comportamiento, y regenerarla con el entorno viejo tampoco.
- **OPcache sí está activo en PHP-FPM.** La imagen base lo trae habilitado, con `validate_timestamps=On` y `revalidate_freq=2`, medido en vivo el 2026-10-26. Corrige una versión anterior de este documento, que decía que no estaba habilitado. Una caché regenerada surte efecto en unos 2 segundos, sin reiniciar PHP-FPM; así ocurrió en la activación del 2026-10-26.
- **nginx no usa estas variables.** Solo el servicio `app` tiene `env_file`.

### 4.2 Requisitos previos

1. Autorización explícita del propietario para ese cambio.
2. El checkout del directorio de despliegue y la imagen en uso corresponden al SHA desplegado (`git rev-parse HEAD`, y la imagen del contenedor `app` creada por el último despliegue). Así, recrear el contenedor no cambia el código.
3. Una copia de respaldo del `.env` actual, **en el servidor** y fuera de git, para poder volver atrás.
4. Aceptar una ventana breve de indisponibilidad: unos segundos mientras se recrea `app`, durante los que nginx puede responder 502.

### 4.3 Secuencia recomendada (desde el directorio de despliegue)

1. Editar el `.env` con las variables `MAIL_*`, sin copiar los valores a otra parte.
2. Validar que el archivo se lee sin errores y **sin imprimir valores**: `docker compose config --quiet`. (`docker compose config` sin `--quiet` imprime el entorno resuelto, credenciales incluidas: **no usarlo**.)
3. **Refrescar el entorno**: recrear solo `app`, con la imagen existente y sin reconstruir:
   `docker compose up -d --no-deps --no-build --force-recreate app`
   Hasta el paso 4, la app sigue usando la caché vieja: el comportamiento es el anterior, de forma consistente.
4. **Regenerar la caché en el contenedor ya refrescado:**
   `docker compose exec -T -u www-data app php artisan config:cache`
5. **Validar:**
   - `docker compose exec -T -u www-data app php < docs/deploy/mail_status_readonly.php` debe mostrar el transporte y los campos esperados como «configurado», sin valores;
   - la fecha de inicio del contenedor `app` cambió (`docker inspect --format '{{.State.StartedAt}}' $(docker compose ps -q app)`);
   - HTTP 200 en `/` y en `/admin/login`;
   - sin errores nuevos en el log de la app.

   La prueba de envío real es un paso aparte (§6).

**Sobre volver a ejecutar el workflow de despliegue:** ejecuta `config:cache` dentro de `docker compose run --rm` (un contenedor nuevo, que sí lee el `.env` actual) y luego `docker compose up -d`. En la demostración, `up -d` recreó el contenedor al cambiar el `.env`. Aun así, el workflow además hace `git reset` a la rama, reconstruye la imagen, entra en modo mantenimiento y ejecuta migraciones. **No es la vía para un cambio solo de configuración**: úsese solo cuando también esté autorizado un despliegue, y compruébese igualmente que `app` fue recreado (fecha de inicio).

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
6. **Vuelta atrás al modo sin envío:** restaurar el `.env` de respaldo, o poner `MAIL_MAILER=log`, y aplicar la **misma secuencia completa de §4.3**: `docker compose config --quiet`, recrear `app` (`up -d --no-deps --no-build --force-recreate app`), `config:cache` en el contenedor refrescado y validar que `mail_status_readonly.php` muestre `transporte=log` y `envia_fuera_del_servidor=no (simulado)`. **Editar el `.env` y ejecutar `config:cache` con `exec` en el contenedor existente NO desactiva el envío**: regenera la configuración con el entorno viejo. Hasta que la validación muestre `log`, hay que asumir que el sistema todavía puede enviar.
