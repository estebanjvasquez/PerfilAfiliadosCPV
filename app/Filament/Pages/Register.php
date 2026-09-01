<?php

namespace App\Filament\Pages;

use App\Support\Turnstile;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Illuminate\Validation\ValidationException;

/**
 * Breezy v2 elimino su propio componente de Register igual que el de Login
 * (JeffGreco13\FilamentBreezy\Http\Livewire\Auth\Register ya no existe) - se
 * reescribe extendiendo Filament\Pages\Auth\Register directamente.
 *
 * El honeypot ($website) y el Turnstile vivian antes en un blade publicado a
 * mano (resources/views/vendor/filament-breezy/register.blade.php, con
 * wire:model.defer="website" fuera del form builder de Filament, porque el
 * Register de Breezy v1/v2-viejo no se armaba via schema()). El Register
 * nativo de v3 SI se arma via getForms()/schema(), asi que ambos pasan a ser
 * campos reales del formulario (honeypot con clase "hidden" en vez de
 * wire:model.defer suelto) - se leen de $data en vez de $this->website.
 */
class Register extends \Filament\Pages\Auth\Register
{
    use WithRateLimiting;

    /** Token del widget Turnstile (sincronizado por JS via @this.set(...)). */
    public string $ts_token = '';

    public function register(): ?\Filament\Http\Responses\Auth\Contracts\RegistrationResponse
    {
        // Rate-limit por IP (el registro no tenia ningun limite antes de esto).
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            throw ValidationException::withMessages([
                'data.email' => __('filament-panels::pages/auth/register.notifications.throttled.title', [
                    'seconds' => $exception->secondsUntilAvailable,
                    'minutes' => ceil($exception->secondsUntilAvailable / 60),
                ]),
            ]);
        }

        // Honeypot: los humanos no ven este campo (CSS), los bots lo llenan.
        if (filled($this->form->getState()['website'] ?? null)) {
            abort(422);
        }

        // CAPTCHA server-side.
        if (! app(Turnstile::class)->verify($this->ts_token, request()->ip())) {
            $this->ts_token = '';
            $this->dispatch('turnstile-reset');

            throw ValidationException::withMessages([
                'ts_token' => __('Verificación anti-bot fallida, intenta de nuevo.'),
            ]);
        }

        return parent::register();
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
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                        TextInput::make('website')
                            ->label('')
                            ->extraAttributes(['class' => 'hidden'])
                            ->extraInputAttributes(['tabindex' => -1, 'autocomplete' => 'off'])
                            ->dehydrated(),
                        View::make('partials.turnstile'),
                    ])
                    ->statePath('data'),
            ),
        ];
    }
}
