<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajusta el esquema de la Fase 4 (2026_09_01_120000..120003, "catalogo SupplHi") a los acuerdos
 * cerrados el 7 sep 2026 tras recibir el archivo real de Lorenzo (CLASSIFIED_MASTER.xlsx) y
 * definir la nomenclatura final con el cliente. Esas 4 tablas ya corrieron contra Supabase de
 * pruebas (batch 15) con 0 filas cada una - se corrige con ALTER en vez de editar las migraciones
 * originales, mismo criterio ya usado en este proyecto (ver 6aca79e/bba8a7e).
 *
 * Cambios:
 * 1. Renombres para dejar de referenciar "supplier"/"SupplHi" en el nombre de tabla/columna, acorde
 *    a la decision de que la BD no debe llevar ningun rastro de "SupplHi"/"SH" (docs/taxonomia/
 *    analisis_taxonomia_supplyhigh.md seccion 0): supplier_categories -> taxonomy_categories,
 *    supplier_category_translations -> taxonomy_category_translations, supplier_category_synonyms
 *    -> taxonomy_category_synonyms, empresa_supplier_categories -> empresa_taxonomy_category.
 *    supplhi_code -> code (el VALOR que se cargue ahi ya lleva el prefijo "CPV-" agregado por el
 *    importador, ver ImportTaxonomy - el codigo de Lorenzo en si nunca menciona "SH"/"SupplHi").
 * 2. tipo_oferta pasa a ser binario en la practica (Bien/Servicio, ver docs/taxonomia/
 *    acuerdos_pendientes_con_lorenzo.md punto 1) - sin cambio de esquema, sigue siendo un string
 *    libre, solo cambia que el importador ya no intenta poblar un 3er valor ("equipo") que el
 *    archivo real de Lorenzo no distingue.
 * 3. `branch`/`subbranch` nuevos: texto libre SIN codigo propio (punto 2 del acuerdo) - Lorenzo no
 *    los codifica de forma unica (el mismo texto de Branch se repite en familias distintas, ej.
 *    "Accessories" en 3 familias - verificado contra el Excel real), asi que NO se modelan como
 *    nodos navegables del arbol (evita tener que inventarles un codigo que la fuente no da) - son
 *    solo una etiqueta descriptiva mas de la Categoria, buscable por texto.
 * 4. `chamber_relevance`/`relevance_basis` nuevos: la clasificacion BELONGS/MAYBE/DOES NOT BELONG
 *    que el propio archivo de Lorenzo ya trae resuelta por categoria (ver
 *    docs/taxonomia/presentacion-fase-2.html) - no existia en el diseño de agosto porque esa fase
 *    de clasificacion se hizo despues. Determina el valor por defecto de is_active al importar.
 * 5. `is_active` nuevo: reemplaza no tener forma de "apagar" una categoria sin borrarla (para las
 *    323 DOES NOT BELONG - punto 4 del acuerdo, pendiente de confirmar con Lorenzo el default
 *    exacto para las 375 MAYBE; mientras tanto administrable a mano desde el panel).
 * 6. `version` -> `source_version`: alineado al nombre usado en
 *    docs/taxonomia/analisis_taxonomia_supplyhigh.md seccion 3 (permite reimportar sin ambiguedad
 *    con un futuro campo de "version" de otra cosa).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        Schema::connection('pgsql')->rename('supplier_categories', 'taxonomy_categories');
        Schema::connection('pgsql')->rename('supplier_category_translations', 'taxonomy_category_translations');
        Schema::connection('pgsql')->rename('supplier_category_synonyms', 'taxonomy_category_synonyms');
        Schema::connection('pgsql')->rename('empresa_supplier_categories', 'empresa_taxonomy_category');

        Schema::connection('pgsql')->table('taxonomy_categories', function (Blueprint $table) {
            $table->renameColumn('supplhi_code', 'code');
            $table->renameColumn('version', 'source_version');
            $table->string('branch')->nullable()->after('tipo_oferta');
            $table->string('subbranch')->nullable()->after('branch');
            $table->string('chamber_relevance')->nullable()->after('subbranch');
            $table->text('relevance_basis')->nullable()->after('chamber_relevance');
            $table->boolean('is_active')->default(true)->after('relevance_basis');
        });

        // Postgres no renombra automaticamente los indices/constraints al hacer rename() de tabla
        // en todas las versiones - lo dejamos como esta (siguen funcionando, solo el NOMBRE interno
        // del indice conserva "supplier_categories"/"supplhi_code"), no afecta a la aplicacion ni
        // requiere accion: es cosmetico, visible solo si alguien inspecciona pg_indexes a mano.
    }

    public function down()
    {
        Schema::connection('pgsql')->table('taxonomy_categories', function (Blueprint $table) {
            $table->dropColumn(['branch', 'subbranch', 'chamber_relevance', 'relevance_basis', 'is_active']);
            $table->renameColumn('source_version', 'version');
            $table->renameColumn('code', 'supplhi_code');
        });

        Schema::connection('pgsql')->rename('empresa_taxonomy_category', 'empresa_supplier_categories');
        Schema::connection('pgsql')->rename('taxonomy_category_synonyms', 'supplier_category_synonyms');
        Schema::connection('pgsql')->rename('taxonomy_category_translations', 'supplier_category_translations');
        Schema::connection('pgsql')->rename('taxonomy_categories', 'supplier_categories');
    }
};
