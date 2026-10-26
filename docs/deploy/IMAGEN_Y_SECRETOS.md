# Imagen de la aplicación y secretos: dónde vive cada cosa

> Este documento contiene **solo nombres** de variables, rutas lógicas y propósitos, **nunca valores**. El
> repositorio es público.

## 1. Regla

La imagen de la aplicación se construye **solo con código fuente**. La configuración y los secretos llegan
**en ejecución**, desde el entorno del despliegue. Ninguno queda en una capa de la imagen.

- **`.dockerignore`** deja fuera:
  - los metadatos del repositorio: `.git`, `.github`, `.claude`;
  - **todas** las variantes de `.env`, a cualquier profundidad, salvo la plantilla de nombres `.env.example`;
  - credenciales y llaves: `auth.json`, `*.key`, `*.pem`, `*.p12`, `*.pfx`, `*.ppk`, `id_rsa*`, `id_ed25519*`, `*credential*`, `*secret*`;
  - volcados, respaldos y logs: `*.sql`, `*.bak`, `*.log`, `error_log`, copias `composer.*-*`;
  - el estado generado: `vendor`, `node_modules`, el contenido de `bootstrap/cache` y de `storage` (salvo sus `.gitignore`);
  - los contextos que no sirven tráfico: `audit`, `tests`, `docs`, `docker/test`.
- **El `Dockerfile` falla la build** si alguna de las rutas críticas llega igualmente al contexto. Así se cubre el
  caso de que alguien cambie o rompa `.dockerignore`. La build imprime solo nombres de archivo, nunca contenido.
- **Excluir no repara imágenes viejas.** Un archivo que entró en una capa sigue en la imagen aunque una capa
  posterior lo borre. Las imágenes construidas antes de este cambio **contienen el `.env` del servidor** de su
  momento (§5).

## 2. Dónde vive cada secreto

| Nombre (solo el nombre) | Propósito | Dónde vive | Cómo llega a la app |
|---|---|---|---|
| `APP_KEY` | cifrado de Laravel (sesiones, cookies, URLs firmadas) | `.env` del directorio de despliegue del servidor | `env_file: .env` (`docker-compose.yml`) |
| `DB_PGSQL_HOST`, `DB_PGSQL_PORT`, `DB_PGSQL_DATABASE`, `DB_PGSQL_USERNAME`, `DB_PGSQL_PASSWORD`, `DB_PGSQL_SSLMODE` | base de datos PostgreSQL externa | ídem | ídem |
| `OPENAI_API_KEY` (y `OPENAI_EMBEDDING_MODEL`) | embeddings del buscador | ídem | ídem |
| `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` | captcha | ídem | ídem |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` (y `AWS_*`) | disco S3 si se usa | ídem | ídem |
| `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER` | broadcasting si se usa | ídem | ídem |
| `MAIL_*` | transporte de correo global y remitente (ver [`CORREO_DEL_SISTEMA.md`](CORREO_DEL_SISTEMA.md)) | ídem | ídem |
| `CONTABO_HOST`, `CONTABO_USER`, `CONTABO_SSH_KEY` | **solo** acceso SSH del workflow de despliegue | secretos de GitHub Actions | el workflow; **nunca** llegan a la app |

Sobre el `.env` del servidor:
- Está fuera de git y solo lo puede leer root (permiso `600`).
- Tiene el mismo conjunto de nombres que `.env.example` más las variables de correo. El 2026-10-26 no había
  ninguna variable que existiera solo en la imagen.

## 3. Cómo llegan en ejecución

1. **Entorno del contenedor.** El servicio `app` declara `env_file: .env`, y Compose lo lee **al crear** el
   contenedor:
   - `docker compose up` que recrea, o `docker compose run --rm`, toman el `.env` actual;
   - `restart` o `exec` mantienen el entorno con el que se creó.
2. **Caché de configuración.** Es `bootstrap/cache/config.php`, montado desde el host y fuera de git.
   - La escribe `php artisan config:cache`, con los valores **resueltos**, secretos incluidos.
   - Mientras existe, la app usa **la caché**, no el entorno.
   - Es un artefacto generado. **No es el almacén**: no se edita, no se copia, no se versiona y no se adjunta a
     informes.
3. **Sin `.env` en la imagen.** La app resuelve todo desde el entorno del contenedor, y Laravel no necesita el
   archivo.
4. **OPcache está activo en PHP-FPM**, con `validate_timestamps=On` y `revalidate_freq=2`. Una caché regenerada
   surte efecto en unos 2 segundos, sin reiniciar PHP-FPM. Esto se midió en vivo el 2026-10-26.

### Montajes del host (no forman parte de la imagen)

| Montaje | Qué contiene | Consecuencia |
|---|---|---|
| `./storage` → `storage` | logs, sesiones, caché de vistas, archivos subidos | la imagen solo trae la estructura vacía |
| `./bootstrap/cache` → `bootstrap/cache` | `config.php`, `packages.php`, `services.php` | tapa lo que `package:discover` generó en la build |
| `./public` → `public` (y `:ro` en nginx) | assets publicados por `filament:assets` | los assets generados viven en el host, no en la imagen |

**Pruebas aisladas.** Una prueba de imagen **no** usa estos montajes. Usa copias en un directorio temporal o
ningún montaje. Así no puede modificar los assets ni la caché de configuración del entorno vivo.

### Build y `package:discover`

- `composer dump-autoload` corre `package:discover` **durante la build**, sin `.env`, sin red hacia la app, sin
  base de datos y sin correo. Solo lee `vendor/composer/installed.json`.
- El despliegue lo repite dentro de un contenedor efímero, con el entorno real, y escribe en el
  `bootstrap/cache` montado.

## 4. Rotar un secreto o reconstruir

1. Cambiar el valor en el `.env` del servidor. No se copia a ningún otro sitio.
2. Validar sin imprimir valores con `docker compose config --quiet`. **Nunca** usar `config` sin `--quiet`: imprime
   el entorno resuelto.
3. Recrear `app` con la imagen existente: `docker compose up -d --no-deps --no-build --force-recreate app`.
4. Regenerar la caché **en el contenedor recreado**:
   `docker compose exec -T -u www-data app php artisan config:cache`.
5. Verificar con los scripts de solo lectura de `docs/deploy/` (por ejemplo `mail_status_readonly.php`).

Para rotar un secreto **no hace falta reconstruir la imagen**: la imagen no lo contiene. Si un secreto estuvo en
una imagen vieja (§5), hay que rotarlo **además** de restringir o eliminar esa imagen.

## 5. Imágenes anteriores con configuración embebida

- Todas las imágenes de la aplicación construidas antes de este cambio llevan dentro el `.env` y el `.git` del
  checkout del servidor de su momento. Entre ellas están la imagen en servicio y los puntos de recuperación.
- Quedan **restringidas al servidor** hasta que el propietario decida su ciclo de vida:
  - no se suben a ningún registro;
  - no se exportan (`docker save`) fuera del servidor;
  - no se adjuntan volcados de su contenido ni de su configuración.
- El inventario y la propuesta de limpieza viven en el repositorio privado de infraestructura, no aquí.
