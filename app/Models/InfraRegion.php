<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InfraRegion extends Model
{
    use HasFactory;

    protected $table = 'infraregions';

    protected $fillable = [
        'infraregion_id',
        'region_name',
    ];

    public function infrasystems()
    {
        return $this->belongsTo(InfraSystem::class, 'id');
    }

    public function infrafacilities()
    {
        return $this->belongsToMany(InfraFacility::class, 'infraregion_infrasystem', 'infraregion_infrasystem.id', 'infrafacilities.id');
        //return $this->belongsToMany(InfraFacility::class, 'infraregion_infrasystem', 'infraregion_infrasystem.infraregion_id', 'infrafacilities.id');
    }

    //OBTIENE LAS INSTALACIONES ASOCIADAS A LA REGION DADA.........................................
    public function getFacility($region, $sistema)
    {
        $facility = DB::table('infrafacilities')
            ->select("*", DB::raw("infrafacilities.id, facility_name"))
            ->from('infrafacilities')
            ->join('infraregion_infrasystem', 'infrafacilities.infraregion_infrasystem_id', '=', 'infraregion_infrasystem.id')
            ->Join('infraregions', 'infraregion_infrasystem.infraregion_id', '=', 'infraregions.id')
            ->join('infrasystems', 'infraregion_infrasystem.infrasystem_id', '=', 'infrasystems.id')
            ->where([['infraregions.id', '=', $region], ['infrasystems.id', '=', $sistema]])

            ->get();

        return $facility;
    }
}
