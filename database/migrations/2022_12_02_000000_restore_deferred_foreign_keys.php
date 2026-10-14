<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Re-declara las cuatro claves foraneas que sus migraciones de origen ya no pueden declarar en
 * linea, porque cada una referencia una tabla que otra migracion crea DESPUES. Replicar el juego
 * completo sobre una base vacia abortaba en la primera de ellas:
 *
 *   empresas.city_id                           -> cities                    (creada 2022_07_28)
 *   sustainabilities.areas_id                  -> areas                     (creada 2022_08_15)
 *   infratypes.infrasectors_id                 -> infrasectors              (creada 2022_09_06)
 *   infrafacilities.infraregion_infrasystem_id -> infraregion_infrasystem    (creada 2022_12_01)
 *
 * Medido sobre PostgreSQL 16.15: `SQLSTATE[42P01] relation "cities" does not exist`, en la
 * migracion 7 de 98. Que otros motores rechacen la misma declaracion NO esta establecido por esa
 * evidencia y no se afirma aqui. Las bases ya existentes nunca sufrieron el problema porque nunca
 * se construyeron reproduciendo el juego desde cero.
 *
 * NADA SE DEBILITA. Mismas columnas, misma columna referenciada, los mismos nombres que Laravel
 * genera ({tabla}_{columna}_foreign) y la misma accion referencial por defecto - NO ACTION, que es
 * lo que daban tanto ->constrained() como ->references()->on() sin modificador.
 *
 * -------------------------------------------------------------------------------------------
 * POR QUE `down()` NO HACE NADA, que es una correccion de la revision `6167931953`
 * -------------------------------------------------------------------------------------------
 * La version anterior de este archivo tenia una asimetria peligrosa, y la objecion era correcta:
 * `up()` OMITE una restriccion que ya exista, de modo que sobre una base ya migrada no crea nada,
 * pero `down()` borraba igualmente cualquier restriccion que coincidiera. En Supabase las cuatro
 * ya existen desde que corrieron sus migraciones de origen, asi que un rollback habria ELIMINADO
 * integridad historica que esta migracion nunca creo. Y con un nombre distinto al esperado,
 * `dropForeign($name)` ademas falla.
 *
 * Una migracion no puede saber si creo una restriccion que ya estaba: no hay marca de propiedad
 * durable, e inventarse una (una tabla de registro propia) seria mas maquinaria y mas superficie
 * de la que justifica una reparacion de integridad puramente aditiva.
 *
 * Asi que el rollback es explicitamente un NO-OP, que es lo que la revision acepta para este caso.
 * Consecuencias, escritas en vez de implicitas:
 *   - sobre una base ya migrada, `down()` no toca nada: exactamente lo que se quiere, porque las
 *     cuatro restricciones son patrimonio de las migraciones de origen, no de esta;
 *   - sobre una base construida desde cero, las cuatro restricciones sobreviven a un rollback de
 *     ESTA migracion. No quedan huerfanas: se van con sus tablas cuando el rollback llega a las
 *     migraciones que las crearon, que es quien debe retirarlas;
 *   - por tanto `migrate:rollback` seguido de `migrate` es seguro y no duplica: `up()` encuentra
 *     las cuatro ya presentes y las omite.
 */
return new class extends Migration
{
    /**
     * tabla, columna, tabla referenciada, columna referenciada, nombre que genera Laravel.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private array $deferred = [
        ['empresas', 'city_id', 'cities', 'id', 'empresas_city_id_foreign'],
        ['sustainabilities', 'areas_id', 'areas', 'id', 'sustainabilities_areas_id_foreign'],
        ['infratypes', 'infrasectors_id', 'infrasectors', 'id', 'infratypes_infrasectors_id_foreign'],
        ['infrafacilities', 'infraregion_infrasystem_id', 'infraregion_infrasystem', 'id', 'infrafacilities_infraregion_infrasystem_id_foreign'],
    ];

    public function up(): void
    {
        foreach ($this->deferred as [$table, $column, $referenced, $referencedColumn, $name]) {
            // UNA PRECONDICION QUE FALTA ES UN ERROR, no algo que saltarse.
            //
            // Antes esto era un `continue`, y eso significaba que una base a la que le faltara
            // `cities` terminaria la migracion como exitosa, SIN la clave foranea y sin que nada
            // lo dijera: justo el fallo silencioso que esta migracion existe para evitar. Si una
            // tabla referenciada no esta, el juego de migraciones no es el que esta migracion
            // asume y hay que detenerse y mirarlo.
            foreach ([$table, $referenced] as $required) {
                if (! Schema::hasTable($required)) {
                    throw new RuntimeException(
                        "No se puede restaurar {$name}: falta la tabla «{$required}». Esta migracion "
                        ."esta fechada despues de las que crean las cuatro tablas referenciadas, asi "
                        ."que su ausencia significa que el juego de migraciones no es el esperado. "
                        .'Revisar antes de continuar: omitirla dejaria la base sin esa clave foranea '
                        .'y la migracion se registraria igualmente como aplicada.'
                    );
                }
            }

            if ($this->satisfyingForeignKeyExists($table, $column, $referenced, $referencedColumn, $name)) {
                continue;
            }

            Schema::table($table, function ($blueprint) use ($column, $referenced, $referencedColumn, $name) {
                $blueprint->foreign($column, $name)->references($referencedColumn)->on($referenced);
            });
        }
    }

    /**
     * NO-OP deliberado. Ver el bloque del encabezado: esta migracion no puede distinguir las
     * restricciones que creo de las que ya estaban, y borrar las ajenas seria destruir integridad
     * historica. El desmontaje de una base recien creada lo hace el rollback de las migraciones
     * que crean esas tablas.
     */
    public function down(): void
    {
        // Intencionalmente vacio.
    }

    /**
     * Decide si una restriccion EXISTENTE ya satisface el objetivo, y es estricta a proposito.
     *
     * No basta con que haya una clave foranea sobre la misma columna: la revision `6167931953`
     * pidio comprobar la columna referenciada exacta y conservar las acciones referenciales. Una
     * restriccion que apunte a otra columna, o que traiga un `ON DELETE CASCADE` donde el objetivo
     * es `NO ACTION`, NO es la misma garantia - tratarla como equivalente dejaria la base con un
     * comportamiento distinto del declarado y lo reportaria como correcto.
     *
     * Si existe algo sobre esa columna que NO satisface el objetivo, se levanta excepcion en vez
     * de crear una segunda restriccion encima: dos claves foraneas sobre la misma columna es un
     * estado que nadie pidio y que hay que mirar a mano.
     */
    private function satisfyingForeignKeyExists(string $table, string $column, string $referenced, string $referencedColumn, string $name): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $columns = array_map('strtolower', $foreignKey['columns'] ?? []);
            if ($columns !== [strtolower($column)]) {
                continue;
            }

            $onTable = strtolower((string) ($foreignKey['foreign_table'] ?? ''));
            $onColumns = array_map('strtolower', $foreignKey['foreign_columns'] ?? []);
            // Null/'no action'/'restrict' son la misma semantica por defecto en PostgreSQL y MySQL
            // para lo que aqui importa: no propagar. Cualquier otra cosa (cascade, set null, set
            // default) SI cambia el comportamiento y no se acepta como equivalente.
            $neutral = fn (?string $action): bool => in_array(strtolower((string) $action), ['', 'no action', 'restrict'], true);

            $matches = $onTable === strtolower($referenced)
                && $onColumns === [strtolower($referencedColumn)]
                && $neutral($foreignKey['on_delete'] ?? null)
                && $neutral($foreignKey['on_update'] ?? null);

            if ($matches) {
                return true;
            }

            throw new RuntimeException(
                "La columna {$table}.{$column} ya tiene una clave foranea que NO coincide con el "
                ."objetivo de {$name}: apunta a "
                ."«{$onTable}(".implode(',', $onColumns).')» con on_delete='
                .((string) ($foreignKey['on_delete'] ?? 'null')).' / on_update='
                .((string) ($foreignKey['on_update'] ?? 'null'))
                ." , y se esperaba «{$referenced}({$referencedColumn})» sin propagacion. No se crea "
                .'una segunda restriccion encima: revisar a mano cual es la correcta.'
            );
        }

        return false;
    }
};
