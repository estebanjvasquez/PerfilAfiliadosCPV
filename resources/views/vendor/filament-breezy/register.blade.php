<x-filament-breezy::auth-card action="register">
    <div class="w-full flex justify-center">
        <x-filament::brand />
    </div>

    <div>
        <h2 class="font-bold tracking-tight text-center text-2xl">
            {{ __('filament-breezy::default.registration.heading') }}
        </h2>
        <p class="mt-2 text-sm text-center">
            {{ __('filament-breezy::default.or') }}
            <a class="text-primary-600" href="{{route("filament.auth.login")}}">
                {{ strtolower(__('filament::login.heading')) }}
            </a>
        </p>
    </div>

    {{ $this->form }}

    {{-- Honeypot anti-bot: invisible para humanos, los bots lo llenan. --}}
    <div aria-hidden="true" style="position: absolute; left: -9999px; top: -9999px; height: 0; overflow: hidden;">
        <label>
            No llenar este campo
            <input type="text" tabindex="-1" autocomplete="off" wire:model.defer="website">
        </label>
    </div>

    @include('partials.turnstile')

    <x-filament::button type="submit" class="w-full">
        {{ __('filament-breezy::default.registration.submit.label') }}
    </x-filament::button>
</x-filament-breezy::auth-card>
