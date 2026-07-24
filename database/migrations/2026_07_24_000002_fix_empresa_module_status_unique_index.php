<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migracion de reparacion: en al menos un entorno, la migracion anterior
 * (2026_07_24_000001) agrego la columna sub_type pero no logro reemplazar
 * el unique key antiguo (empresa_id, module) por el nuevo compuesto
 * (empresa_id, module, sub_type) — probablemente por un corte de conexion
 * a mitad del ALTER TABLE en un MySQL alojado. Esta migracion es idempotente:
 * solo actua sobre lo que falte, sin importar en que estado quedo cada entorno.
 */
return new class extends Migration
{
    private const OLD_UNIQUE = 'empresa_module_status_empresa_id_module_unique';

    private const NEW_UNIQUE = 'empresa_module_status_empresa_id_module_sub_type_unique';

    public function up()
    {
        if (! Schema::hasColumn('empresa_module_status', 'sub_type')) {
            Schema::table('empresa_module_status', function (Blueprint $table) {
                $table->string('sub_type', 50)->default('')->after('module');
            });
        }

        if ($this->indexExists(self::OLD_UNIQUE)) {
            Schema::table('empresa_module_status', function (Blueprint $table) {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }

        if (! $this->indexExists(self::NEW_UNIQUE)) {
            Schema::table('empresa_module_status', function (Blueprint $table) {
                $table->unique(['empresa_id', 'module', 'sub_type'], self::NEW_UNIQUE);
            });
        }
    }

    public function down()
    {
        if ($this->indexExists(self::NEW_UNIQUE)) {
            Schema::table('empresa_module_status', function (Blueprint $table) {
                $table->dropUnique(self::NEW_UNIQUE);
            });
        }

        if (! $this->indexExists(self::OLD_UNIQUE)) {
            Schema::table('empresa_module_status', function (Blueprint $table) {
                $table->unique(['empresa_id', 'module'], self::OLD_UNIQUE);
            });
        }
    }

    private function indexExists(string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', Schema::getConnection()->getDatabaseName())
            ->where('table_name', 'empresa_module_status')
            ->where('index_name', $indexName)
            ->exists();
    }
};
