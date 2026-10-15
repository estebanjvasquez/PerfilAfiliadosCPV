<?php

namespace App\Services;

use App\Filament\Resources\EmpresaResource;
use App\Mail\TaxonomyCategoryRequestMail;
use App\Models\Empresa;
use App\Models\EmpresaTaxonomyCategory;
use App\Exceptions\CategoryRequestNoticeException;
use App\Models\TaxonomyCategoryRequest;
use App\Models\TaxonomyCategoryRequestDeliveryAttempt;
use App\Models\TaxonomySelectionSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * TASK-0010A (Issue #2 comentario `6079967780`): registra una solicitud de REVISIÓN de categoría
 * CPV y notifica a la Cámara por correo.
 *
 * Reglas del contrato que este servicio garantiza (y no la Action de Filament):
 * - empresa/usuario/contexto se derivan en el servidor; del formulario solo se toman los 4 campos
 *   de texto declarados (`necesidad`, `justificacion`, `detalle`, `terminos_probados`);
 * - se persiste PRIMERO y el correo va después: si no hay destinatario configurado o el envío
 *   falla, la solicitud queda guardada con `delivery_status = failed` y el detalle técnico solo
 *   va al log del servidor;
 * - nunca escribe en `taxonomy_categories`, TERM→CPV, conceptos canónicos ni mapeos de búsqueda
 *   (la única tabla que escribe es `taxonomy_category_requests`).
 */
class TaxonomyCategoryRequestService
{
    public const NECESIDAD_MAX = 500;
    public const DETALLE_MIN = 20;
    public const DETALLE_MIN_OTRO = 40;
    public const DETALLE_MAX = 3000;
    public const TERMINOS_MAX = 500;

    /** Motivos de falla de entrega guardados en la fila (códigos, nunca el mensaje de la excepción). */
    public const ERROR_SIN_DESTINATARIO = TaxonomyCategoryRequest::ERROR_SIN_DESTINATARIO;
    public const ERROR_ENVIO = TaxonomyCategoryRequest::ERROR_ENVIO;

    /** TASK-0010C: transportes que no envían nada fuera del servidor. */
    public const SIMULATED_TRANSPORTS = ['log', 'array'];

    /** TASK-0010C: minutos tras los cuales una reserva de aviso abandonada deja de bloquear. */
    public const CLAIM_TTL_MINUTES = 10;

    /**
     * @param  array<string, mixed>  $data  Datos del formulario - cualquier clave fuera de las 4 declaradas se ignora.
     *
     * @throws AuthorizationException si el usuario no tiene acceso a la empresa.
     * @throws \Illuminate\Validation\ValidationException si los datos no son válidos.
     */
    public function submit(Empresa $empresa, User $user, array $data): TaxonomyCategoryRequest
    {
        if (! self::userCanAccessEmpresa($user, $empresa)) {
            throw new AuthorizationException('El usuario no tiene acceso a esta empresa.');
        }

        // NORMALIZAR ANTES DE VALIDAR, no después.
        //
        // Antes se validaba el texto crudo y se recortaba al persistir, así que un `detalle` de un
        // carácter más 19 espacios pasaba `min:20` y se guardaba con 1 carácter: por debajo del
        // mínimo que el propio contrato declara. Validar exactamente lo que se va a guardar cierra
        // esa diferencia, en vez de confiar en que nadie rellene con espacios.
        $data = self::normalize($data);

        $validated = Validator::make($data, self::rules($data['justificacion'] ?? null))->validate();

        $request = TaxonomyCategoryRequest::create([
            'empresa_id' => $empresa->getKey(),
            'user_id' => $user->getKey(),
            'necesidad' => $validated['necesidad'],
            'justificacion' => $validated['justificacion'],
            'detalle' => $validated['detalle'],
            'terminos_probados' => filled($validated['terminos_probados'] ?? null) ? $validated['terminos_probados'] : null,
            'context_snapshot' => $this->contextSnapshot($empresa, $user),
            'request_status' => TaxonomyCategoryRequest::STATUS_PENDING_REVIEW,
            'delivery_status' => TaxonomyCategoryRequest::DELIVERY_PENDING,
            'submitted_at' => now(),
        ]);

        // El aviso NUNCA puede hacer perder la solicitud: ya está guardada. Si el intento mismo falla
        // de forma inesperada (no un error de correo, que deliverNotice registra), la solicitud
        // queda `pending` y se puede reintentar desde el panel.
        try {
            $this->deliverNotice($request, TaxonomyCategoryRequestDeliveryAttempt::KIND_INITIAL, $user, [TaxonomyCategoryRequest::DELIVERY_PENDING]);
        } catch (Throwable $e) {
            Log::error('TASK-0010C: no se pudo completar el intento inicial de aviso; la solicitud queda registrada como pendiente.', [
                'taxonomy_category_request_id' => $request->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }

        return $request->refresh();
    }

    /**
     * Recorta SOLO los cuatro campos declarados, y sólo cuando ya son cadenas.
     *
     * Deliberadamente NO convierte tipos. Si llega un array donde se espera texto, se deja como
     * está para que el validador lo rechace con un `ValidationException` legible; castearlo a
     * cadena lo convertiría en un dato inventado, y dejar que explote más adelante lo convertiría
     * en un `TypeError` (un 500, no un error de validación). Cualquier clave fuera de las cuatro
     * se ignora aquí igual que se ignora al persistir.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        foreach (['necesidad', 'justificacion', 'detalle', 'terminos_probados'] as $campo) {
            if (array_key_exists($campo, $data) && is_string($data[$campo])) {
                $data[$campo] = trim($data[$campo]);
            }
        }

        return $data;
    }

    /**
     * Reglas de validación (compartidas con el formulario de Filament).
     *
     * `mixed` y no `?string`: este método recibe la justificación CRUDA, antes de validar, así que
     * un array o un número llegaban a un parámetro tipado y producían un `TypeError` - un 500 en
     * vez del `ValidationException` que corresponde. Lo único que se decide aquí es si aplica el
     * mínimo más exigente de `OTRO`, y eso sólo puede ser cierto para esa cadena exacta; cualquier
     * otro valor cae en el mínimo normal y lo rechaza la regla `string`/`Rule::in` de abajo.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(mixed $justificacion = null): array
    {
        $detalleMin = $justificacion === TaxonomyCategoryRequest::JUSTIFICACION_OTRO ? self::DETALLE_MIN_OTRO : self::DETALLE_MIN;

        return [
            'necesidad' => ['required', 'string', 'min:3', 'max:'.self::NECESIDAD_MAX],
            'justificacion' => ['required', 'string', Rule::in(array_keys(TaxonomyCategoryRequest::JUSTIFICACIONES))],
            'detalle' => ['required', 'string', 'min:'.$detalleMin, 'max:'.self::DETALLE_MAX],
            'terminos_probados' => ['nullable', 'string', 'max:'.self::TERMINOS_MAX],
        ];
    }

    /**
     * Mismo alcance que EmpresaResource::getEloquentQuery() (empresas vinculadas al usuario en
     * `empresa_user`) más el bypass estándar de Super Admin de filament-shield - no agrega ningún
     * privilegio nuevo.
     */
    public static function userCanAccessEmpresa(User $user, Empresa $empresa): bool
    {
        if ($user->hasRole(config('filament-shield.super_admin.name'))) {
            return true;
        }

        return $empresa->users()->where('users.id', $user->getKey())->exists();
    }

    /** @return array<string, mixed> */
    private function contextSnapshot(Empresa $empresa, User $user): array
    {
        $categorias = EmpresaTaxonomyCategory::query()
            ->where('empresa_id', $empresa->getKey())
            ->with('category.translations')
            ->orderByDesc('es_principal')
            ->orderBy('id')
            ->get()
            ->map(fn (EmpresaTaxonomyCategory $vinculo) => [
                'category_id' => $vinculo->category_id,
                'code' => $vinculo->category?->code,
                'breadcrumb' => $vinculo->category?->breadcrumb('es') ?? '—',
                'es_principal' => (bool) $vinculo->es_principal,
            ])
            ->values()
            ->all();

        return [
            'empresa' => [
                'id' => $empresa->getKey(),
                'name' => $empresa->name,
                'rif' => $empresa->rif,
                'phone' => $empresa->phone,
                'website' => $empresa->website,
            ],
            'usuario' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'categorias_vinculadas' => $categorias,
        ];
    }

    /**
     * TASK-0010C: reintento MANUAL del aviso de una solicitud ya registrada.
     *
     * Reutiliza la solicitud tal como está guardada (su snapshot de empresa/usuario/categorías y el
     * mismo Mailable): no crea otra solicitud ni reescribe su contenido. Solo Super Admin, y el
     * chequeo vive AQUÍ además de en la policy, para que ninguna ruta del panel lo saltee.
     *
     * @throws AuthorizationException si el actor no es Super Admin.
     * @throws CategoryRequestNoticeException si no hay destinatario, el estado no admite reintento
     *                                        o ya hay un intento en curso.
     */
    public function retryNotice(TaxonomyCategoryRequest $request, User $actor): TaxonomyCategoryRequestDeliveryAttempt
    {
        if (! $actor->hasRole(config('filament-shield.super_admin.name'))) {
            throw new AuthorizationException('Solo Super Admin puede reintentar avisos de solicitudes.');
        }

        $request->refresh();

        if (! $request->isRetryable()) {
            throw CategoryRequestNoticeException::notRetryable();
        }

        // Sin destinatario el reintento solo produciría otro fallo idéntico: se rechaza sin
        // registrar intento y el panel explica qué configurar.
        if (self::configuredRecipient() === null) {
            throw CategoryRequestNoticeException::recipientMissing();
        }

        return $this->deliverNotice(
            $request,
            TaxonomyCategoryRequestDeliveryAttempt::KIND_MANUAL_RETRY,
            $actor,
            TaxonomyCategoryRequest::RETRYABLE_DELIVERY,
        );
    }

    /** Destinatario configurado y válido, o null. Nunca se inventa uno. */
    public static function configuredRecipient(): ?string
    {
        $recipient = TaxonomySelectionSettings::current()->category_request_recipient_email;

        return filled($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL) ? $recipient : null;
    }

    /**
     * Modo de transporte del correo por defecto (`smtp`, `log`, `array`, ...). Solo el NOMBRE del
     * modo: nunca host, usuario ni credenciales, así que se puede mostrar al administrador.
     */
    public static function transportMode(): string
    {
        $mailer = (string) config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");

        return is_string($transport) && $transport !== '' ? $transport : $mailer;
    }

    /**
     * Explicación del modo de transporte para el ADMINISTRADOR (nunca para el afiliado). Solo dice
     * el nombre del modo y qué significa un resultado; no revela ninguna configuración del servidor.
     */
    public static function transportDescription(): string
    {
        $mode = self::transportMode();

        if (! self::transportSendsExternally($mode)) {
            return "Simulado (modo «{$mode}»): los avisos quedan registrados en el servidor y NO se envían a ningún buzón. "
                .'Activar el envío real requiere configurar el servidor de correo, que es una decisión aparte.';
        }

        return "Envío real (modo «{$mode}»): un aviso «aceptado» significa que el servidor de correo recibió el mensaje para enviarlo. "
            .'No confirma que haya llegado al buzón del destinatario.';
    }

    /** `log` y `array` no envían nada fuera del servidor: un "éxito" con ellos es una simulación. */
    public static function transportSendsExternally(?string $transport = null): bool
    {
        return ! in_array($transport ?? self::transportMode(), self::SIMULATED_TRANSPORTS, true);
    }

    /**
     * Un intento de aviso, inicial o manual, con exclusión mutua y sin transacción abierta durante
     * el envío.
     *
     * 1. RESERVA: un único UPDATE condicional marca la solicitud con un token, sólo si su estado está
     *    en `$eligible` y no hay otra reserva vigente. Dos llamadas simultáneas: una afecta 1 fila y
     *    la otra 0, y la segunda se rechaza sin enviar nada. El bloqueo de fila dura lo que esa
     *    sentencia, no el envío.
     * 2. ENVÍO: fuera de cualquier transacción. Si el proceso muere aquí, la reserva vence a los
     *    CLAIM_TTL_MINUTES y la solicitud vuelve a poder reintentarse: nunca queda trabada.
     * 3. CIERRE: una transacción corta registra el intento y actualiza la solicitud SOLO si la
     *    reserva sigue siendo de este intento.
     *
     * @param  array<int, string>  $eligible
     */
    private function deliverNotice(TaxonomyCategoryRequest $request, string $kind, ?User $actor, array $eligible): TaxonomyCategoryRequestDeliveryAttempt
    {
        $token = (string) Str::uuid();

        $claimed = TaxonomyCategoryRequest::query()
            ->whereKey($request->getKey())
            ->whereIn('delivery_status', $eligible)
            ->where(fn ($q) => $q->whereNull('delivery_claimed_at')
                ->orWhere('delivery_claimed_at', '<', now()->subMinutes(self::CLAIM_TTL_MINUTES)))
            ->update(['delivery_claim_token' => $token, 'delivery_claimed_at' => now()]);

        if ($claimed !== 1) {
            throw CategoryRequestNoticeException::inProgress();
        }

        $startedAt = now();
        $recipient = self::configuredRecipient();
        $transport = self::transportMode();
        $error = null;

        if ($recipient === null) {
            Log::warning('TASK-0010A: solicitud de revisión de categoría registrada sin correo - destinatario no configurado o inválido en taxonomy_selection_settings.category_request_recipient_email.', [
                'taxonomy_category_request_id' => $request->id,
                'empresa_id' => $request->empresa_id,
                'kind' => $kind,
            ]);
            $result = TaxonomyCategoryRequest::DELIVERY_FAILED;
            $error = self::ERROR_SIN_DESTINATARIO;
        } else {
            try {
                Mail::to($recipient)->send(new TaxonomyCategoryRequestMail($request->fresh(), $this->adminUrl((int) $request->empresa_id)));
                $result = self::transportSendsExternally($transport)
                    ? TaxonomyCategoryRequest::DELIVERY_SENT
                    : TaxonomyCategoryRequest::DELIVERY_SIMULATED;
            } catch (Throwable $e) {
                Log::error('TASK-0010A: falló el envío del correo de solicitud de revisión de categoría (la solicitud SÍ quedó registrada).', [
                    'taxonomy_category_request_id' => $request->id,
                    'empresa_id' => $request->empresa_id,
                    'kind' => $kind,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ]);
                $result = TaxonomyCategoryRequest::DELIVERY_FAILED;
                $error = self::ERROR_ENVIO;
            }
        }

        return DB::connection('pgsql')->transaction(function () use ($request, $kind, $actor, $token, $startedAt, $recipient, $transport, $result, $error) {
            $attempt = TaxonomyCategoryRequestDeliveryAttempt::create([
                'request_id' => $request->getKey(),
                'attempt_number' => (int) TaxonomyCategoryRequestDeliveryAttempt::query()->where('request_id', $request->getKey())->max('attempt_number') + 1,
                'kind' => $kind,
                'actor_user_id' => $actor?->getKey(),
                'recipient_email' => $recipient,
                'transport' => $transport,
                'result' => $result,
                'error_code' => $error,
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            $changes = [
                'delivery_status' => $result,
                'delivery_error' => $error,
                'recipient_email' => $recipient,
                'delivery_transport' => $transport,
                'delivery_claim_token' => null,
                'delivery_claimed_at' => null,
            ];
            // `mail_sent_at` = cuando el transporte ACEPTÓ el mensaje. Una simulación no envió nada.
            if ($result === TaxonomyCategoryRequest::DELIVERY_SENT) {
                $changes['mail_sent_at'] = now();
            }

            $owned = TaxonomyCategoryRequest::query()
                ->whereKey($request->getKey())
                ->where('delivery_claim_token', $token)
                ->update($changes);

            if ($owned !== 1) {
                // Sólo pasa si la reserva venció mientras este proceso seguía vivo y otro intento la
                // tomó. El intento queda registrado igual; el estado lo decide el intento vigente.
                Log::warning('TASK-0010C: la reserva del aviso venció antes de cerrar el intento; se registra el intento sin pisar el estado vigente.', [
                    'taxonomy_category_request_id' => $request->id,
                    'attempt_number' => $attempt->attempt_number,
                ]);
            }

            return $attempt;
        });
    }

    /** Enlace a la ficha de la empresa en el panel admin, o null si no se puede generar de forma segura. */
    private function adminUrl(int $empresaId): ?string
    {
        try {
            return EmpresaResource::getUrl('edit', ['record' => $empresaId], panel: 'admin');
        } catch (Throwable $e) {
            Log::info('TASK-0010A: no se pudo generar el enlace al panel para la solicitud de categoría.', [
                'empresa_id' => $empresaId,
                'exception' => get_class($e),
            ]);

            return null;
        }
    }
}
