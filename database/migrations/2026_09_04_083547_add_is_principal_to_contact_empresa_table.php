<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contacto principal de la empresa (pedido del cliente): un solo contacto por empresa
     * marcado como principal, usado en reportes/graficas en vez de tomar cualquiera de la
     * lista sin criterio. Vive en el pivot (no en "contacts") porque es una propiedad de la
     * relacion empresa<->contacto, no del contacto en si (mismo patron que Empresa::
     * principalUser(), salvo que ahi no hay columna - se infiere por antiguedad porque
     * empresa_user no tiene esta necesidad explicita del cliente).
     */
    public function up(): void
    {
        Schema::table('contact_empresa', function (Blueprint $table) {
            $table->boolean('is_principal')->default(false)->after('contact_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_empresa', function (Blueprint $table) {
            $table->dropColumn('is_principal');
        });
    }
};
