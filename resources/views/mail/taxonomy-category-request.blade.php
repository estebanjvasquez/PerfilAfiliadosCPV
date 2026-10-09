<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Solicitud de revisión de categoría CPV</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; line-height: 1.5;">
    <h2 style="margin-bottom: 4px;">Solicitud de revisión de categoría CPV #{{ $solicitud->id }}</h2>
    <p style="margin-top: 0; color: #4b5563;">
        Fecha: {{ $solicitud->submitted_at?->format('d/m/Y H:i') }}
    </p>

    <p style="padding: 10px; background: #fef3c7; border: 1px solid #f59e0b;">
        <strong>{{ $nota }}</strong>
    </p>

    <h3>Empresa</h3>
    <ul>
        <li>Nombre: {{ $empresa['name'] ?? '—' }}</li>
        <li>RIF: {{ $empresa['rif'] ?? '—' }}</li>
        <li>Teléfono: {{ filled($empresa['phone'] ?? null) ? $empresa['phone'] : 'No registrado' }}</li>
        <li>Sitio web: {{ filled($empresa['website'] ?? null) ? $empresa['website'] : 'No registrado' }}</li>
    </ul>
    @if ($adminUrl)
        <p>Ficha de la empresa en el panel: <a href="{{ $adminUrl }}">{{ $adminUrl }}</a></p>
    @endif

    <h3>Usuario solicitante</h3>
    <ul>
        <li>Nombre: {{ $usuario['name'] ?? '—' }}</li>
        <li>Correo: {{ $usuario['email'] ?? '—' }}</li>
        <li>Teléfono: {{ filled($usuario['phone'] ?? null) ? $usuario['phone'] : 'No registrado' }}</li>
    </ul>

    <h3>Solicitud</h3>
    <p><strong>Justificación:</strong> {{ $justificacionLabel }}</p>
    <p><strong>¿Qué producto, servicio o capacidad necesita representar?</strong></p>
    <p style="white-space: pre-line;">{{ $solicitud->necesidad }}</p>
    <p><strong>Detalle:</strong></p>
    <p style="white-space: pre-line;">{{ $solicitud->detalle }}</p>
    <p><strong>Palabras probadas al buscar:</strong></p>
    <p style="white-space: pre-line;">{{ filled($solicitud->terminos_probados) ? $solicitud->terminos_probados : 'No indicadas' }}</p>

    <h3>Categorías CPV vinculadas actualmente</h3>
    @if (count($categorias) === 0)
        <p>La empresa no tiene categorías CPV vinculadas.</p>
    @else
        <ul>
            @foreach ($categorias as $categoria)
                <li>{{ $categoria['breadcrumb'] ?? '—' }} ({{ ! empty($categoria['es_principal']) ? 'Principal' : 'Secundaria' }})</li>
            @endforeach
        </ul>
    @endif

    <p style="color: #6b7280; font-size: 12px;">
        Mensaje automático del Perfil de Afiliados CPV. La Cámara puede contactar al usuario solicitante para aclarar la necesidad.
    </p>
</body>
</html>
