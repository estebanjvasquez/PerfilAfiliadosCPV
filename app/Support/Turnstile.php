<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

class Turnstile
{
    /**
     * El CAPTCHA solo se exige si hay clave secreta configurada.
     * Sin claves, se comporta como antes (sin bloqueo).
     */
    public function enabled(): bool
    {
        return filled(config('services.turnstile.secret_key'));
    }

    /**
     * Verifica el token del widget contra el endpoint siteverify de Cloudflare.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return (bool) $response->json('success', false);
    }
}
