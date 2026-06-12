<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InfraRegion extends Model
{
    use HasFactory;

    protected $table = 'infraregions';

    protected $fillable = [
        'infrasystems_id',
        'region_name',
    ];

    public function infrasystems()
    {
        return $this->belongsTo(InfraSystem::class, 'infrasystems_id');
    }

    public function infrafacilities()
    {
        return $this->hasMany(InfraFacility::class, 'infraregions_id');
    }
}
