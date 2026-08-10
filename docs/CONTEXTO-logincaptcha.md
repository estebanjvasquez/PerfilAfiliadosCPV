# Contexto para retomar — CAPTCHA anti-bots (Registro + Login)

> Archivo de retomada rápida. El plan completo (análisis, código, costos) está en
> **[logincaptcha.md](logincaptcha.md)**. Este documento resume el estado para reanudar sin
> releer todo.

## Estado actual

- **Fase:** Plan aprobado internamente. **En espera de aprobación del cliente** para implementar.
- **Implementación:** NO iniciada. Ningún archivo de código modificado aún.
- **Al retomar:** cuando el cliente apruebe → ejecutar la sección "Solución propuesta" y
  "Archivos a crear / modificar" de `logincaptcha.md`.

## Decisiones ya tomadas (no volver a preguntar)

- **CAPTCHA:** Cloudflare Turnstile (gratis, ilimitado, sin puzzles, no requiere estar detrás de CF).
- **Registro:** se mantiene **abierto** + CAPTCHA + honeypot + rate-limiting.
- **Alcance:** CAPTCHA en **registro Y login**.
- **Verificación:** server-side dentro de los componentes Livewire, con Guzzle (ya instalado) →
  `siteverify`. No se requiere Cloudflare Worker.

## Causa raíz del ataque

Formulario de registro Livewire (`/admin/register`) **sin CAPTCHA ni rate-limiting**. Bots crean
cuentas basura → bloat de `users` + abuso del SMTP corporativo (correos de verificación por
`MustVerifyEmail`) con riesgo de blacklist. Las cuentas no logran acceso (requieren aprobación
humana / rol), pero el daño colateral persiste.

## Datos clave del sistema (para no re-investigar)

- Laravel 9 + Filament v2 (panel `/admin`) + `jeffgreco13/filament-breezy` (auth).
- **Registro activo:** componente vendor `JeffGreco13\...\Auth\Register` (SIN extender), cableado
  en `config/filament-breezy.php` → `registration_component_path`. Vista custom en
  `resources/views/vendor/filament-breezy/register.blade.php`.
- **Login activo:** componente vendor `JeffGreco13\...\Auth\Login` (ya trae `WithRateLimiting`
  5/min), cableado en `config/filament.php` (líneas 94-98) → `auth.pages.login`. Vista custom en
  `resources/views/vendor/filament-breezy/login.blade.php`.
- **OJO — código muerto:** `app/Filament/Pages/Login.php` tiene namespace
  `App\Http\Livewire\Auth\Login` mal ubicado (PSR-4 no lo carga). NO está en uso. Contiene la
  lógica de password-reveal (rescatable si se quiere conservar).

## Trabajo a realizar (resumen; detalle en logincaptcha.md)

**Crear:** `app/Support/Turnstile.php` (helper verify), `resources/views/partials/turnstile.blade.php`
(widget con `wire:ignore` + callback JS `@this.set('ts_token', token)`),
`app/Http/Livewire/Auth/Register.php` (extiende vendor: honeypot + rate-limit + Turnstile),
`app/Http/Livewire/Auth/Login.php` (extiende vendor: Turnstile).

**Modificar:** `config/services.php` (bloque turnstile), `config/filament-breezy.php`
(registration_component_path), `config/filament.php` (auth.pages.login), las 2 vistas blade
(incluir partial + honeypot + `@error`), `.env`/`.env.example` (claves TURNSTILE_*), `routes/web.php`
(corregir ruta `xls`).

## Correcciones de seguridad adicionales (aprobadas, fuera de CAPTCHA)

1. **`routes/web.php` líneas 42-44** — ruta pública `xls/{empresa}` ignora el parámetro y exporta
   TODA la data de afiliados **sin auth** (fuga de datos). Exigir auth + respetar parámetro, o eliminar.
2. **`.env`** — `APP_ENV=local` → `production`; `APP_URL=https://localhost` → dominio real.
3. **`config/filament-breezy.php`** — reforzar `password_rules` (hoy sólo `min:8`).

## Requisitos antes de implementar

- Obtener **TURNSTILE_SITE_KEY** y **TURNSTILE_SECRET_KEY** del cliente (o crear widget vía skill
  `turnstile-spin`).
- Tras editar configs/vistas: `php artisan config:clear && php artisan view:clear`.

## Costos (referencia rápida)

Puesta en marcha (dev + deploy) **~$345–$405 una sola vez** (escenario Mid $30/h; 10–12 h dev + 1.5 h deploy).
Mantenimiento ~$10–$30/mes. Turnstile **$0** de licencia. Detalle en `logincaptcha.md`.
