<?php

namespace App\Filament\Pages;

use App\Support\Auth\AccountRecovery;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Phpsa\FilamentPasswordReveal\Password;

/**
 * Breezy v2 elimino su propio componente de Login (el paquete ahora delega
 * por completo en el Login nativo de Filament v3 - JeffGreco13\FilamentBreezy\
 * Http\Livewire\Auth\Login ya no existe). Se reescribe extendiendo
 * Filament\Pages\Auth\Login directamente, preservando intacta la logica de
 * negocio propia: rate limiting + verificacion de Turnstile antes de
 * intentar autenticar, y el campo de password con reveal (Phpsa).
 *
 * mount() se elimina - es identica a la del padre en v3 (chequea sesion +
 * $this->form->fill()), no hace falta repetirla.
 */
class Login extends BaseLogin
{
    use WithRateLimiting;

    /** Token del widget Turnstile (sincronizado por JS via @this.set(...)). */
    public $ts_token = '';

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            throw ValidationException::withMessages([
                'data.email' => __('filament-panels::pages/auth/login.messages.throttled', [
                    'seconds' => $exception->secondsUntilAvailable,
                    'minutes' => ceil($exception->secondsUntilAvailable / 60),
                ]),
            ]);
        }

        if (! app(\App\Support\Turnstile::class)->verify($this->ts_token, request()->ip())) {
            $this->ts_token = '';
            $this->dispatch('turnstile-reset');

            throw ValidationException::withMessages([
                'ts_token' => __('Verificación anti-bot fallida, intenta de nuevo.'),
            ]);
        }

        $data = $this->form->getState();

        if (! Filament::auth()->attempt($this->getCredentialsFromFormData($data), $data['remember'] ?? false)) {
            $this->throwFailureValidationException();
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }

    /**
     * @return array<int|string, Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getRememberFormComponent(),
                        View::make('partials.turnstile'),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    /** TASK-0012: el usuario es el correo; se dice en la propia pantalla. */
    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->helperText('Su usuario es su correo electrónico.');
    }

    /**
     * TASK-0012 (D3): Postgres compara el correo exacto y hay correos guardados con mayúsculas. Si el correo escrito
     * coincide con una sola cuenta sin distinguir mayúsculas ni espacios, se usa el guardado; si no, el escrito. La
     * contraseña se comprueba igual que antes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $credentials = parent::getCredentialsFromFormData($data);
        if ($user = AccountRecovery::findByEmail((string) $credentials['email'])) {
            $credentials['email'] = $user->email;
        }

        return $credentials;
    }

    protected function getPasswordFormComponent(): Password
    {
        return Password::make('password')
            ->label(__('filament-panels::pages/auth/login.form.password.label'))
            // TASK-0012: el enlace que Filament pone en este campo; se perdía al redefinirlo.
            ->hint(filament()->hasPasswordReset() ? new HtmlString(Blade::render('<x-filament::link :href="filament()->getRequestPasswordResetUrl()" tabindex="3">¿Olvidaste tu contraseña?</x-filament::link>')) : null)
            ->autocomplete('current-password')
            ->required()
            ->revealable(true)
            ->showIcon('heroicon-o-eye')
            ->hideIcon('heroicon-o-eye-slash')
            ->helperText('Haz clic en el icono del ojo para ver tu contraseña')
            ->extraInputAttributes(['tabindex' => 2]);
    }
}
