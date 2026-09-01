<?php

namespace App\Filament\Pages;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Facades\Filament;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
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

    protected function getPasswordFormComponent(): Password
    {
        return Password::make('password')
            ->label(__('filament-panels::pages/auth/login.form.password.label'))
            ->autocomplete('current-password')
            ->required()
            ->revealable(true)
            ->showIcon('heroicon-o-eye')
            ->hideIcon('heroicon-o-eye-slash')
            ->helperText('Haz clic en el icono del ojo para ver tu contraseña')
            ->extraInputAttributes(['tabindex' => 2]);
    }
}
