<?php

namespace App\Http\Livewire\Auth;

use App\Support\Turnstile;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Validation\ValidationException;
use JeffGreco13\FilamentBreezy\Http\Livewire\Auth\Register as BreezyRegister;

class Register extends BreezyRegister
{
    use WithRateLimiting;

    /** Token del widget Turnstile (sincronizado por JS). */
    public string $ts_token = '';

    /** Honeypot: debe quedar vacio; si un bot lo llena, se rechaza. */
    public string $website = '';

    public function register()
    {
        // 1) Honeypot: los humanos no ven este campo, los bots lo llenan.
        if (filled($this->website)) {
            abort(422);
        }

        // 2) Rate-limit por IP (el registro no tenia ningun limite).
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            throw ValidationException::withMessages([
                'email' => __('filament::login.messages.throttled', [
                    'seconds' => $exception->secondsUntilAvailable,
                    'minutes' => ceil($exception->secondsUntilAvailable / 60),
                ]),
            ]);
        }

        // 3) CAPTCHA server-side.
        if (! app(Turnstile::class)->verify($this->ts_token, request()->ip())) {
            $this->ts_token = '';
            $this->dispatchBrowserEvent('turnstile-reset');

            throw ValidationException::withMessages([
                'ts_token' => __('Verificación anti-bot fallida, intenta de nuevo.'),
            ]);
        }

        // 4) Registro normal del vendor (crea usuario + evento + login).
        return parent::register();
    }
}
