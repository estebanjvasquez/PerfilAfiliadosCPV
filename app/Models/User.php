<?php

namespace App\Models;

use Spatie\Permission\Traits\HasRoles;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\DB;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;


class User extends Authenticatable implements MustVerifyEmail, FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'facebook_profile',
        'instagram_profile',
        'email_verified_at',

    ];

    public function empresas()
    {

        return $this->belongsToMany(Empresa::class, 'empresa_user');
        // ->wherePivot('empresa_id', 5);
    }

    /**
     * Filament v3 exige implementar FilamentUser::canAccessPanel(); sin esto, el panel
     * solo se permite cuando APP_ENV=local (salvaguarda propia de Filament para no dejar
     * un panel sin autorizacion explicita en un entorno real) - por eso cualquier usuario
     * autenticado recibia 403 en staging/produccion pese a loguear bien. Esto no existia
     * en Filament v2 (todo usuario autenticado podia entrar); se mantiene ese mismo
     * comportamiento aqui para no cambiar reglas de acceso como parte del upgrade.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * CPV-SEC-01 F-01 (Issue #2 `6293112309`): only a super admin may impersonate («Impersonate» in Usuarios). The
     * impersonation packages (stechstudio/filament-impersonate, lab404/laravel-impersonate) allow it to anyone when this
     * method does not exist, so seeing the user list was enough. The rules for who can open Usuarios are unchanged.
     */
    public function canImpersonate(): bool
    {
        return $this->hasRole(config('filament-shield.super_admin.name'));
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_id');
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function presences()
    {
        return $this->hasMany(Presence::class);
    }

    /**
     * CPV-VERIFICATION-FLOW-AND-COMPLETION (Issue #2 `6215451160`): el aviso de verificación lleva el enlace
     * firmado del PANEL (`filament.admin.auth.email-verification.verify`, con vencimiento
     * `auth.verification.expire`), no la ruta `verification.verify` de Laravel, que esta app no tiene.
     *
     * Es la única vía de envío. La usan el registro (App\Filament\Pages\Register), el «Pedir un nuevo enlace»
     * de la página de verificación y, si algo dispara el evento Registered de Laravel, su listener
     * SendEmailVerificationNotification.
     *
     * Sale por el transporte configurado. Con `log`, como hoy en staging, no llega a ningún buzón.
     */
    public function sendEmailVerificationNotification(): void
    {
        $notification = app(\Filament\Notifications\Auth\VerifyEmail::class);
        $notification->url = \Filament\Facades\Filament::getPanel('admin')->getVerifyEmailUrl($this);

        $this->notify($notification);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
}
