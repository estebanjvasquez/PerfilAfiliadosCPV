<?php

namespace App\Filament\Pages\Auth;

use App\Support\Auth\AccountRecovery;
use App\Support\Turnstile;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\View;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * TASK-0012 (Issue #2 `6602168914`), R1: «¿Olvidaste tu contraseña?» por correo.
 *
 * La página de Filament mostraba el estado del broker: «no encontramos un usuario» o «espere» delataban si la cuenta
 * existía, y el envío SMTP inmediato también (por el tiempo). Aquí la respuesta es siempre la misma, el envío ocurre
 * después de responder (AccountRecovery::defer) y hay Turnstile y límites por origen y por correo.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    public const SENT_TITLE = 'Revise su correo';

    public const SENT_BODY = 'Si ese correo es el de una cuenta del Perfil de Afiliados, en unos minutos recibirá un enlace para elegir una contraseña nueva. Revise también la carpeta de correo no deseado. Si no llega, contacte a la Cámara.';

    public const THROTTLED_TITLE = 'Demasiadas solicitudes';

    public const THROTTLED_BODY = 'Espere unos minutos e intente de nuevo. Si no recibe el correo, contacte a la Cámara.';

    /** Token del widget Turnstile (sincronizado por JS via @this.set(...)). */
    public $ts_token = '';

    public function request(): void
    {
        $data = $this->form->getState();
        $email = AccountRecovery::normalizeEmail((string) $data['email']);
        $limits = config('account_recovery.limits');

        if (! AccountRecovery::attempt([
            'account-recovery:email:ip:'.request()->ip() => $limits['per_ip'],
            'account-recovery:email:'.sha1($email) => $limits['per_email'],
        ])) {
            Notification::make()->title(self::THROTTLED_TITLE)->body(self::THROTTLED_BODY)->danger()->send();

            return;
        }

        if (! app(Turnstile::class)->verify($this->ts_token, request()->ip())) {
            $this->ts_token = '';
            $this->dispatch('turnstile-reset');

            throw ValidationException::withMessages([
                'ts_token' => __('Verificación anti-bot fallida, intenta de nuevo.'),
            ]);
        }

        AccountRecovery::defer(fn () => AccountRecovery::sendByEmail($email));

        Notification::make()->title(self::SENT_TITLE)->body(self::SENT_BODY)->success()->send();
        $this->form->fill();
        $this->ts_token = '';
        $this->dispatch('turnstile-reset');
    }

    /**
     * @return array<int | string, string | \Filament\Forms\Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getEmailFormComponent(),
                        View::make('partials.turnstile'),
                        Placeholder::make('no_email')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<a class="text-primary-600 underline" href="'.e(route('filament.admin.auth.account-recovery.rif')).'">No recuerdo el correo de acceso</a>: '
                                .'reciba la ayuda en el correo de su cuenta usando el RIF de su empresa.'
                            )),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->helperText('Su usuario es su correo electrónico: escriba el mismo con el que entra al sistema. Le enviaremos un enlace para elegir una contraseña nueva.');
    }

    protected function getRequestFormAction(): Action
    {
        return parent::getRequestFormAction()->label('Enviar el enlace');
    }

    public function getHeading(): string | Htmlable
    {
        return '¿Olvidaste tu contraseña?';
    }
}
