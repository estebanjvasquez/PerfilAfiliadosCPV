<?php

namespace App\Models;

/**
 * Copia de User apuntando siempre a pgsql — mismo motivo que EmpresaPgsql. Se usa solo para la
 * relación `approved_by` de EmpresaTaxonomyCategory (quién de la Cámara aprobó el vínculo
 * empresa-categoría); no reemplaza a User en ningún otro lugar de la app.
 */
class UserPgsql extends User
{
    protected $connection = 'pgsql';

    // Mismo motivo que EmpresaPgsql::$table - la convención de nombre de tabla de Eloquent usa el
    // nombre de esta clase ("UserPgsql" -> "user_pgsqls") si no se fija a mano.
    protected $table = 'users';
}
