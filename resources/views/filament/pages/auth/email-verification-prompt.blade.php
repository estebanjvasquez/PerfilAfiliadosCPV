{{-- CPV-VERIFICATION-FLOW-AND-COMPLETION: ver App\Filament\Pages\Auth\EmailVerificationPrompt. --}}
<x-filament-panels::page.simple>
    @php($email = filament()->auth()->user()->getEmailForVerification())

    <div class="space-y-4 text-sm text-gray-600 dark:text-gray-300" data-verification-prompt>
        <p>
            Su cuenta está creada, pero falta verificar su correo electrónico <strong class="break-all">{{ $email }}</strong>.
            Hasta entonces no puede abrir el perfil de su empresa, el manual ni el resto del panel.
        </p>

        @if ($this->mailIsDelivered())
            <p>
                Le enviamos un correo con un enlace para verificarlo. Abra ese enlace desde este mismo navegador,
                con su sesión iniciada. El enlace vence a los {{ (int) config('auth.verification.expire', 60) }} minutos.
            </p>
            <p>
                Si no lo encuentra, revise la carpeta de correo no deseado («spam»).
                ¿No le llegó? {{ $this->resendNotificationAction }}
            </p>
        @else
            {{-- Colores con las variables de Filament (--warning-500), que existen en claro y oscuro sin depender de
                 un build propio de Tailwind. --}}
            <div class="rounded-lg border p-3 text-gray-950 dark:text-white" role="status"
                 style="border-color: rgba(var(--warning-500), 0.45); background-color: rgba(var(--warning-500), 0.12);">
                <p class="font-medium">El sistema todavía no envía correos.</p>
                <p class="mt-1">
                    El mensaje con el enlace de verificación no llegará a su buzón por ahora.
                    Para activar su cuenta, comuníquese con la Cámara e indique el correo con el que se registró.
                </p>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Cuando el envío esté activo, podrá pedir aquí un nuevo enlace: {{ $this->resendNotificationAction }}
            </p>
        @endif

        {{-- TASK-0011: qué sigue después de registrarse (se llega aquí justo después del registro). --}}
        <div data-next-steps>
            <p class="font-medium text-gray-950 dark:text-white">Próximos pasos</p>
            <ol style="list-style: decimal; padding-left: 1.25rem; margin-top: 0.25rem;" class="space-y-1">
                <li>Verifique su correo electrónico, como se indica arriba.</li>
                <li>Avise a la Cámara que se registró, con el nombre y el RIF de su empresa: crear la cuenta no da acceso
                    automático a su empresa; la Cámara asocia su usuario con ella.</li>
                <li>Cuando la Cámara lo haga, al entrar verá su empresa en <strong>Inicio</strong>, con lo que falta completar.</li>
            </ol>
        </div>

        <form method="POST" action="{{ filament()->getLogoutUrl() }}" class="pt-2 text-center">
            @csrf
            <x-filament::button type="submit" color="gray" outlined size="sm">
                Cerrar sesión
            </x-filament::button>
        </form>
    </div>
</x-filament-panels::page.simple>
