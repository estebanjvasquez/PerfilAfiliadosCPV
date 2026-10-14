<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 - catalogo SupplHi (bilingue). Tabla canonica de la jerarquia (grupo > categoria >
 * subcategoria > item), identificada SIEMPRE por `supplhi_code` (nunca por nombre - el nombre
 * vive en supplier_category_translations, por idioma). Ver docs/Respuesta_Ampliacion_SupplHi_Bilingue.pdf
 * punto 1 y 3.
 *
 * Tabla PGSQL-only (no existe en MySQL) - `$connection = 'pgsql'` fuerza que esta migracion corra
 * contra esa conexion sin importar con que flag se invoque `migrate`, mismo patron ya usado en las
 * 13 vistas de Fase 2.
 *
 * `path`: cadena de `supplhi_code` separados por '/' desde la raiz hasta este nodo (ej.
 * "GRP-04/CAT-17/SUB-99"), recalculada por SupplierCategoryObserver en vez de a mano - se usa
 * `supplhi_code` en vez del `id` autoincremental porque el codigo se conoce ANTES del insert (lo
 * trae el archivo de importacion), evitando el problema de "necesito el id para armar el path pero
 * el id recien se genera al insertar".
 *
 * `level`: profundidad en el arbol, 0 = grupo raiz. La verificacion de Fase 4 del plan exige
 * `COUNT(*) WHERE level = 0` = 48 (los 48 grupos de SupplHi) una vez importada la taxonomia real.
 *
 * `tipo_oferta`: material/equipo/servicio (PDF punto 2) - nullable porque solo tiene sentido
 * declararlo en los niveles mas especificos (item), no en un grupo/categoria general.
 *
 * `version`: version de la taxonomia SupplHi importada (ej. "2026.1") - permite reimportar cuando
 * SupplHi actualice su clasificacion sin duplicar categorias ya vinculadas a empresas (PDF punto 1).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up()
    {
        Schema::connection('pgsql')->create('supplier_categories', function (Blueprint $table) {
            $table->id();
            $table->string('supplhi_code')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('supplier_categories')->nullOnDelete();
            $table->unsignedSmallInteger('level');
            $table->string('path');
            $table->string('tipo_oferta')->nullable();
            $table->string('version');
            $table->timestamps();

            $table->index('parent_id');
            $table->index('level');
            $table->index('path');
        });
    }

    public function down()
    {
        Schema::connection('pgsql')->dropIfExists('supplier_categories');
    }
};
