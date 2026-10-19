# Descargas del perfil de una empresa (PDF y XLS): acceso, errores y caché

Nota para la Cámara y los operadores. Se agregó con CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES (Issue #2 `6264837159`).

## Rutas

| Ruta | Qué hace |
|---|---|
| `/admin/descargas/empresas/{id}/pdf` (`filament.admin.export.pdf`) | perfil completo de la empresa en PDF (`perfil-<rif>.pdf`, inline) |
| `/admin/descargas/empresas/{id}/xlsx` (`filament.admin.export.xls`) | la fila de la empresa en Excel (`perfil-<rif>.xlsx`); no tiene botón en la interfaz |
| `/pdf/{id}`, `/xls/{id}` (antes) | **solo redirigen** a las rutas de arriba, sin consultar la base. Los enlaces viejos siguen sirviendo, con las mismas reglas |

## Quién puede descargar

- **Invitado:** va al login del panel.
- **Usuario sin verificar:** va a «Verifique su correo electrónico».
- **Puede descargar** quien cumple las tres condiciones (`EmpresaPolicy::export`):
  - tiene el permiso `view_empresa`;
  - está **vinculado** a esa empresa en `empresa_user`;
  - tiene el correo verificado.
- **No hay excepción para administradores.** El personal de la Cámara descarga las empresas a las que está vinculado, y usa los reportes generales de siempre (páginas con permiso de Shield).
- **No existe** un permiso de «exportar cualquier empresa».
- **Todo lo demás** (otra empresa, sin empresa, sin permiso, id inexistente o inválido) da el mismo **404 «Archivo no disponible»**, en HTML o en JSON.
  - No dice si la empresa existe ni de quién es.
  - Se responde **antes** de consultar las vistas de reporte o de generar el archivo.

Para dar acceso a una persona: vincular su usuario a la empresa (pestaña Usuarios, o como se hace hoy) y que su rol tenga `view_empresa`. No hay nada que configurar en las descargas.

## Páginas de error

- **`resources/views/errors/404.blade.php`:** «Página no encontrada», neutral, para cualquier 404 del sitio.
- **`errors/export-not-available.blade.php`:** «Archivo no disponible», para las descargas.

Las dos están en español y son HTML autocontenido: no muestran datos, rutas, permisos ni detalles técnicos. Producción y staging deben seguir con `APP_DEBUG=false` (staging lo tenía así el 2026-10-19, lectura de configuración).

## Caché

- Las descargas responden `Cache-Control: private, no-store, max-age=0`, `Pragma: no-cache` y `X-Content-Type-Options: nosniff`. Las rutas nuevas no terminan en `.pdf`/`.xlsx`, porque algunos CDN guardan en caché por extensión.
- Antes, el XLS respondía `Cache-Control: public`. Estas cabeceras **no borran** copias que un caché compartido hubiera guardado antes.
- Staging pasa por Cloudflare (la página de login responde `cf-cache-status: DYNAMIC`). En esta entrega no se revisaron ni se cambiaron sus reglas.
- **Acción aparte, del propietario:** si existe alguna regla «Cache Everything» o similar para `/xls/*` o `/pdf/*`, purgar esas rutas en Cloudflare después de desplegar.
