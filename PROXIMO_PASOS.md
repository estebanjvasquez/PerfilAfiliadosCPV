# Próximos Pasos - Implementación de Cambios
**Versión:** 1.0  
**Última actualización:** 23 de Junio de 2026  
**Estado:** Pendiente aprobación del cliente  
**Tiempo estimado para implementar:** 4.5 - 7.5 horas

---

## 📋 Pre-requisitos

- ✅ Cliente ha aprobado el diseño y funcionalidad
- ✅ Rama `feature/limite-sectores-no-aplica` está actualizada
- ✅ Acceso a servidor de staging para testing
- ✅ Acceso a producción para despliegue (si aplica)

---

## 🚀 FASES DE IMPLEMENTACIÓN

### FASE 1: Fijar Bug Crítico (2-3 horas)

**Objetivo:** Agregar validación `beforeSave()` a CreateEmpresa  
**Archivo:** `app/Filament/Resources/EmpresaResource/Pages/CreateEmpresa.php`

#### Paso 1.1: Agregar imports necesarios
Al inicio de CreateEmpresa, agregar:
```php
use Filament\Notifications\Notification;
use App\Models\Sector;
```

#### Paso 1.2: Agregar método `beforeSave()`
Antes de `protected function getSteps()`, insertar:

```php
/**
 * Las empresas solo pueden operar en 2 sectores (principal y secundario).
 * Si la empresa tiene servicios asociados a sectores fuera de los 2 elegidos,
 * se bloquea el guardado hasta que desvincule esos servicios o ajuste sus sectores.
 */
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

#### Paso 1.3: Verificación
```bash
php -l app/Filament/Resources/EmpresaResource/Pages/CreateEmpresa.php
```

---

### FASE 2: Refactorizar Sustainability (0.5-1 hora)

**Objetivo:** Usar NoAplicaAction en lugar de código hardcodeado  
**Archivo:** `app/Filament/Resources/EmpresaResource/RelationManagers/SustainabilitiesRelationManager.php`

#### Paso 2.1: Agregar import
Al inicio del archivo:
```php
use App\Filament\Support\NoAplicaAction;
```

#### Paso 2.2: Reemplazar headerActions
**Buscar (líneas ~94-122):**
```php
->headerActions([
    Tables\Actions\CreateAction::make(),

    Tables\Actions\Action::make('no_aplica')
        ->label('No Aplica')
        ->icon('heroicon-o-x-circle')
        ->color('warning')
        ->modalHeading('No Aplica — Enfoque de Sostenibilidad')
        ->modalButton('Guardar')
        ->form([
            Forms\Components\Toggle::make('no_aplica')
                ->label('"Enfoque de Sostenibilidad" No Aplica para esta empresa')
                ->helperText('Si lo activa, este módulo contará como completado en el perfil. Si luego carga datos en el módulo, la marca se elimina automáticamente.')
                ->default(true),
        ])
        ->action(function (array $data, RelationManager $livewire) {
            EmpresaModuleStatus::setStatus(
                (int) $livewire->ownerRecord->id,
                EmpresaModuleStatus::MODULE_SOSTENIBILIDAD,
                (bool) $data['no_aplica']
            );

            Notification::make()
                ->success()
                ->title($data['no_aplica']
                    ? '"Enfoque de Sostenibilidad" marcado como No Aplica'
                    : 'Se eliminó la marca No Aplica de "Enfoque de Sostenibilidad"')
                ->send();
        }),

])
```

**Reemplazar por:**
```php
->headerActions([
    Tables\Actions\CreateAction::make(),
    NoAplicaAction::make(EmpresaModuleStatus::MODULE_SOSTENIBILIDAD),
])
```

#### Paso 2.3: Verificación
```bash
php -l app/Filament/Resources/EmpresaResource/RelationManagers/SustainabilitiesRelationManager.php
```

---

### FASE 3: Testing (2-4 horas)

#### Paso 3.1: Preparar Staging
```bash
cd /path/to/staging/PerfilAfiliadosCPV
git fetch origin
git checkout feature/limite-sectores-no-aplica
composer install
php artisan migrate
php artisan cache:clear
```

#### Paso 3.2: Casos de Prueba

**Test 1: Crear Empresa Nueva**
- [ ] Crear empresa sin sector principal → debe bloquear
- [ ] Crear empresa con sector principal solamente → debe permitir
- [ ] Crear empresa con sector principal + secundario (diferente) → debe permitir
- [ ] Crear empresa con sector principal = secundario → debe bloquear (validación `different()`)

**Test 2: Editar Empresa Existente**
- [ ] Editar empresa con servicios en 2 sectores → debe permitir cambiar selectores
- [ ] Editar empresa con servicios en 3+ sectores, intentar guardar sin cambiar sectores → debe bloquear con mensaje claro
- [ ] Editar empresa: desasociar servicios de 1 sector, luego cambiar sectores → debe permitir

**Test 3: Validación de Servicios**
- [ ] En ServicesRelationManager: asociar servicio de sector permitido → debe permitir
- [ ] En ServicesRelationManager: intentar asociar servicio fuera de los 2 sectores → debe bloquear

**Test 4: Botón "No Aplica"**
- [ ] Marcar módulo como "No Aplica" → debe aparecer "NO APLICA" en PDF
- [ ] Crear dato real en módulo marcado como "No Aplica" → debe limpiar flag automáticamente
- [ ] Verificar % completitud: módulo "No Aplica" cuenta como completo

**Test 5: PDF**
- [ ] Generar PDF de empresa con "No Aplica" en Sostenibilidad → debe mostrar "NO APLICA — Declarado por la empresa"
- [ ] Generar PDF de empresa con "No Aplica" en Recursos → debe mostrar en todas sus subsecciones

**Test 6: Exports**
- [ ] Exportar ExperienceExport: empresa sin experiencias pero con "No Aplica" → debe aparecer en export
- [ ] Exportar ManagementExport: idem

#### Paso 3.3: Checklist de QA
```
Crear Empresa:
- [ ] Sin sector principal → bloqueado ✓
- [ ] Con sectores válidos → guardado ✓
- [ ] Validación de diferencia sector_principal vs secundario ✓

Editar Empresa:
- [ ] Empresa con >2 sectores actual: bloqueada hasta desasociar servicios ✓
- [ ] Cambiar sectores: se actualiza límite de servicios permitidos ✓

Servicios:
- [ ] Attach limitado a sectores principales/secundarios ✓
- [ ] Notificación clara si intenta fuera de límite ✓

No Aplica:
- [ ] Botón visible en 5 módulos ✓
- [ ] Toggle funcional ✓
- [ ] Flag se limpia al crear datos reales ✓

Completitud:
- [ ] % visible en tabla de Empresas ✓
- [ ] Desglose en EditEmpresa ✓
- [ ] Módulos "No Aplica" cuentan como completos ✓

PDF:
- [ ] Muestra "NO APLICA" en Sostenibilidad ✓
- [ ] Muestra "NO APLICA" en Recursos (todas subsecciones) ✓
- [ ] Muestra "NO APLICA" en Experiencias ✓
- [ ] Muestra "NO APLICA" en Presencia ✓
- [ ] Muestra "NO APLICA" en Gestión ✓

Exports:
- [ ] ExperienceExport incluye filas "NO APLICA" ✓
- [ ] ManagementExport incluye filas "NO APLICA" ✓
- [ ] Trait AppendsNoAplicaRows funciona en 9 exports ✓

Migraciones:
- [ ] `php artisan migrate` crea tablas ✓
- [ ] `php artisan migrate:rollback` revierte cambios ✓
```

---

### FASE 4: Despliegue a Producción (1-2 horas)

#### Opción A: Despliegue Manual

```bash
# En servidor de producción
cd /path/to/production/PerfilAfiliadosCPV

# Backup de BD
mysqldump -u user -p database > backup_antes_cambios_$(date +%Y%m%d_%H%M%S).sql

# Actualizar código
git fetch origin
git checkout feature/limite-sectores-no-aplica

# Instalar dependencias
composer install --no-dev

# Ejecutar migraciones
php artisan migrate

# Limpiar caché
php artisan cache:clear
php artisan config:cache
php artisan route:cache

# Restart queue/services si aplica
php artisan queue:restart
```

#### Opción B: Despliegue vía cPanel/Webhook
- Si tienes workflow automatizado, revisar `.cpanel.yml`
- Verificar que las migraciones se ejecuten POST-deploy

---

## 🔍 VERIFICACIÓN POST-DESPLIEGUE

```bash
# 1. Verificar migraciones
php artisan migrate:status | grep 2026_06_12

# 2. Verificar tablas
mysql -u user -p database -e "DESCRIBE empresas LIKE 'sector_%';"
mysql -u user -p database -e "DESCRIBE empresa_module_status;"

# 3. Verificar modelos
php artisan tinker
>>> App\Models\Empresa::first()->completionPercentage()
>>> App\Models\EmpresaModuleStatus::count()

# 4. Logs
tail -f storage/logs/laravel.log
```

---

## 📞 SOPORTE & ROLLBACK

### Si algo falla en producción:

```bash
# Rollback migraciones
php artisan migrate:rollback

# Revertir código a versión anterior
git checkout main

# Compositor
composer install

# Limpiar caché
php artisan cache:clear

# Restaurar BD desde backup
mysql -u user -p database < backup_antes_cambios_YYYYMMDD_HHMMSS.sql
```

---

## 📊 Checklist Final

**Antes de iniciar cambios:**
- [ ] Cliente ha aprobado formalmente
- [ ] Backup de BD realizado
- [ ] Rama feature actualizada desde main
- [ ] Testing plan revisado con QA

**Después de completar cambios:**
- [ ] 2-3 casos críticos testeados en staging
- [ ] PDF generado y validado
- [ ] Exports descargados y validados
- [ ] Empresa legada con >2 sectores probada (debe bloquearse)
- [ ] Documentación actualizada

**Pre-despliegue:**
- [ ] Code review completado
- [ ] Todos los tests pasan
- [ ] Migraciones reversibles verificadas
- [ ] Backup reciente en producción

**Post-despliegue:**
- [ ] Migraciones ejecutadas correctamente
- [ ] Tabla `empresa_module_status` existe
- [ ] Columnas `sector_principal_id` y `sector_secundario_id` existen
- [ ] Prueba rápida: crear empresa, ver % completitud = 25%
- [ ] Prueba rápida: marcar módulo como "No Aplica", ver % completitud sube

---

## 📚 Documentación Relacionada

- `ESTADO_ANALISIS_JUNIO_2026.md` — Análisis detallado de lo implementado
- `ESTIMADO_IMPLEMENTACION.md` — Desglose de horas original
- Commit `cbc1e37` — Todos los cambios implementados
- Commit `db7ae4b` — Tabla de estimado para el cliente

---

## 🎯 Tiempo Total Estimado

| Fase | Tiempo | Crítico |
|------|--------|---------|
| Fase 1: Bug CreateEmpresa | 2-3h | 🔴 SÍ |
| Fase 2: Refactor Sustainability | 0.5-1h | 🟡 NO |
| Fase 3: Testing | 2-4h | 🔴 SÍ |
| Fase 4: Despliegue | 1-2h | 🔴 SÍ |
| **TOTAL** | **5.5-10h** | - |

**Punto medio:** ~7.5 horas

---

## 📝 Notas Importantes

1. **No hacer cambios en main** — Todo trabajo en `feature/limite-sectores-no-aplica`
2. **Las migraciones son reversibles** — Rollback siempre disponible
3. **30 empresas legadas se bloquearán** — Al editar, deben ajustar sus sectores
4. **Testing es obligatorio** — No saltar fase de QA
5. **Comunicar al cliente sobre empresas legadas** — Darles instrucciones en caso de bloqueo

---

**Documento preparado por:** Claude Haiku 4.5  
**Versión:** 1.0 - Pendiente aprobación  
**Próxima revisión:** Cuando cliente apruebe
