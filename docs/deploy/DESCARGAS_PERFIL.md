# Descargas del perfil de una empresa (PDF y XLS): acceso, errores y caché

Nota para la Cámara y los operadores. Se agregó con CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES (Issue #2 `6264837159`).

## Rutas

| Ruta | Qué hace |
|---|---|
| `/admin/descargas/empresas/{id}/pdf` (`filament.admin.export.pdf`) | perfil completo de la empresa en PDF, como descarga (`perfil-<rif>.pdf`). Con `?secciones=a,b` (opcional), solo esas secciones (`perfil-<rif>-parcial.pdf`; ver abajo) |
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

## Perfil completo y secciones (CPV-COMPLETE-COMPANY-PDF, `6270558322`)

- **Dónde:** en **Empresas**, cada fila tiene **Perfil completo (PDF)** y **Elegir secciones**; la ficha de la empresa tiene **Descargar perfil completo (PDF)**. **Exportar listado** (acción masiva) es otra cosa: exporta solo las columnas de la tabla.
- **Por defecto, todo:** sin `secciones`, el PDF trae todas las secciones (hoy 11, `App\Support\CompanyDossier::SECTIONS`). Elegirlas todas es lo mismo.
- **`secciones`:** lista blanca de claves, en el orden del reporte. Desde CPV-PDF-OPERATION-SECTION (`6286644396`), `operacion` es una sección propia: antes iba dentro de `generales`.

  | Clave | Opción | Bloques del PDF |
  |---|---|---|
  | `generales` | Datos generales y contactos | «Datos generales» (nombre, RIF, dirección, ciudad, web, teléfono, redes, fundación, capital/origen, sectores principal y secundario) y «Contactos» |
  | `operacion` | Operación en Venezuela | facturación anual, empleados (rango declarado), empleados (total en Recursos humanos), estatus actual |
  | `clientes` | Clientes | nombre y país (la consulta trae también los campos generales, pero no se imprimen) |
  | `camaras` | Cámaras | nombres |
  | `sectores` | Sectores de actividad económica y servicios | sector y servicios |
  | `categorias` | Categorías CPV | declaradas o confirmadas, principal, origen |
  | `sostenibilidad` | Enfoque de sostenibilidad | áreas y estado |
  | `recursos` | Recursos en Venezuela | recursos humanos, maquinarias y equipos, instalaciones, inventario |
  | `experiencia` | Experiencia relevante | una tarjeta por trabajo (año, clasificación de infraestructura, monto, esfuerzo H-H, sector y servicios, descripción) |
  | `presencia` | Presencia internacional | oficinas en otros países y proyectos en otros países (no es la Experiencia relevante) |
  | `gestion` | Sistemas de gestión | calidad, ambiente, credibilidad y transparencia, seguridad (con OVID), gestión de proyectos, seguridad de la información; cada una con «Otros» |

  - Acepta `secciones=a,b` o `secciones[]=a`.
  - Las claves desconocidas se ignoran. Si no queda ninguna válida, el arreglo tiene más entradas que secciones, o el texto pasa de tres veces la lista completa de claves, sale el perfil completo.
  - El selector muestra la misma descripción de cada opción (`CompanyDossier::SECTION_DESCRIPTIONS`), y el manual la repite (`#descargar-perfil`).
  - Solo elige secciones: se lee **después** de autorizar, y no cambia la empresa ni el acceso.
- **El PDF dice lo que contiene:** al inicio, qué secciones incluye y cuáles se omitieron. Cada sección distingue datos, «Marcado no aplica por el afiliado» y «No hay información registrada».

## Páginas de error

- **`resources/views/errors/404.blade.php`:** «Página no encontrada», neutral, para cualquier 404 del sitio.
- **`errors/export-not-available.blade.php`:** «Archivo no disponible», para las descargas.

Las dos están en español y son HTML autocontenido: no muestran datos, rutas, permisos ni detalles técnicos. Producción y staging deben seguir con `APP_DEBUG=false` (staging lo tenía así el 2026-10-19, lectura de configuración).

## Caché

- Las descargas responden `Cache-Control: private, no-store, max-age=0`, `Pragma: no-cache` y `X-Content-Type-Options: nosniff`. Las rutas nuevas no terminan en `.pdf`/`.xlsx`, porque algunos CDN guardan en caché por extensión.
- Antes, el XLS respondía `Cache-Control: public`. Estas cabeceras **no borran** copias que un caché compartido hubiera guardado antes.
- Staging pasa por Cloudflare (la página de login responde `cf-cache-status: DYNAMIC`). En esta entrega no se revisaron ni se cambiaron sus reglas.
- **Acción aparte, del propietario:** si existe alguna regla «Cache Everything» o similar para `/xls/*` o `/pdf/*`, purgar esas rutas en Cloudflare después de desplegar.
