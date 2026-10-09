<?php

namespace App\Services;

use App\Filament\Resources\EmpresaResource;
use App\Mail\TaxonomyCategoryRequestMail;
use App\Models\Empresa;
use App\Models\EmpresaTaxonomyCategory;
use App\Models\TaxonomyCategoryRequest;
use App\Models\TaxonomySelectionSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
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
    public const ERROR_SIN_DESTINATARIO = 'recipient_not_configured';
    public const ERROR_ENVIO = 'mail_send_failed';

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

        $validated = Validator::make($data, self::rules($data['justificacion'] ?? null))->validate();

        $request = TaxonomyCategoryRequest::create([
            'empresa_id' => $empresa->getKey(),
            'user_id' => $user->getKey(),
            'necesidad' => trim($validated['necesidad']),
            'justificacion' => $validated['justificacion'],
            'detalle' => trim($validated['detalle']),
            'terminos_probados' => filled($validated['terminos_probados'] ?? null) ? trim($validated['terminos_probados']) : null,
            'context_snapshot' => $this->contextSnapshot($empresa, $user),
            'request_status' => TaxonomyCategoryRequest::STATUS_PENDING_REVIEW,
            'delivery_status' => TaxonomyCategoryRequest::DELIVERY_PENDING,
            'submitted_at' => now(),
        ]);

        $this->deliver($request, $empresa);

        return $request->refresh();
    }

    /**
     * Reglas de validación (compartidas con el formulario de Filament).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?string $justificacion = null): array
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

    private function deliver(TaxonomyCategoryRequest $request, Empresa $empresa): void
    {
        $recipient = TaxonomySelectionSettings::current()->category_request_recipient_email;

        if (blank($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            Log::warning('TASK-0010A: solicitud de revisión de categoría registrada sin correo - destinatario no configurado o inválido en taxonomy_selection_settings.category_request_recipient_email.', [
                'taxonomy_category_request_id' => $request->id,
                'empresa_id' => $request->empresa_id,
            ]);

            $request->update([
                'delivery_status' => TaxonomyCategoryRequest::DELIVERY_FAILED,
                'delivery_error' => self::ERROR_SIN_DESTINATARIO,
                'recipient_email' => null,
            ]);

            return;
        }

        $request->update(['recipient_email' => $recipient]);

        try {
            Mail::to($recipient)->send(new TaxonomyCategoryRequestMail($request, $this->adminUrl($empresa)));
        } catch (Throwable $e) {
            Log::error('TASK-0010A: falló el envío del correo de solicitud de revisión de categoría (la solicitud SÍ quedó registrada).', [
                'taxonomy_category_request_id' => $request->id,
                'empresa_id' => $request->empresa_id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            $request->update([
                'delivery_status' => TaxonomyCategoryRequest::DELIVERY_FAILED,
                'delivery_error' => self::ERROR_ENVIO,
            ]);

            return;
        }

        $request->update([
            'delivery_status' => TaxonomyCategoryRequest::DELIVERY_SENT,
            'delivery_error' => null,
            'mail_sent_at' => now(),
        ]);
    }

    /** Enlace a la ficha de la empresa en el panel admin, o null si no se puede generar de forma segura. */
    private function adminUrl(Empresa $empresa): ?string
    {
        try {
            return EmpresaResource::getUrl('edit', ['record' => $empresa->getKey()], panel: 'admin');
        } catch (Throwable $e) {
            Log::info('TASK-0010A: no se pudo generar el enlace al panel para la solicitud de categoría.', [
                'empresa_id' => $empresa->getKey(),
                'exception' => get_class($e),
            ]);

            return null;
        }
    }
}
