<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache de Empresa::completionPercentage(), recalculado automaticamente por
 * EmpresaObserver (assets/management/moduleStatuses/etc.) y por los hooks
 * ->after() en ServicesRelationManager/ContactsRelationManager (attach/detach
 * de servicios y contactos no dispara eventos de modelo - ver comentario en
 * app/Providers/AppServiceProvider.php).
 *
 * Motivo (Fase 2, verificacion de rendimiento contra Postgres/Supabase real):
 * el listado de empresas (EmpresaResource) llamaba completionPercentage() por
 * fila, que toca 4 relaciones (assets, management, moduleStatuses, y las
 * restantes ya resueltas via withCount/withExists) - cada una 1 round-trip
 * mas al query principal. Con ~470ms fijos por round-trip contra Supabase
 * remoto, cachear este valor elimina esas 3 relaciones (services queda,
 * se sigue mostrando su nombre en la tabla) sin cambiar ningun resultado
 * visible: se sigue usando moduleBreakdown()/completionPercentage() como
 * unica fuente de verdad, solo que se guarda su resultado en vez de
 * recalcularlo en cada carga de pagina.
 *
 * NO vive en database/migrations/pgsql/ (ver README ahi) porque "empresas"
 * es una tabla base sincronizada entre mysql y pgsql (Fase 1) - esta
 * migracion debe aplicarse a AMBOS motores:
 *   php artisan migrate --force
 *   php artisan migrate --database=pgsql --force
 * (la segunda SIN --path, distinto del patron de las vistas/tablas
 * exclusivas de Postgres de Fase 2/4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->unsignedTinyInteger('completion_percentage')->default(0)->after('status_id');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('completion_percentage');
        });
    }
};
