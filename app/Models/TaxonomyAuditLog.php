<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxonomyAuditLog extends Model
{
    protected $connection = 'pgsql';

    /** La migración usa el nombre singular de la sección 11 del documento V2, no el plural que Eloquent adivinaría. */
    protected $table = 'taxonomy_audit_log';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'entity_type',
        'entity_id',
        'field',
        'old_value',
        'new_value',
        'reason',
        'taxonomy_version_id',
        'actor_type',
        'algorithm_version',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** Mismo patrón cross-connection que `EmpresaTaxonomyCategory::approvedBy` (UserPgsql, no User). */
    public function user()
    {
        return $this->belongsTo(UserPgsql::class, 'user_id');
    }
}
