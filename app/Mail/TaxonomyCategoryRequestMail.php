<?php

namespace App\Mail;

use App\Models\TaxonomyCategoryRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * TASK-0010A: notificación a la Cámara de una solicitud de revisión de categoría CPV.
 *
 * Deliberadamente NO implementa ShouldQueue: TaxonomyCategoryRequestService envía sincrónicamente
 * para poder registrar en la propia solicitud si la entrega funcionó (`delivery_status`) y avisar
 * al usuario con un mensaje honesto. Todo el contenido proviene del snapshot server-side guardado
 * en la solicitud y se escapa en la vista (`{{ }}`), nunca `{!! !!}`.
 */
class TaxonomyCategoryRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public const NOTA_NO_CREACION = 'Esta solicitud no implica creación automática. Debe verificarse primero si una categoría existente cubre la necesidad.';

    public function __construct(
        public TaxonomyCategoryRequest $categoryRequest,
        public ?string $adminUrl = null,
    ) {
    }

    public static function subjectFor(TaxonomyCategoryRequest $request): string
    {
        $empresa = (string) ($request->context_snapshot['empresa']['name'] ?? ('Empresa #'.$request->empresa_id));
        // Sin saltos de línea en el asunto (encabezado de correo).
        $empresa = trim(preg_replace('/[\r\n\t]+/', ' ', $empresa));

        return "Solicitud de revisión de categoría CPV — {$empresa} — #{$request->id}";
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::subjectFor($this->categoryRequest));
    }

    public function content(): Content
    {
        $snapshot = $this->categoryRequest->context_snapshot ?? [];

        return new Content(
            view: 'mail.taxonomy-category-request',
            with: [
                'solicitud' => $this->categoryRequest,
                'empresa' => $snapshot['empresa'] ?? [],
                'usuario' => $snapshot['usuario'] ?? [],
                'categorias' => $snapshot['categorias_vinculadas'] ?? [],
                'justificacionLabel' => $this->categoryRequest->justificacionLabel(),
                'nota' => self::NOTA_NO_CREACION,
                'adminUrl' => $this->adminUrl,
            ],
        );
    }
}
