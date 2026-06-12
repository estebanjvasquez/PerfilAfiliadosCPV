<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InfraFacility extends Model
{
    use HasFactory;

    protected $table = 'infrafacilities';

    protected $fillable = [
        'infraregion_infrasystem_id',
        'infrasystems_id',
        'infraregions_id',
        'facility_name',
    ];

    public function infraregions()
    {
        return $this->belongsToMany(InfraRegion::class, 'infraregion_infrasystem', 'infraregion_infrasystem.id', 'infraregion_id');
    }

    /*   public function infraregion_infrasystem()
    {
        return $this->belongsToMany(infraregion_infrasystem::class, 'infraregion_infrasystem_id', 'infrasystem_id', 'infraregion_id');
    } */

    /*  public function infraregions()
    {
        return $this->belongsToMany(InfraRegion::class, 'infraregion_infrasystem', 'infrasystem_id', 'infraregion_id');
    } */
    /* public function infrasystems()
    {
        return $this->belongsTo(InfraSystem::class, 'id');
    } */
}
