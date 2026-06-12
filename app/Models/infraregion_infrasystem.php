<?php

namespace App\Models;

use App\Policies\InfraFacilityPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class infraregion_infrasystem extends Model
{
    use HasFactory;

    protected $table = "infraregion_infrasystem";

    /* public function infraregions()
    {
        return $this->belongsToMany(InfraRegion::class, 'infraregion_id');
    }

    public function infrasystems()
    {
        return $this->belongsToMany(InfraSystem::class, 'infraregion_infrasystem', 'infraregion_id', 'infrasystem_id');
    } */

    public function infrafacilities()
    {
        return $this->belongsToMany(InfraFacility::class, 'infraregion_infrasystem', 'infraregion_infrasystem.id', 'infrafacilities.infraregion_infrasystem_id');
        //return $this->belongsToMany(Infrafacilities::class, 'infraregion_infrasystem_id', 'infrasystem_id', 'infraregion_id');
        //return $this->belongsToMany(InfraFacility::class, 'infraregion_infrasystem', 'infrasystem_id', 'infraregion_id');
    }

    public function getRegsys()
    {
        $regsys = DB::table('infraregion_infrasystem')
            ->select("*", DB::raw("infraregion_infrasystem.id as infraid, CONCAT(infrasystems.system_name,', ',infraregions.region_name) as regsys"))
            ->from('infraregion_infrasystem')
            ->join('infraregions', 'infraregions.id', '=', 'infraregion_infrasystem.infraregion_id')
            ->Join('infrasystems', 'infrasystems.id', '=', 'infraregion_infrasystem.infrasystem_id')
            ->get();

        return $regsys;
    }
}
