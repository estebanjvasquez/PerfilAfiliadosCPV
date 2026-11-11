<?php

namespace App\Filament\Pages\Auth;

use App\Support\Auth\AccountRecovery;
use App\Support\Turnstile;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\View;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * TASK-0012 (Issue #2 `6602168914`), R2/R3: «No recuerdo el correo de acceso».
 *
 * Una ayuda, no un selector de cuentas: con el RIF, cada buzón afiliado elegible de esa empresa recibe su propio enlace
 * (AccountRecovery::eligibleForRif). La pantalla responde siempre lo mismo y nunca lista, cuenta ni enmascara correos.
 * Reusa la vista y el enlace «Volver al inicio de sesión» de la página de Filament; solo cambian el formulario y el envío.
 */
class RecoverAccessByRif extends BaseRequestPasswordReset
{
    public const SENT_TITLE = 'Solicitud recibida';

    public const SENT_BODY = 'Si el RIF es el de una empresa afiliada con usuarios registrados, cada usuario recibirá en el correo de su cuenta su usuario y un enlace para elegir su propia contraseña. Por seguridad, aquí no mostramos ningún correo. Si nadie de su empresa recibe el mensaje, contacte a la Cámara e indique el RIF.';

    public const THROTTLED_TITLE = 'Demasiadas solicitudes';

    public const THROTTLED_BODY = 'Espere unos minutos e intente de nuevo. Si no recibe el correo, contacte a la Cámara.';

    /** Token del widget Turnstile (sincronizado por JS via @this.set(...)). */
    public $ts_token = '';

    public function request(): void
    {
        $data = $this->form->getState();
        $rif = AccountRecovery::normalizeRif((string) $data['rif']);
        $limits = config('account_recovery.limits');

        // TASK-0018 / SEC-03 P3 (F-SEC03-04): el cupo por origen se cuenta antes de Turnstile (frena bots), pero el cupo
        // del RIF solo después de pasarlo: un pedido sin Turnstile válido ya no agota el cupo de esa empresa.
        if (! AccountRecovery::attempt(['account-recovery:rif:ip:'.request()->ip() => $limits['per_ip']])) {
            $this->throttled();

            return;
        }

        if (! app(Turnstile::class)->verify($this->ts_token, request()->ip())) {
            $this->ts_token = '';
            $this->dispatch('turnstile-reset');

            throw ValidationException::withMessages([
                'ts_token' => __('Verificación anti-bot fallida, intenta de nuevo.'),
            ]);
        }

        if (! AccountRecovery::attempt(['account-recovery:rif:'.$rif => $limits['per_rif']])) {
            $this->throttled();

            return;
        }

        AccountRecovery::defer(fn () => AccountRecovery::sendByRif($rif));

        Notification::make()->title(self::SENT_TITLE)->body(self::SENT_BODY)->success()->send();
        $this->form->fill();
        $this->ts_token = '';
        $this->dispatch('turnstile-reset');
    }

    /** Mismo aviso para cualquier límite: no dice cuál se alcanzó ni si el RIF existe. */
    private function throttled(): void
    {
        Notification::make()->title(self::THROTTLED_TITLE)->body(self::THROTTLED_BODY)->danger()->send();
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
                        Placeholder::make('how')
                            ->hiddenLabel()
                            ->content('Su usuario es el correo electrónico con el que se registró. Si no lo recuerda, escriba el RIF de su empresa: enviaremos un mensaje al correo de cada usuario asociado a ella, con su usuario y un enlace para elegir su propia contraseña.'),
                        TextInput::make('rif')
                            ->label('RIF de la empresa')
                            ->placeholder('J-12345678-9')
                            ->required()
                            ->maxLength(20)
                            ->autofocus()
                            ->rule(fn () => function (string $attribute, $value, \Closure $fail): void {
                                if (! AccountRecovery::isValidRif(AccountRecovery::normalizeRif((string) $value))) {
                                    $fail('Escriba el RIF con su letra y sus números, por ejemplo J-12345678-9.');
                                }
                            }),
                        View::make('partials.turnstile'),
                        Placeholder::make('fallback')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                'Si recuerda su correo, use <a class="text-primary-600 underline" href="'.e(route('filament.admin.auth.password-reset.request')).'">¿Olvidaste tu contraseña?</a>. '
                                .'Si ya no tiene acceso a ese correo, contacte a la Cámara para que actualice su cuenta.'
                            )),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getRequestFormAction(): Action
    {
        return parent::getRequestFormAction()->label('Enviar la ayuda');
    }

    public function getTitle(): string | Htmlable
    {
        return 'Recuperar el acceso con el RIF';
    }

    public function getHeading(): string | Htmlable
    {
        return 'No recuerdo el correo de acceso';
    }
}
