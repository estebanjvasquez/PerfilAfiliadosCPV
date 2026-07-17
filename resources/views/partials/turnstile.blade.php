{{-- Widget Cloudflare Turnstile. Solo se renderiza si hay site_key configurada. --}}
@if (filled(config('services.turnstile.site_key')))
    <div wire:ignore>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        <div
            class="cf-turnstile"
            data-sitekey="{{ config('services.turnstile.site_key') }}"
            data-callback="onTurnstileSuccess"
            data-expired-callback="onTurnstileExpired"
            data-error-callback="onTurnstileError"
        ></div>
    </div>

    <script>
        // El token es de un solo uso: se sincroniza con la propiedad Livewire ts_token.
        function onTurnstileSuccess(token) { @this.set('ts_token', token); }
        function onTurnstileExpired() { @this.set('ts_token', ''); }
        function onTurnstileError() { @this.set('ts_token', ''); }

        // Tras un error de validacion el componente pide resetear el widget.
        window.addEventListener('turnstile-reset', function () {
            if (window.turnstile) { window.turnstile.reset(); }
        });
    </script>

    @error('ts_token')
        <p class="text-sm text-danger-600 mt-1">{{ $message }}</p>
    @enderror
@endif
