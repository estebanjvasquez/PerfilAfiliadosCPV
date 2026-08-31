<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega 2 foreign keys que sus migraciones originales declaraban antes de que la tabla
 * referenciada existiera (bug real encontrado al correr `migrate` desde cero contra Postgres/
 * Supabase - nunca fallaba en MySQL porque la BD de produccion jamas se construyo desde cero
 * en el orden actual de estos archivos):
 *
 * - empresas.city_id -> cities.id (2022_05_31_223828_create_empresas_table corre ANTES que
 *   2022_07_28_183312_create_cities_table).
 * - infrafacilities.infraregion_infrasystem_id -> infraregion_infrasystem.id
 *   (2022_09_01_185804_create_infrafacilities_table corre ANTES que
 *   2022_12_01_222538_create_infraregion_infrasystem_table).
 * - sustainabilities.areas_id -> areas.id (2022_08_12_145310_create_sustainabilities_table corre
 *   ANTES que 2022_08_15_005221_create_areas_table).
 * - infratypes.infrasectors_id -> infrasectors.id (2022_09_01_154856_create_infratypes_table
 *   corre ANTES que 2022_09_06_153218_create_infrasectors_table).
 *
 * Idempotente a proposito: en MySQL de produccion estas 2 FK ya existen (se crearon inline en su
 * momento porque el historial real no se ejecuto desde cero en este orden), asi que esta migracion
 * debe poder correr ahi sin fallar por "constraint ya existe". En Postgres/Supabase (BD nueva) las
 * crea de cero, ahora que ambas tablas ya existen. `information_schema.table_constraints` es
 * estandar ANSI, funciona igual en los dos motores.
 */
return new class extends Migration
{
    public function up()
    {
        $this->addForeignKeyIfMissing('empresas', 'city_id', 'cities', 'id');
        $this->addForeignKeyIfMissing('infrafacilities', 'infraregion_infrasystem_id', 'infraregion_infrasystem', 'id');
        $this->addForeignKeyIfMissing('sustainabilities', 'areas_id', 'areas', 'id');
        $this->addForeignKeyIfMissing('infratypes', 'infrasectors_id', 'infrasectors', 'id');
    }

    public function down()
    {
        $this->dropForeignKeyIfExists('empresas', 'empresas_city_id_foreign');
        $this->dropForeignKeyIfExists('infrafacilities', 'infrafacilities_infraregion_infrasystem_id_foreign');
        $this->dropForeignKeyIfExists('sustainabilities', 'sustainabilities_areas_id_foreign');
        $this->dropForeignKeyIfExists('infratypes', 'infratypes_infrasectors_id_foreign');
    }

    private function addForeignKeyIfMissing(string $table, string $column, string $referencesTable, string $referencesColumn): void
    {
        $constraint = "{$table}_{$column}_foreign";

        if ($this->foreignKeyExists($table, $constraint)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($column, $referencesColumn, $referencesTable) {
            $t->foreign($column)->references($referencesColumn)->on($referencesTable);
        });
    }

    private function dropForeignKeyIfExists(string $table, string $constraint): void
    {
        if (! $this->foreignKeyExists($table, $constraint)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($constraint) {
            $t->dropForeign($constraint);
        });
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $connection = Schema::getConnection();

        $result = $connection->select(
            "select constraint_name from information_schema.table_constraints
                where table_name = ? and constraint_name = ? and constraint_type = 'FOREIGN KEY'",
            [$table, $constraint]
        );

        return count($result) > 0;
    }
};
