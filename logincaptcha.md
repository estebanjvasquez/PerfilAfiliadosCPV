# Protección anti-bots (CAPTCHA + defensas) en Registro y Login

## Contexto

El sitio en producción sufrió un ataque de **registro masivo por bots** contra el formulario
de registro. Las cuentas creadas no obtuvieron acceso porque el sistema exige **aprobación
humana** (asignación de rol / verificación) antes de permitir el ingreso al panel. Sin embargo
el ataque sigue causando daño:

- **Bloat de la tabla `users`** con cuentas basura.
- **Abuso del SMTP propio** (`smtpout.secureserver.net`): cada registro dispara un correo de
  verificación (`MustVerifyEmail` → evento `Registered`), lo que puede llevar a que el
  proveedor de correo marque la cuenta como spam o la suspenda.
- Consumo de recursos del servidor.

Objetivo: incorporar **Cloudflare Turnstile** en registro y login, más defensas
complementarias (honeypot + rate-limiting en registro), manteniendo el **autoregistro abierto**.

## Análisis del sistema actual

- **Stack:** Laravel 9 + Filament v2 (panel en `/admin`) + `jeffgreco13/filament-breezy` para auth.
- **Formularios son Livewire** (envían por AJAX vía `wire:submit`, no POST tradicional). Esto
  obliga a validar el token del CAPTCHA **dentro del componente Livewire**, del lado servidor.
- **Registro:** `config/filament-breezy.php` → `enable_registration => true` y
  `registration_component_path => JeffGreco13\...\Auth\Register::class` (componente vendor **sin
  extender**). Ruta pública `/admin/register`. **Sin CAPTCHA ni rate-limiting.**
  - Vista ya personalizada: `resources/views/vendor/filament-breezy/register.blade.php` — renderiza `{{ $this->form }}` dentro de `wire:submit="register"`.
  - Método vendor `register()`: `vendor/jeffgreco13/filament-breezy/src/Http/Livewire/Auth/Register.php` (líneas 71-81) crea el usuario, dispara `Registered` (correo) y hace login.
- **Login:** `config/filament.php` → `auth.pages.login` apunta al **componente vendor**
  `JeffGreco13\...\Auth\Login::class` (que ya trae `WithRateLimiting` = 5 intentos/min).
  - Vista ya personalizada: `resources/views/vendor/filament-breezy/login.blade.php` con `wire:submit="authenticate"`.
  - Nota: existe `app/Filament/Pages/Login.php` con namespace
    `App\Http\Livewire\Auth\Login` **mal ubicado** (PSR-4 no lo carga) → es código muerto y **no
    está cableado**. El login activo es el del vendor.
- **Guardado de secretos:** `.env` está en `.gitignore` (no filtrado por git). ✔

## Fallas de seguridad detectadas (por severidad)

1. **[Alta] Sin CAPTCHA / anti-bot en registro (y login).** Causa raíz del ataque reportado.
2. **[Alta] Registro sin rate-limiting.** El login limita 5/min; el registro **no tiene ningún
   límite** → un bot puede crear cuentas y disparar correos sin freno.
3. **[Alta] Fuga de datos en ruta pública `xls/{empresa}`** en `routes/web.php` (líneas 42-44):
   el closure **ignora el parámetro `{empresa}`** y descarga `new JoinViewExport` (TODAS las
   filas), **sin autenticación**. Cualquiera puede exportar toda la data de afiliados. (Fuera del
   alcance CAPTCHA, pero debe corregirse.)
4. **[Media] `APP_ENV=local` en producción** (`.env` línea 2). Debe ser `production` (afecta
   optimizaciones y comportamiento de seguridad). `APP_DEBUG=false` está bien.
5. **[Media] `APP_URL=https://localhost`** (`.env` línea 5). URL incorrecta → enlaces de
   verificación de correo y generación de URLs firmadas apuntan a `localhost`.
6. **[Media] Flood de correos de verificación** sin throttle → riesgo de blacklist del SMTP.
7. **[Baja] Política de contraseña débil:** sólo `min:8` en `filament-breezy.php` (sin
   complejidad ni chequeo de contraseñas comprometidas).
8. **[Baja] Higiene del repo:** archivos de respaldo con lógica/credenciales antiguas
   (`mail.php.bk`, `config/filament-shield.php.bak`, `composer.*-2510/2011/1911`,
   `app/Filament-29-11-22/`, `resources-bk`, múltiples `error_log`). Ruido y superficie de
   exposición; conviene retirar del deploy.

## Solución propuesta

### A. CAPTCHA — Cloudflare Turnstile (elegido)

Verificación server-side dentro de los componentes Livewire. `guzzlehttp/guzzle` ya está
disponible como dependencia, así que la llamada a `siteverify` se hace directo desde Laravel
(no se requiere Cloudflare Worker).

**Preparación de credenciales**
- Crear el widget Turnstile (se puede apoyar en el skill `turnstile-spin`, que crea el widget
  vía API de Cloudflare y entrega snippets front). Turnstile **no** exige que el sitio esté en
  Cloudflare.
- `.env` (y `.env.example`): `TURNSTILE_SITE_KEY=...`, `TURNSTILE_SECRET_KEY=...`.
- `config/services.php`: añadir bloque `'turnstile' => ['site_key' => env('TURNSTILE_SITE_KEY'), 'secret_key' => env('TURNSTILE_SECRET_KEY')]`.

**Helper de verificación reutilizable** — nuevo `app/Support/Turnstile.php` (o un trait
`app/Http/Livewire/Concerns/VerifiesTurnstile.php`):
- Método `verify(string $token, ?string $ip): bool` que hace `POST` a
  `https://challenges.cloudflare.com/turnstile/v0/siteverify` con `secret`, `response`, `remoteip`
  usando el cliente Guzzle / `Http::asForm()->post(...)`, y retorna `data['success']`.

**Partial Blade reutilizable** — nuevo `resources/views/partials/turnstile.blade.php`:
- Carga el script `https://challenges.cloudflare.com/turnstile/v0/api.js` (una vez).
- `<div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-callback="onTurnstile">` envuelto en `wire:ignore` (clave: evita que Livewire destruya el widget en re-render).
- Callback JS que hace `@this.set('ts_token', token)` para sincronizar el token con el componente Livewire; resetear el widget en fallo (`turnstile.reset()`).

**Registro** — nuevo `app/Http/Livewire/Auth/Register.php` extendiendo el vendor:
```php
class Register extends \JeffGreco13\FilamentBreezy\Http\Livewire\Auth\Register {
    use WithRateLimiting;            // rate-limit del registro
    public string $ts_token = '';    // token Turnstile (wire:model)
    public string $website = '';     // honeypot (debe quedar vacío)

    public function register() {
        if (filled($this->website)) { abort(422); }          // bot llenó honeypot
        try { $this->rateLimit(5); } catch (TooManyRequestsException $e) { /* ValidationException throttled */ }
        if (!app(Turnstile::class)->verify($this->ts_token, request()->ip())) {
            throw ValidationException::withMessages(['ts_token' => __('Verificación anti-bot fallida, intenta de nuevo.')]);
        }
        return parent::register();   // reusa creación de usuario + evento vendor
    }
}
```
- Apuntar `config/filament-breezy.php` → `registration_component_path` a esta clase.
- Editar `resources/views/vendor/filament-breezy/register.blade.php`: incluir
  `@include('partials.turnstile')`, el input honeypot oculto (`website`, con `autocomplete=off`,
  `tabindex=-1`, oculto por CSS) y `@error('ts_token')`.

**Login** — nuevo `app/Http/Livewire/Auth/Login.php` extendiendo el vendor
`JeffGreco13\...\Auth\Login` (ya trae rate-limit):
- Añadir `public string $ts_token`, y sobrescribir `authenticate()` para verificar Turnstile
  **antes** de `parent::authenticate()` (o replicar el flujo llamando a la verificación primero).
- Apuntar `config/filament.php` → `auth.pages.login` a esta nueva clase.
- Editar `resources/views/vendor/filament-breezy/login.blade.php` con el mismo
  `@include('partials.turnstile')` y `@error`.
- Retirar/renombrar el `app/Filament/Pages/Login.php` muerto para evitar confusión (mover la
  lógica de password-reveal a la nueva clase si se desea conservarla).

> Nota Livewire: el widget debe ir en `wire:ignore` y el token sincronizarse por callback JS
> (`@this.set(...)`). Tras un error de validación hay que **resetear** el widget
> (`turnstile.reset()`) porque el token es de un solo uso.

### B. Defensas complementarias (incluidas)

- **Honeypot** en el registro (campo `website` invisible) — descrito arriba.
- **Rate-limiting del registro** (`WithRateLimiting`, 5/min por IP) — descrito arriba.
- (Opcional recomendado) Throttle de reenvío de verificación de correo.

### C. Correcciones de seguridad recomendadas (fuera del CAPTCHA, mismo esfuerzo bajo)

- Corregir la ruta `xls/{empresa}`: exigir auth y respetar el parámetro (o eliminarla si no se usa).
- `.env`: `APP_ENV=production` y `APP_URL=https://<dominio-real>`.
- Reforzar `password_rules` en `filament-breezy.php` (p. ej. `Password::min(8)->letters()->numbers()`).

## Comparación de opciones de CAPTCHA (justificación de la elección)

| Opción | Fricción usuario | Privacidad | Costo | Encaje Livewire/Filament v2 |
|---|---|---|---|---|
| **Cloudflare Turnstile (elegido)** | Muy baja (sin puzzles) | Alta | Gratis, ilimitado | Bueno (widget + siteverify server-side con Guzzle ya presente) |
| Google reCAPTCHA v3 | Invisible pero por score (puede bloquear reales; requiere ajuste de umbral) | Baja (datos a Google) | Gratis con límites | Bueno |
| hCaptcha | Media (a veces puzzle) | Alta | Gratis nivel básico | Bueno |

**Elegido: Turnstile** — mejor balance fricción/privacidad/costo y hay skill `turnstile-spin`
para acelerar el alta del widget y snippets.

## Archivos a crear / modificar

**Crear**
- `app/Support/Turnstile.php` (o trait en `app/Http/Livewire/Concerns/`).
- `resources/views/partials/turnstile.blade.php`.
- `app/Http/Livewire/Auth/Register.php` (extiende vendor Register).
- `app/Http/Livewire/Auth/Login.php` (extiende vendor Login).

**Modificar**
- `config/services.php` — bloque `turnstile`.
- `config/filament-breezy.php` (línea ~73) — `registration_component_path`.
- `config/filament.php` (líneas 94-98) — `auth.pages.login`.
- `resources/views/vendor/filament-breezy/register.blade.php` — widget + honeypot.
- `resources/views/vendor/filament-breezy/login.blade.php` — widget.
- `.env` / `.env.example` — claves Turnstile (+ correcciones `APP_ENV`/`APP_URL`).
- `routes/web.php` (líneas 42-44) — corregir/eliminar ruta `xls`.

## Verificación (end-to-end)

1. `php artisan config:clear && php artisan view:clear` tras editar configs/vistas.
2. **Registro:** abrir `/admin/register` → el widget Turnstile debe renderizar. Enviar sin
   resolver → error `ts_token`. Resolver y registrar → cuenta creada + correo de verificación.
3. **Bot simulado:** POST/emisión Livewire con `website` (honeypot) lleno → 422, sin crear usuario.
   Repetir registro >5 veces/min desde misma IP → mensaje throttled.
4. **Login:** `/admin/login` con widget; sin token → falla; con token + credenciales válidas → entra.
5. Confirmar `data-callback` sincroniza token (probar tras un error de validación que el widget
   se **resetee** y permita reintentar).
6. Confirmar que la clave secreta **no** aparece en el HTML (sólo `site_key`), y que
   `siteverify` se ejecuta server-side (revisar logs / dd temporal).

## Estimación de costos

> Nota: el esfuerzo se estima en horas; el costo monetario depende de la tarifa del
> desarrollador. Se muestran tres escenarios de tarifa/hora en USD como referencia
> (LATAM freelance): **Junior $15/h**, **Mid $30/h**, **Senior $50/h**. Ajusta a tu contexto.
> El servicio **Cloudflare Turnstile es gratuito e ilimitado** — sin costo de licencia.

### 1. Desarrollo (una sola vez)

| Tarea | Esfuerzo |
|---|---|
| Alta del widget Turnstile en Cloudflare + claves en `.env`/`config` | 0.5 h |
| Helper `Turnstile.php` (verificación server-side con Guzzle) | 1.0 h |
| Partial Blade reutilizable del widget (con `wire:ignore` + callback JS) | 1.5 h |
| Extender componente Livewire **Register** (CAPTCHA + honeypot + rate-limit) + cablear config | 2.0 h |
| Extender componente Livewire **Login** (CAPTCHA) + cablear config | 1.5 h |
| Correcciones de seguridad C (ruta `xls`, `APP_ENV`/`APP_URL`, `password_rules`) | 1.5 h |
| Pruebas end-to-end (registro, login, bot simulado, reset del widget) | 2.0 h |
| **Subtotal desarrollo** | **~10 h** |

Con margen de imprevistos (~20 %): **10–12 h**.

| Escenario | Costo desarrollo (10–12 h) |
|---|---|
| Junior ($15/h) | **$150 – $180** |
| Mid ($30/h) | **$300 – $360** |
| Senior ($50/h) | **$500 – $600** |

### 2. Despliegue (una sola vez)

| Tarea | Esfuerzo |
|---|---|
| Cargar claves Turnstile en `.env` de producción | 0.25 h |
| Deploy de archivos + `php artisan config:clear && view:clear` (y `config:cache` si aplica) | 0.5 h |
| Verificación en producción (smoke test registro/login) | 0.75 h |
| **Subtotal despliegue** | **~1.5 h** → **$25 – $75** según tarifa |

- Sin costo de infraestructura nueva: se usa el hosting actual (GoDaddy/secureserver).
- Turnstile no requiere que el sitio esté detrás de Cloudflare → **$0** de infraestructura.

### 3. Mantenimiento (recurrente)

| Concepto | Frecuencia | Esfuerzo/costo |
|---|---|---|
| Licencia Turnstile | — | **$0** (gratis e ilimitado) |
| Monitoreo básico (revisar que el widget carga y que no crecen registros basura) | Mensual | ~0.5 h/mes |
| Ajustes menores (rotar claves, cambiar modo del widget managed/invisible) | Ocasional | ~0.5–1 h por evento |
| Compatibilidad al actualizar `filament-breezy`/Filament (revisar que los `extends` sigan válidos) | Por upgrade | ~1–2 h por upgrade |

**Costo de mantenimiento estimado:** **$10 – $30/mes** en escenario mid, o bajo un
acuerdo de soporte por horas. Sin costos de licencia ni de servicio de terceros.

### Resumen total

| Fase | Rango (Mid $30/h) |
|---|---|
| Desarrollo | $300 – $360 |
| Despliegue | ~$45 |
| Mantenimiento | ~$10 – $30/mes |
| **Puesta en marcha (dev + deploy)** | **~$345 – $405 una sola vez** |

> El costo evitado (daño del ataque: abuso de SMTP con riesgo de blacklist del correo
> corporativo, bloat de BD y consumo de recursos) supera ampliamente esta inversión única.
