<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Phase 3.1 (sección 9 del pedido, auditoría de admin write safeguards): permisos Shield-style de
 * los 2 Resources nuevos de Phase 3 (`TaxonomyConceptRelationResource`,
 * `TaxonomyCandidateConceptLinkResource`) - mismo hallazgo, mismo fix, que
 * `TaxonomyV2PermissionsSeeder` ya documentó para TAXV2-10: en este proyecto NO existe ningún
 * `Gate::before` que le dé paso libre a `super_admin` - Shield le asigna, de forma EXPLÍCITA, cada
 * permiso que exista en la tabla `permissions`. Un permiso referenciado por una Policy nueva
 * (`TaxonomyConceptRelationPolicy`/`TaxonomyCandidateConceptLinkPolicy`, creadas en Phase 3) que
 * nunca pasó por `shield:generate` ni por este seeder NO le llega a nadie, ni siquiera a
 * `super_admin` - se verificó que, sin este seeder, la sección de navegación de ambos Resources
 * nuevos queda inaccesible para cualquier usuario desde que se creó Phase 3.
 *
 * Mismo caveat operativo documentado en `TaxonomyV2PermissionsSeeder`: correr esto una sola vez no
 * alcanza - `roles`/`permissions` viven en la conexión `database.default` (distinta por entorno), así
 * que hay que repetirlo en cada entorno desplegado (`--force` en producción/Contabo).
 */
class Phase3PermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'view_any_taxonomy::concept::relation',
        'view_taxonomy::concept::relation',
        'create_taxonomy::concept::relation',
        'update_taxonomy::concept::relation',
        'delete_taxonomy::concept::relation',
        'delete_any_taxonomy::concept::relation',
        'view_any_taxonomy::candidate::concept::link',
        'view_taxonomy::candidate::concept::link',
        'update_taxonomy::candidate::concept::link',
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->map(
            fn ($name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'])
        );

        $superAdminRoleName = config('filament-shield.super_admin.name', 'super_admin');
        $superAdmin = Role::query()->where('name', $superAdminRoleName)->first();

        if ($superAdmin) {
            $superAdmin->givePermissionTo($permissions);
            $this->command?->info("Asignados a '{$superAdminRoleName}'.");
        } else {
            $this->command?->warn("No existe el rol '{$superAdminRoleName}' todavía - los permisos quedaron creados pero sin asignar a nadie.");
        }

        $this->command?->info(count(self::PERMISSIONS).' permiso(s) de Phase 3 verificados/creados.');
    }
}
