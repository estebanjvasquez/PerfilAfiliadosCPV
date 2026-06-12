<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InfraFacility extends Model
{
    use HasFactory;

    protected $table = 'infrafacilities';

    protected $fillable = [
        'infraregions_id',
        'facility_name',
    ];

    public function infraregions()
    {
        return $this->belongsTo(InfraRegion::class, 'infraregions_id');
    }
}
