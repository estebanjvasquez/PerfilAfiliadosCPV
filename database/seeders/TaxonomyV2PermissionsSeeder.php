<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * TAXV2-10 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 13 y el plan
 * de esta fase): los 7 permisos finos del diccionario V2. No encajan en el generador automático de
 * `shield:generate` (que crea view/create/update/delete por Resource) porque son transversales a
 * ACCIONES, no a un Resource puntual - se siembran acá y se consultan con `Auth::user()->can(...)`
 * en las acciones especiales (aprobar/rechazar, pesos, publicar, rollback, fuentes).
 *
 * El CRUD base de cada Resource nuevo (`TaxonomyTermResource`, `LegacyServiceResource`, etc.) sigue
 * usando exclusivamente las Policies auto-generadas por Shield (`view_taxonomy::term`, etc.) - por
 * eso `taxonomy_edit_terms` se siembra acá (pedido literal de la sección 13) pero no se cablea a
 * ninguna acción nueva: ya está cubierto por `update_taxonomy::term`, cablear un segundo permiso
 * redundante encima solo agregaría confusión sobre cuál es la autoridad real.
 *
 * Bug real encontrado al verificar esta fase con un usuario `super_admin` de verdad: NO existe
 * ningún `Gate::before` que le dé paso libre a cualquier permiso - `config('filament-shield.super_admin')`
 * con `define_via_gate: false` funciona asignándole al rol, de forma explícita, TODOS los permisos
 * que existen (375 al momento de escribir esto) - no es un bypass mágico. Un permiso creado por
 * fuera de `shield:generate` (como estos 7) no le llega solo; sin este seeder asignándoselo a mano,
 * ni el propio super_admin podía acceder al Dashboard/Pesos/aprobar-rechazar/fuentes - se
 * verificó el error real contra un usuario real antes de este fix.
 */
class TaxonomyV2PermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'taxonomy_view',
        'taxonomy_edit_terms',
        'taxonomy_edit_relations',
        'taxonomy_edit_weights',
        'taxonomy_manage_sources',
        'taxonomy_publish',
        'taxonomy_rollback',
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

        $this->command?->info(count(self::PERMISSIONS).' permiso(s) del diccionario V2 verificados/creados.');
    }
}
