<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_UNIQUE = 'empresa_module_status_empresa_id_module_unique';

    private const NEW_UNIQUE = 'empresa_module_status_empresa_id_module_sub_type_unique';

    /**
     * Cada paso esta guardado para poder correr de nuevo sin error: en MySQL
     * cada ALTER TABLE hace commit por separado (no es atomico con el resto
     * del up()), asi que si un paso posterior falla (p.ej. se corta la
     * conexion en un MySQL alojado) los pasos anteriores quedan aplicados
     * pero la migracion no se marca como completada, y el siguiente
     * `php artisan migrate` la vuelve a intentar desde el principio.
     *
     * Importante: `empresa_id` tiene una foreign key, e InnoDB reutiliza
     * como indice de soporte de esa FK cualquier indice existente cuya
     * columna izquierda sea empresa_id (aqui, el unique key viejo). Por eso
     * el nuevo unique se crea ANTES de borrar el viejo — si se borra primero,
     * la FK se queda sin indice de soporte y MySQL rechaza el DROP con el
     * error 1553.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn('empresa_module_status', 'sub_type')) {
            Schema::table('empresa_module_status', function (Blueprint $table) {
                $table->string('sub_type', 50)->default('')->after('module');
            });
        }

        if (! $this->indexExists(self::NEW_UNIQUE)) {
            Schema::table('empresa_module_status', function (Blueprint $table) {
                $table->unique(['empresa_id', 'module', 'sub_type'], self::NEW_UNIQUE);
            });
        }

        if ($this->indexExists(self::OLD_UNIQUE)) {
            Schema::table('empresa_module_status', function (Blueprint $table) {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! $this->indexExists(self::OLD_UNIQUE)) {
            Schema::table('empresa_module_status', function (Blueprint $table) {
                $table->unique(['empresa_id', 'module'], self::OLD_UNIQUE);
            });
        }

        if ($this->indexExists(self::NEW_UNIQUE)) {
            Schema::table('empresa_module_status', function (Blueprint $table) {
                $table->dropUnique(self::NEW_UNIQUE);
            });
        }

        if (Schema::hasColumn('empresa_module_status', 'sub_type')) {
            Schema::table('empresa_module_status', function (Blueprint $table) {
                $table->dropColumn('sub_type');
            });
        }
    }

    private function indexExists(string $indexName): bool
    {
        // information_schema.statistics is a MySQL-only view: PostgreSQL has no
        // such relation, so the previous implementation of this method made the
        // migration unrunnable there. Schema::hasIndex() is driver-aware and
        // asks each engine its own catalogue, so it behaves identically on
        // MySQL and works on PostgreSQL. Available since Laravel 11; this
        // project runs v12.69.0.
        return Schema::hasIndex('empresa_module_status', $indexName);
    }
};
