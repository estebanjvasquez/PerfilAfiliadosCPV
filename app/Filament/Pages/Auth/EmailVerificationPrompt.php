<?php

namespace App\Filament\Pages\Auth;

use App\Services\TaxonomyCategoryRequestService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\EmailVerification\EmailVerificationPrompt as BasePrompt;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Support\Htmlable;

/**
 * CPV-VERIFICATION-FLOW-AND-COMPLETION (Issue #2 `6215451160`): página «Verifique su correo electrónico» del
 * panel, la que ve un usuario con sesión y sin correo verificado. Antes, ese usuario recibía 500
 * (`Route [verification.notice] not defined`).
 *
 * Es la página de Filament (`->emailVerification()` en AdminPanelProvider), con dos cambios:
 * - **Textos honestos:** si el servidor no envía correos hacia afuera (transporte `log` o `array`, el
 *   mismo criterio que TaxonomyCategoryRequestService::transportSendsExternally()), no se dice «le
 *   enviamos» ni «le reenviamos». Se dice que el mensaje no llegará todavía y cómo seguir: contactar a la
 *   Cámara. No se muestra el modo del servidor ni ninguna configuración.
 * - **Una sola vía de envío:** el reenvío usa User::sendEmailVerificationNotification(), la misma que
 *   usa el registro.
 *
 * El reenvío sigue limitado como en Filament: 2 por minuto.
 */
class EmailVerificationPrompt extends BasePrompt
{
    protected static string $view = 'filament.pages.auth.email-verification-prompt';

    public static function mailIsDelivered(): bool
    {
        return TaxonomyCategoryRequestService::transportSendsExternally();
    }

    protected function sendEmailVerificationNotification(MustVerifyEmail $user): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->sendEmailVerificationNotification();
    }

    public function resendNotificationAction(): Action
    {
        return Action::make('resendNotification')
            ->link()
            ->label(fn () => static::mailIsDelivered() ? 'Reenviar el correo de verificación' : 'Pedir un nuevo enlace')
            ->action(function (): void {
                try {
                    $this->rateLimit(2);
                } catch (TooManyRequestsException $exception) {
                    $this->getRateLimitedNotification($exception)?->send();

                    return;
                }

                $this->sendEmailVerificationNotification($this->getVerifiable());

                if (static::mailIsDelivered()) {
                    Notification::make()
                        ->title('Le reenviamos el correo de verificación')
                        ->body('Revise su bandeja de entrada y la carpeta de correo no deseado («spam»).')
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Pedido registrado')
                    ->body('El sistema todavía no envía correos, así que este mensaje no llegará a su buzón. Para activar su cuenta, comuníquese con la Cámara e indique el correo con el que se registró.')
                    ->warning()
                    ->persistent()
                    ->send();
            });
    }

    public function getTitle(): string | Htmlable
    {
        return 'Verifique su correo electrónico';
    }

    public function getHeading(): string | Htmlable
    {
        return 'Verifique su correo electrónico';
    }
}
