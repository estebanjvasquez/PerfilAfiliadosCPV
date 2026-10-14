<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Re-declara las cuatro claves foraneas que sus migraciones de origen ya no pueden declarar en
 * linea, porque cada una referencia una tabla que otra migracion crea DESPUES. Replicar el juego
 * completo sobre una base vacia abortaba en la primera de ellas:
 *
 *   empresas.city_id                        -> cities                   (creada 2022_07_28)
 *   sustainabilities.areas_id               -> areas                    (creada 2022_08_15)
 *   infratypes.infrasectors_id              -> infrasectors             (creada 2022_09_06)
 *   infrafacilities.infraregion_infrasystem_id -> infraregion_infrasystem (creada 2022_12_01)
 *
 * Medido sobre PostgreSQL 16.15: `SQLSTATE[42P01] relation "cities" does not exist`, en la
 * migracion 7 de 98. Que otros motores rechacen la misma declaracion NO esta establecido por esa
 * evidencia y no se afirma aqui. Las bases ya existentes nunca sufrieron el problema porque nunca
 * se construyeron reproduciendo el juego desde cero.
 *
 * NADA SE DEBILITA. Mismas columnas, mismas columnas referenciadas, los mismos nombres que Laravel
 * genera ({tabla}_{columna}_foreign) y la misma accion referencial por defecto - NO ACTION, que es
 * lo que daban tanto ->constrained() como ->references()->on() sin modificador. Las columnas nunca
 * se tocaron: siguen con su tipo original en las migraciones de origen.
 *
 * EFECTO SOBRE BASES YA EXISTENTES: ninguno. Alli las cuatro restricciones ya existen, creadas por
 * las migraciones de origen cuando corrieron, asi que cada ALTER se omite. La comprobacion no es
 * una suposicion: pregunta al catalogo del motor via Schema::getForeignKeys(), que es consciente
 * del driver (disponible desde Laravel 11; este proyecto corre v12.69), igual criterio que el
 * indexExists() portable de 2026_07_24_000001. Fecha 2022_12_02 para que una reproduccion desde
 * vacio cree las restricciones en cuanto existan las cuatro tablas referenciadas.
 *
 * La fecha esta en el pasado a proposito: en una base ya migrada Laravel ejecuta las pendientes
 * ordenadas entre si, sin importar su fecha respecto de las ya registradas, asi que esta corre una
 * sola vez y queda inerte.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> */
    private array $deferred = [
        ['empresas', 'city_id', 'cities', 'empresas_city_id_foreign'],
        ['sustainabilities', 'areas_id', 'areas', 'sustainabilities_areas_id_foreign'],
        ['infratypes', 'infrasectors_id', 'infrasectors', 'infratypes_infrasectors_id_foreign'],
        ['infrafacilities', 'infraregion_infrasystem_id', 'infraregion_infrasystem', 'infrafacilities_infraregion_infrasystem_id_foreign'],
    ];

    public function up(): void
    {
        foreach ($this->deferred as [$table, $column, $referenced, $name]) {
            if (! Schema::hasTable($table) || ! Schema::hasTable($referenced)) {
                continue;
            }
            if ($this->foreignKeyExists($table, $column, $referenced)) {
                continue;
            }

            Schema::table($table, function ($blueprint) use ($column, $referenced, $name) {
                $blueprint->foreign($column, $name)->references('id')->on($referenced);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->deferred) as [$table, $column, $referenced, $name]) {
            if (! Schema::hasTable($table) || ! $this->foreignKeyExists($table, $column, $referenced)) {
                continue;
            }

            Schema::table($table, function ($blueprint) use ($name) {
                $blueprint->dropForeign($name);
            });
        }
    }

    /**
     * Se compara por COLUMNA y tabla referenciada, no por nombre: en las bases ya existentes la
     * restriccion la creo la migracion original, que pudo nombrarla igual pero no esta garantizado
     * si alguien la recreo a mano. Lo que no debe duplicarse es la relacion, no el nombre.
     */
    private function foreignKeyExists(string $table, string $column, string $referenced): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $columns = array_map('strtolower', $foreignKey['columns'] ?? []);
            $on = strtolower((string) ($foreignKey['foreign_table'] ?? ''));

            if ($columns === [strtolower($column)] && $on === strtolower($referenced)) {
                return true;
            }
        }

        return false;
    }
};
