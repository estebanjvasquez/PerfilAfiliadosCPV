# Estado del Análisis - Limitación de Sectores + "No Aplica"
**Fecha:** 23 de Junio de 2026  
**Rama:** `feature/limite-sectores-no-aplica`  
**Estado:** ✅ 90% IMPLEMENTADO - Pendiente aprobación del cliente  
**Commit base:** `cbc1e37` (Limitar empresas a 2 sectores y agregar "No Aplica" por modulo)

---

## 📊 Resumen Ejecutivo

Se realizó un análisis exhaustivo de la implementación actual de:
1. **Limitación a 2 Sectores** (Sector Principal + Secundario)
2. **Botón "No Aplica"** por módulo de perfil
3. **% de Completitud** del perfil de empresa

**Resultado:** La implementación está **90% completa**, con **1 bug crítico** identificado y **2 mejoras menores** recomendadas.

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

## 🔴 BUGS CRÍTICOS ENCONTRADOS

### 1. CreateEmpresa SIN validación al guardar
**Ubicación:** `app/Filament/Resources/EmpresaResource/Pages/CreateEmpresa.php`  
**Problema:** 
- EditEmpresa tiene `beforeSave()` que valida servicios/sectores
- CreateEmpresa NO tiene esta validación
- Una empresa nueva podría guardarse con inconsistencias (ej: servicios en >2 sectores)

**Impacto:** 🔴 Crítico - Viola la regla de negocio  
**Fix:** Copiar `beforeSave()` de EditEmpresa a CreateEmpresa (2-3 horas)

**Código a agregar en CreateEmpresa:**
```php
protected function beforeSave(): void
{
    $data = $this->form->getState();

    $allowed = array_map('intval', array_filter([
        $data['sector_principal_id'] ?? null,
        $data['sector_secundario_id'] ?? null,
    ]));

    $outside = array_diff($this->record->distinctSectorIds(), $allowed);

    if (count($outside) > 0) {
        $names = Sector::whereIn('id', $outside)->pluck('name')->implode(', ');

        Notification::make()
            ->danger()
            ->title('Su empresa tiene servicios en más de 2 sectores')
            ->body("Solo se permiten un Sector Principal y uno Secundario. En la pestaña \"Sectores y Servicios\" desvincule los servicios de: {$names}; o ajuste sus sectores seleccionados.")
            ->persistent()
            ->send();

        $this->halt();
    }
}
```

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
| Wizards Create | ⚠️ | Sin validación `beforeSave()` |
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

Una vez que el cliente apruebe, el orden de implementación será:

1. **Fase A - Bug Crítico (2-3h)**
   - Agregar `beforeSave()` a CreateEmpresa
   - Testing local

2. **Fase B - Mejoras Menores (0.5-1h)**
   - Refactorizar SustainabilitiesRelationManager
   - Validación reactiva en wizards

3. **Fase C - QA & Despliegue (2-4h)**
   - Testing en staging
   - Revisión de migraciones
   - Despliegue a producción

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
- ⚠️ Hay 1 caso de uso que podría fallar (crear empresa sin validación) — será corregido antes de despliegue
- ⚠️ Las 30 empresas legadas con >2 sectores se bloquearán al editar hasta que ajusten

### Para el Desarrollador (Retomar el Trabajo)
- Todos los cambios están en el commit `cbc1e37`
- La rama actual ya tiene todo implementado
- Solo falta: validación en CreateEmpresa + testing
- Ver archivo `PROXIMO_PASOS.md` para instrucciones paso a paso

### Riesgo de Implementación
- **Bajo** — Los cambios son localizados (no afectan otros módulos)
- **Migraciones reversibles** — Se pueden deshacer con `php artisan migrate:rollback`
- **Testing recomendado:** Staging + ~2h de QA manual

---

**Documento preparado por:** Claude Haiku 4.5  
**Fecha de análisis:** 23 de Junio de 2026  
**Estado:** Listo para aprobación del cliente
