<?php

namespace App\Models;

/**
 * Copia de User apuntando siempre a pgsql - mismo motivo que EmpresaPgsql. Se usa solo para la
 * relacion `approved_by` de EmpresaSupplierCategory (quien de la Camara aprobo el vinculo
 * empresa-categoria); no reemplaza a User en ningun otro lugar de la app.
 */
class UserPgsql extends User
{
    protected $connection = 'pgsql';

    // Mismo motivo que EmpresaPgsql::$table - la convencion de nombre de tabla de Eloquent usa el
    // nombre de esta clase ("UserPgsql" -> "user_pgsqls") si no se fija a mano.
    protected $table = 'users';
}
