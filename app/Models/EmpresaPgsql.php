<?php

namespace App\Models;

/**
 * Copia de Empresa apuntando SIEMPRE a la conexión pgsql — usada exclusivamente por la taxonomía
 * CPV y lo que dependa de ella (buscador híbrido). Existe porque `Empresa` (la clase real) sigue
 * con su conexión default configurable por `.env` (`DB_CONNECTION`) — localmente suele ser mysql
 * mientras el corte final a producción no se haga — y una relación belongsTo/belongsToMany
 * definida sobre `Empresa` resolvería por esa conexión default, no necesariamente pgsql, donde
 * viven las tablas de taxonomía. Mismo patrón ya usado y verificado en
 * `feature/supplhi-postgres-buscador` (`EmpresaPgsql`/`UserPgsql`, commit `45a10bd`).
 *
 * Extiende Empresa (no Model) para heredar los casts/atributos/relaciones ya definidos ahí sin
 * duplicarlos — solo se pisa la conexión y se agregan las relaciones nuevas de taxonomía.
 */
class EmpresaPgsql extends Empresa
{
    protected $connection = 'pgsql';

    // Empresa infiere el nombre de tabla por convención de Eloquent (su $table está comentado) -
    // esa convención usa el nombre de ESTA clase ("EmpresaPgsql" -> "empresa_pgsqls") si no se
    // fija explícitamente, así que hay que declararla a mano al heredar con otro nombre de clase.
    protected $table = 'empresas';

    // CPV-MODEL-COMPATIBILITY-AND-LABELS (Issue #2 `6211997650`, investigación `6190871814`): esta clase
    // redefinía taxonomyCategories() como belongsToMany(TaxonomyCategory) sin tipo de retorno. Eso es
    // incompatible con Empresa::taxonomyCategories(): HasMany, y PHP lo rechaza con un error fatal al
    // cargar la clase, antes de cualquier consulta. Ningún consumidor usaba la variante de categorías
    // directas: el relation manager de Categorías CPV y la solicitud de categoría trabajan con los
    // registros de vínculo (EmpresaTaxonomyCategory, con origen, es_principal y aprobación). Por eso
    // se hereda la relación del padre tal cual, y no se agrega una API nueva sin uso.
}
