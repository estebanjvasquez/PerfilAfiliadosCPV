<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase MCP-5.2 (ver docs/taxonomia/plan_mcp_cira.md): espejo del catálogo `areas` de MySQL (8 filas
 * fijas, modelo de economía circular) - se sincroniza una sola vez porque es un catálogo cerrado
 * que no cambia con la operación diaria (a diferencia de `empresa_sustainability_areas`, que sí se
 * resincroniza cada vez que corre el comando de sync). IDs idénticos a `areas.id` en MySQL para que
 * el pivote de abajo no necesite una tabla de mapeo.
 *
 * `synonyms` (curado a mano, no generado): los títulos reales de `areas` son la descripción técnica
 * de un modelo de negocio circular (ver `Area.php`) - ningún usuario de CIRA va a escribir
 * "REORIENTACIÓN DEL OBJETO POR Y PARA LA SOCIEDAD O EL AMBIENTE" tal cual. Los sinónimos son la
 * forma coloquial real en la que alguien preguntaría por cada área (ej. "reciclaje"/"residuos" para
 * el área 2). Con solo 8 filas no se justifica un embedding - matching léxico contra nombre O
 * sinónimos alcanza, mismo criterio que `taxonomy_category_synonyms` pero sin la tabla aparte
 * porque acá el catálogo entero cabe en esta migración.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create('sustainability_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('synonyms')->nullable();
            $table->timestamps();
        });

        $now = now();
        $areas = [
            [1, 'MAXIMIZACIÓN DE LA EFICIENCIA MATERIAL Y ENERGÉTICA', 'eficiencia energetica, ahorro de energia, eficiencia material, uso eficiente de recursos'],
            [2, 'CREACIÓN DE VALOR A PARTIR DE LOS DESECHOS', 'reciclaje, residuos, desechos, economia circular, reutilizacion'],
            [3, 'USO DE ENERGÍAS RENOVABLES Y PROCESOS NATURALES', 'energias renovables, energia solar, energia eolica, energia limpia, procesos naturales'],
            [4, 'FUNCIONALIDAD EN VEZ DE PROPIEDAD', 'economia colaborativa, servicio en vez de producto, alquiler, leasing, arrendamiento'],
            [5, 'PARTICIPACIÓN PROACTIVA CON LAS PARTES INTERESADAS (STAKEHOLDERS)', 'stakeholders, comunidad, responsabilidad social, partes interesadas'],
            [6, 'FOMENTO DE LA SUFICIENCIA', 'consumo responsable, reduccion de consumo, suficiencia'],
            [7, 'REORIENTACIÓN DEL OBJETO POR Y PARA LA SOCIEDAD O EL AMBIENTE', 'impacto social, impacto ambiental, proposito social'],
            [8, 'DESARROLLO DE SOLUCIONES A ESCALA', 'escalabilidad, soluciones a escala, innovacion escalable'],
        ];

        foreach ($areas as [$id, $name, $synonyms]) {
            DB::connection('pgsql')->table('sustainability_areas')->insert([
                'id' => $id,
                'name' => $name,
                'synonyms' => $synonyms,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::connection('pgsql')->statement("SELECT setval('sustainability_areas_id_seq', (SELECT MAX(id) FROM sustainability_areas))");
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('sustainability_areas');
    }
};
