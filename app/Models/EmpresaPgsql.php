<?php

namespace App\Models;

/**
 * Copia de Empresa apuntando SIEMPRE a la conexion pgsql - usada exclusivamente por el catalogo
 * SupplHi (Fase 4 en adelante) y lo que dependa de el (buscador hibrido de Fase 5, UI de carga de
 * Fase 6). Existe porque Empresa (la clase real) sigue con su conexion default en mysql durante
 * todo el desarrollo (ver plan de migracion, Fase 0: "mysql sigue siendo la conexion default") -
 * una relacion belongsTo/belongsToMany definida sobre Empresa resolveria via mysql, donde las
 * tablas nuevas de este catalogo no existen. Cuando la Fase 8 (corte a produccion) cambie el
 * default de Empresa a pgsql, esta clase deja de hacer falta y sus relaciones (supplierCategories/
 * catalogItems) se pueden mover directo a Empresa sin cambiar nada mas.
 *
 * Extiende Empresa (no Model) para heredar los casts/atributos/relaciones ya definidos ahi sin
 * duplicarlos - solo se pisa la conexion y se agregan las relaciones nuevas del catalogo.
 */
class EmpresaPgsql extends Empresa
{
    protected $connection = 'pgsql';

    // Empresa infiere el nombre de tabla por convencion de Eloquent (su $table esta comentado) -
    // esa convencion usa el nombre de ESTA clase ("EmpresaPgsql" -> "empresa_pgsqls") si no se
    // fija explicitamente, asi que hay que declararla a mano al heredar con otro nombre de clase.
    protected $table = 'empresas';

    public function supplierCategories()
    {
        return $this->belongsToMany(
            SupplierCategory::class,
            'empresa_supplier_categories',
            'empresa_id',
            'category_id'
        )->withPivot(['origen', 'approved_by', 'approved_at'])->withTimestamps();
    }

    public function catalogItems()
    {
        return $this->hasMany(EmpresaCatalogItem::class, 'empresa_id');
    }
}
