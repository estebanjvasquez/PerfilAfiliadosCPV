<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cache de Empresa::completionPercentage(), recalculado automaticamente por
 * EmpresaCompletionObserver (assets/management/moduleStatuses/etc.) y por los hooks
 * ->after() en ServicesRelationManager/ContactsRelationManager (attach/detach
 * de servicios y contactos no dispara eventos de modelo).
 *
 * Portado de feature/supplhi-postgres-buscador (motivo original: Fase 2,
 * verificacion de rendimiento contra Postgres/Supabase real; localmente
 * contra mysql el mismo N+1 tardaba ~90s/311 queries en listar 30 empresas).
 * Se sigue usando moduleBreakdown()/completionPercentage() como unica fuente
 * de verdad, solo que se guarda su resultado en vez de recalcularlo en cada
 * carga.
 *
 * NO vive en database/migrations/pgsql/ porque "empresas" es una tabla base
 * compartida entre mysql y pgsql - esta migracion debe aplicarse a AMBOS
 * motores:
 *   php artisan migrate --force
 *   php artisan migrate --database=pgsql --force
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
