# Diagnóstico del fork de `Shield/RoleResource.php` (Fase B del upgrade a Filament v3)

**Fecha:** 2026-09-01
**Método:** diff directo entre `vendor/bezhansalleh/filament-shield/stubs/resources/**`
(v1.1.x, instalado) y `app/Filament/Resources/Shield/**` (el fork de esta app), con
`diff -u -b -w` para separar cambios de formato de cambios semánticos reales.

## Resultado

**No hay customización real que preservar.** Lo que parecía un fork de 541+ líneas
reimplementando internals del paquete es, en sustancia, el stub oficial de
`filament-shield` v1 sin tocar — la diferencia completa se reduce a:

| Archivo | Diferencia real (ignorando formato) |
|---|---|
| `RoleResource.php` | `protected static ?int $navigationSort = -1;` → `10` (orden en el menú de navegación). Una línea comentada sin efecto (`//$navigationGroup = 'SETTINGS'`). Nada más. |
| `RoleResource/Pages/ShieldSettings.php` | Un re-wrap de formato de una llamada a `copyStubToApp(...)`, sin cambio de comportamiento. |
| `RoleResource/Pages/CreateRole.php` | Solo estilo (espaciado). |
| `RoleResource/Pages/EditRole.php` | Solo estilo (espaciado). |
| `RoleResource/Pages/ListRoles.php` | 0 diferencias. |
| `RoleResource/Pages/ViewRole.php` | 0 diferencias. |

## Decisión para la Fase D

Se descarta `app/Filament/Resources/Shield/` completo y se adopta la UI stock de
`bezhansalleh/filament-shield` v3 (arquitectura de plugin) tal cual la instala el
paquete. Al reinstalar, si se quiere mantener el mismo orden de navegación,
reaplicar `navigationSort = 10` sobre el nuevo `RoleResource` que publique Shield v3
(verificar primero si v3 ya lo posiciona razonablemente por defecto).

Esto reduce el riesgo dominante identificado en el plan de "Alto — requiere
rediseño manual" a "Bajo — instalación stock del paquete nuevo, sin lógica que
migrar".
