<?php

namespace App\Notifications\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * TASK-0012 (Issue #2 `6602168914`): el correo de recuperación, para una sola cuenta.
 *
 * Dice cuál es el usuario (el propio correo del destinatario) y lleva un enlace que solo sirve para esa cuenta. Si se
 * pidió con el RIF de la empresa, lo explica: cada usuario cambia solo su propia clave. No nombra a otras cuentas.
 */
class AccountRecoveryLink extends Notification
{
    public readonly bool $viaRif;

    public function __construct(
        public readonly string $url,
        public readonly string $username,
        public readonly ?string $rif = null,
    ) {
        $this->viaRif = $rif !== null;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        $message = (new MailMessage)
            ->subject('Recuperar el acceso al Perfil de Afiliados')
            ->greeting('Hola:');

        if ($this->viaRif) {
            $message->line("Alguien pidió ayuda para entrar al Perfil de Afiliados con el RIF {$this->rif}, de una empresa a la que usted está asociado.")
                ->line('Este mensaje es solo para usted. Si la empresa tiene otros usuarios, cada uno recibe el suyo y cambia solo su propia contraseña.');
        } else {
            $message->line('Recibimos una solicitud para restablecer la contraseña de su cuenta del Perfil de Afiliados.');
        }

        return $message
            ->line("Su usuario para entrar es este correo: **{$this->username}**")
            ->action('Elegir una contraseña nueva', $this->url)
            ->line("El enlace vence en {$minutes} minutos y sirve una sola vez.")
            ->line('Si no lo pidió, ignore este mensaje: su contraseña no cambia.');
    }
}
