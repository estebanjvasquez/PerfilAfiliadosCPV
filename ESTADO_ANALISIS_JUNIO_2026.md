# Estado del Análisis - Limitación de Sectores + "No Aplica"
**Fecha:** 23 de Junio de 2026 (revisado y corregido el 22 de Julio de 2026)  
**Rama:** `feature/limite-sectores-no-aplica`  
**Estado:** ✅ Aprobado por el cliente. Implementación funcional; pendiente 1 conflicto de despliegue + mejora menor + QA  
**Commit base:** `cbc1e37` (Limitar empresas a 2 sectores y agregar "No Aplica" por modulo)

> **Corrección (22 jul 2026):** una revisión posterior encontró que el "bug crítico" descrito abajo en la versión original de este documento **no es real** (ver sección "Corrección de análisis previo"). Se mantiene el histórico tachado por trazabilidad, pero no debe implementarse tal como estaba escrito.

---

## 📊 Resumen Ejecutivo

Se realizó un análisis exhaustivo de la implementación actual de:
1. **Limitación a 2 Sectores** (Sector Principal + Secundario)
2. **Botón "No Aplica"** por módulo de perfil
3. **% de Completitud** del perfil de empresa

**Resultado:** La implementación está **completa y funcional**. El análisis original de este documento identificaba un supuesto "bug crítico" en `CreateEmpresa` que, tras verificación técnica contra el código de Filament instalado, resultó ser un falso positivo (ver corrección más abajo). Queda 1 mejora menor real (refactor de `SustainabilitiesRelationManager`) y, además, se detectó un conflicto real y no documentado originalmente: `.cpanel.yml` diverge de la versión vigente en `main`.

---

## ✅ LO QUE ESTÁ 100% IMPLEMENTADO

### Infraestructura Base
- ✅ Migraciones (2 archivos):
  - `2026_06_12_000001_add_sector_principal_secundario_to_empresas_table.php`
  - `2026_06_12_000002_create_empresa_module_status_table.php`
- ✅ Modelo `EmpresaModuleStatus` con métodos:
  - `setStatus()`, `isNoAplica()`, `flagsFor()`, `noAplicaEmpresas()`
- ✅ Métodos en Modelo `Empresa`:
  - `distinctSectorIds()`, `allowedSectorIds()`, `completionData()`, `completionPercentage()`, `isModuleNoAplica()`

### UI & Wizards
- ✅ **CreateEmpresa** → Paso "3 - Operaciones" con selectores Sector Principal + Secundario
- ✅ **EditEmpresa** → Paso "3 - Operaciones" con misma estructura + validación `beforeSave()`
- ✅ Placeholder "Completitud del Perfil" en EditEmpresa mostrando % + módulos pendientes
- ✅ Columna "completitud" en tabla de Empresas (EmpresaResource)

### Validación de Servicios
- ✅ `ServicesRelationManager`:
  - Método `allowedSectorIds()` limita attach solo a sectores principal/secundario
  - Manejo de empresas sin sectores definidos (se limitan a 2 si llegan a ese número)
  - Notificación clara al bloquear servicios fuera de límite
- ✅ EditEmpresa `beforeSave()` bloquea guardado si hay servicios fuera de los 2 sectores

### Botón "No Aplica"
- ✅ Clase reutilizable `NoAplicaAction` en `app/Filament/Support/NoAplicaAction.php`
- ✅ Implementado en 5 módulos:
  - AssetResource (Recursos)
  - ManagementResource (Sistemas de Gestión)
  - ExperienceResource (Experiencia Relevante)
  - PresenceResource (Presencia Internacional)
  - SustainabilitiesRelationManager (Enfoque de Sostenibilidad)

### Sincronización Automática
- ✅ Hooks en 5 modelos que limpian "No Aplica" al crear datos reales:
  - `Asset.php` línea 58
  - `Management.php` línea 93
  - `Experience.php` línea 46
  - `Presence.php` línea 45
  - `Sustainability.php` línea 36

### PDF & Reportes
- ✅ `resources/views/pdf.blade.php`:
  - Carga flags `$na_flags = EmpresaModuleStatus::flagsFor($record->id)`
  - Muestra "NO APLICA — Declarado por la empresa" en 6+ secciones (líneas 542, 637, 707, 794, 838, 953, 1050, 1073)
  - Condicionales `@if ($na_flags['modulo'])` en cada sección

- ✅ 9 Exports convertidos a `FromCollection`:
  - ExperienceExport, FacilityExport, InventoryExport, MachineryExport, ManagementDetExport, ManagementExport, PresenceExport, ResourceExport, SustainabilityExport
  - Trait `AppendsNoAplicaRows` (app/Exports/Concerns/AppendsNoAplicaRows.php) que:
    - Consulta empresas con "No Aplica"
    - Agrega filas faltantes en exports
    - Marca empresas existentes que también tienen "No Aplica"

---

## ✅ Corrección de análisis previo (22 jul 2026)

### El supuesto "bug crítico" de CreateEmpresa NO es real

El análisis original (23 jun 2026) afirmaba que `CreateEmpresa.php` necesitaba un método `beforeSave()` copiado de `EditEmpresa.php`. Se verificó contra el código fuente de Filament instalado (`vendor/filament/filament/src/Resources/Pages/CreateRecord.php` y `Page::callHook()`) y contra el propio wizard de `CreateEmpresa`, y esa afirmación es incorrecta por dos motivos:

1. **`CreateRecord` (de donde hereda `CreateEmpresa`) nunca dispara el hook `beforeSave`.** Su método `create()` solo invoca `beforeValidate`, `afterValidate`, `beforeCreate`, `afterCreate` (el hook `beforeSave`/`afterSave` es exclusivo de `EditRecord`, por eso funciona en `EditEmpresa`). Un método llamado `beforeSave()` agregado a `CreateEmpresa` **nunca se ejecutaría** — quedaría como código muerto, sin dar ningún error visible, dando una falsa sensación de seguridad.
2. **Aunque se renombrara a `beforeCreate()`, la validación no aplica en este punto del flujo.** `$this->record` todavía no existe cuando se dispara ese hook (el registro se crea después, en `handleRecordCreation()`), así que `$this->record->distinctSectorIds()` fallaría. Y de fondo, el wizard de `CreateEmpresa` **no tiene un paso de "Servicios"** (termina en "4 - Clientes"); los servicios solo se pueden asociar después de guardar la empresa, editándola (`ServicesRelationManager` vive en `EditEmpresa`). Es decir: **una empresa nueva no puede tener servicios en más de 2 sectores al momento de crearse**, porque todavía no tiene servicios. No hay caso de uso real que este "fix" estuviera previniendo.

**Conclusión:** no se requiere ningún cambio en `CreateEmpresa.php`. La validación real de negocio (empresa existente con servicios fuera de sus 2 sectores) ya está cubierta correctamente por `EditEmpresa::beforeSave()` y por `ServicesRelationManager`.

### Hallazgo real no detectado en el análisis original: conflicto en `.cpanel.yml`

`.cpanel.yml` es el único archivo modificado tanto en esta rama como en `main` desde que divergieron. Desde la creación de esta rama, `main` corrigió varias veces el despliegue en producción (esquema real `deployment.tasks` en vez de `targets/post_deployment`, `DEPLOYPATH` explícito, PHP 8.2 en vez de 8.1, orden correcto de `composer install`). La versión de `.cpanel.yml` de esta rama es la **versión anterior, obsoleta**, previa a esos fixes.

**Decisión (confirmada con el cliente/dueño del proyecto, 22 jul 2026):** al integrar esta rama, se conserva el `.cpanel.yml` de `main` como fuente de verdad. Esta rama no debe aportar cambios a ese archivo.

---

## 🟡 MEJORAS MENORES

### 1. SustainabilitiesRelationManager - Código duplicado
**Ubicación:** `app/Filament/Resources/EmpresaResource/RelationManagers/SustainabilitiesRelationManager.php` líneas 97-122  
**Problema:** 
- Tiene el botón "No Aplica" hardcodeado manualmente
- NoAplicaAction ya existe como clase reutilizable
- Duplicación de código = mantenimiento complicado

**Impacto:** 🟡 Menor - Afecta mantenibilidad  
**Fix:** Refactorizar para usar `NoAplicaAction::make()` (0.5 horas)

**Cambio sugerido:**
```php
// En headerActions de SustainabilitiesRelationManager
->headerActions([
    Tables\Actions\CreateAction::make(),
    NoAplicaAction::make(EmpresaModuleStatus::MODULE_SOSTENIBILIDAD),
])
```

---

### 2. Validación reactiva en Wizards
**Ubicación:** CreateEmpresa, EditEmpresa  
**Problema:** 
- Los selectores de sector no tienen validación reactiva visual
- El usuario no ve en tiempo real si hay conflictos

**Impacto:** 🟡 Menor - Afecta UX  
**Fix:** Agregar métodos de validación reactiva (2-3 horas)

---

## 📋 VERIFICACIÓN COMPLETADA

| Componente | Estado | Detalles |
|-----------|--------|----------|
| Migraciones | ✅ | 2 archivos, reversibles |
| Modelos | ✅ | EmpresaModuleStatus + métodos en Empresa |
| Wizards Create | ✅ | No requiere validación adicional (no puede tener servicios al crearse) |
| Wizards Edit | ✅ | Con validación completa |
| Selectores Sectores | ✅ | Funcionales en ambos wizards |
| Validación Servicios | ✅ | ServicesRelationManager limitando a 2 |
| NoAplicaAction | ✅ | Implementada y usada en 5 módulos |
| Hooks Sincronización | ✅ | En 5 modelos de datos |
| Tabla Completitud | ✅ | Mostrada en EmpresaResource |
| PDF "NO APLICA" | ✅ | 6+ secciones actualizadas |
| Exports | ✅ | 9 exports + trait AppendsNoAplicaRows |
| SustainabilitiesRM | ⚠️ | Código duplicado, funcional |

---

## 🔄 FLUJO DE TRABAJO PENDIENTE

Cliente ya aprobó (confirmado 22 jul 2026). Orden de implementación:

1. **Fase A - Mejoras Menores (0.5-1h)**
   - Refactorizar SustainabilitiesRelationManager
   - (Opcional) Validación reactiva en wizards

2. **Fase B - Reconciliar `.cpanel.yml` (incluida en el merge, ~15 min)**
   - Al integrar la rama, conservar el `.cpanel.yml` de `main`; descartar el de esta rama

3. **Fase C - QA & Despliegue (2-4h)**
   - Testing en staging
   - Revisión de migraciones
   - Despliegue a producción

> Ya no hay "Fase de bug crítico": se eliminó del plan porque no era un bug real (ver corrección arriba).

---

## 📂 Archivos Modificados (Commit cbc1e37)

30 archivos modificados, 681 líneas modificadas:

### Modelos (5 archivos)
- `app/Models/Empresa.php` — métodos de validación y completitud
- `app/Models/EmpresaModuleStatus.php` — nuevo modelo
- `app/Models/Asset.php`, `Management.php`, `Experience.php`, `Presence.php`, `Sustainability.php` — hooks

### Filament (7 archivos)
- `app/Filament/Resources/EmpresaResource.php` — columna completitud
- `app/Filament/Resources/EmpresaResource/Pages/CreateEmpresa.php`
- `app/Filament/Resources/EmpresaResource/Pages/EditEmpresa.php`
- `app/Filament/Resources/AssetResource.php`
- `app/Filament/Resources/ManagementResource.php`
- `app/Filament/Resources/ExperienceResource.php`
- `app/Filament/Resources/PresenceResource.php`
- `app/Filament/Support/NoAplicaAction.php` — nueva clase

### Relation Managers (2 archivos)
- `app/Filament/Resources/EmpresaResource/RelationManagers/ServicesRelationManager.php`
- `app/Filament/Resources/EmpresaResource/RelationManagers/SustainabilitiesRelationManager.php`

### Exports (10 archivos)
- `app/Exports/Concerns/AppendsNoAplicaRows.php` — nuevo trait
- 9 exports convertidos a FromCollection

### Vistas (1 archivo)
- `resources/views/pdf.blade.php`

### Base de Datos (2 archivos)
- Migraciones

---

## 🎯 NOTAS IMPORTANTES

### Para el Cliente
- ✅ La funcionalidad de "limitación a 2 sectores" está **lista y funcional**
- ✅ Los botones "No Aplica" están **listos y funcionales**
- ✅ El % de completitud se muestra **correctamente en 2 lugares**
- ✅ No hay caso de uso pendiente de corregir en la creación de empresas (el supuesto riesgo era un falso positivo)
- ⚠️ **Verificado contra el dump real de producción (`campetapp_campet202212.sql`, 22 jul 2026): 34 empresas** tienen hoy servicios asociados en más de 2 sectores distintos (no 30, como se estimaba). Se bloquearán al editar hasta que ajusten sus sectores o desvinculen servicios. Distribución: 15 con 3 sectores, 15 con 4, 1 con 5, 1 con 6, 1 con 8.

### Para el Desarrollador (Retomar el Trabajo)
- Todos los cambios funcionales están en el commit `cbc1e37`
- La rama actual ya tiene todo implementado; no falta ningún fix de código en `CreateEmpresa`
- Pendiente real: refactor menor de `SustainabilitiesRelationManager`, reconciliar `.cpanel.yml` con `main` al integrar, y testing
- Ver archivo `PROXIMO_PASOS.md` para instrucciones paso a paso (actualizado)

### Riesgo de Implementación
- **Bajo** — Los cambios funcionales son localizados (no afectan otros módulos)
- **Migraciones reversibles** — Se pueden deshacer con `php artisan migrate:rollback`
- **Único riesgo real de integración:** `.cpanel.yml` — resolver conservando la versión de `main`
- **Testing recomendado:** Staging + ~2h de QA manual, incluyendo una de las 34 empresas con >2 sectores reales

---

**Documento preparado originalmente por:** Claude Haiku 4.5 (23 jun 2026)  
**Corregido por:** Claude Sonnet 5, tras verificación técnica contra código y datos reales (22 jul 2026)  
**Estado:** Aprobado por el cliente — listo para implementar Fase A/B/C
