<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 0; }
        h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
        .subtitle { color: #6b7280; margin-top: 2px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; }
        .kpi-row { width: 100%; }
        .kpi { display: inline-block; width: 19%; padding: 8px; margin-right: 1%; background: #f3f4f6; border-radius: 4px; }
        .kpi .value { font-size: 16px; font-weight: bold; }
        .kpi .label { font-size: 9px; color: #6b7280; }
        .two-col { width: 100%; }
        .two-col .col { float: left; width: 48%; margin-right: 2%; }
    </style>
</head>
<body>
    <h1>Tablero de Métricas Gerenciales</h1>
    <p class="subtitle">Cámara Petrolera de Venezuela — generado el {{ now()->format('d/m/Y H:i') }}</p>

    <div class="kpi-row">
        <div class="kpi"><div class="value">{{ $resumen['total_empresas'] }}</div><div class="label">Empresas Activas</div></div>
        <div class="kpi"><div class="value">{{ $resumen['completitud_promedio'] }}%</div><div class="label">Completitud Promedio</div></div>
        <div class="kpi"><div class="value">{{ $resumen['frescura_dato'] }}%</div><div class="label">Frescura del Dato</div></div>
        <div class="kpi"><div class="value">{{ $resumen['sedes'] }}</div><div class="label">Sedes c/ Infraestructura</div></div>
        <div class="kpi"><div class="value">{{ $resumen['proyectos'] }}</div><div class="label">Historial de Proyectos</div></div>
    </div>

    <h2>Segmentación por Calidad de Perfil</h2>
    <table>
        <tr>@foreach ($calidadPerfil as $label => $count)<th>{{ $label }}</th>@endforeach</tr>
        <tr>@foreach ($calidadPerfil as $count)<td>{{ $count }}</td>@endforeach</tr>
    </table>

    <h2>Tasa de "No Aplica" por Módulo</h2>
    <table>
        <tr><th>Módulo</th><th>NA Completo</th><th>NA Parcial</th></tr>
        @foreach ($noAplica['labels'] as $i => $label)
            <tr><td>{{ $label }}</td><td>{{ $noAplica['completo'][$i] }}</td><td>{{ $noAplica['parcial'][$i] }}</td></tr>
        @endforeach
    </table>

    <div class="two-col">
        <div class="col">
            <h2>Top Sectores de Afiliados</h2>
            <table>
                <tr><th>Sector</th><th>Empresas</th></tr>
                @foreach ($topSectores['labels'] as $i => $label)
                    <tr><td>{{ $label }}</td><td>{{ $topSectores['values'][$i] }}</td></tr>
                @endforeach
            </table>
        </div>
        <div class="col">
            <h2>Cobertura de Servicios Técnicos</h2>
            <table>
                <tr><th>Servicio</th><th>Empresas</th></tr>
                @foreach ($coberturaServicios['labels'] as $i => $label)
                    <tr><td>{{ $label }}</td><td>{{ $coberturaServicios['values'][$i] }}</td></tr>
                @endforeach
            </table>
        </div>
    </div>

    <h2>Índice de Diversificación Sectorial</h2>
    <table>
        <tr>@foreach ($diversificacion as $label => $count)<th>{{ $label }}</th>@endforeach</tr>
        <tr>@foreach ($diversificacion as $count)<td>{{ $count }}</td>@endforeach</tr>
    </table>

    <div class="two-col">
        <div class="col">
            <h2>Generación de Empleo Directo</h2>
            <table>
                <tr>@foreach ($empleo['labels'] as $label)<th>{{ $label }}</th>@endforeach</tr>
                <tr>@foreach ($empleo['values'] as $value)<td>{{ $value }}</td>@endforeach</tr>
            </table>
        </div>
        <div class="col">
            <h2>Estratificación Financiera</h2>
            <table>
                <tr>@foreach ($facturacion['labels'] as $label)<th>{{ $label }}</th>@endforeach</tr>
                <tr>@foreach ($facturacion['values'] as $value)<td>{{ $value }}</td>@endforeach</tr>
            </table>
        </div>
    </div>

    <h2>Composición de Capital</h2>
    <table>
        <tr><th>Origen: Nacional</th><th>Origen: Internacional</th><th>Propiedad: Privado</th><th>Propiedad: Público</th></tr>
        <tr>
            <td>{{ $capital['origen']['Nacional'] }}</td>
            <td>{{ $capital['origen']['Internacional'] }}</td>
            <td>{{ $capital['propiedad']['Privado'] }}</td>
            <td>{{ $capital['propiedad']['Público'] }}</td>
        </tr>
    </table>

    <h2>Cobertura de Recursos Industriales</h2>
    <table>
        <tr>@foreach ($coberturaRecursos['labels'] as $label)<th>{{ $label }}</th>@endforeach</tr>
        <tr>@foreach ($coberturaRecursos['values'] as $value)<td>{{ $value }}%</td>@endforeach</tr>
    </table>

    <h2>Penetración de Certificaciones y Estándares</h2>
    <table>
        <tr><th>Estándar</th><th>% de Adopción</th></tr>
        @foreach ($certificaciones as $label => $percentage)
            <tr><td>{{ $label }}</td><td>{{ $percentage }}%</td></tr>
        @endforeach
    </table>

    <h2>Alcance Internacional</h2>
    <table>
        <tr>@foreach ($alcanceInternacional as $label => $count)<th>{{ $label }}</th>@endforeach</tr>
        <tr>@foreach ($alcanceInternacional as $count)<td>{{ $count }}</td>@endforeach</tr>
    </table>

    <h2>Crecimiento de Afiliación (últimos 12 meses)</h2>
    <table>
        <tr>@foreach ($crecimiento['labels'] as $label)<th>{{ $label }}</th>@endforeach</tr>
        <tr>@foreach ($crecimiento['values'] as $value)<td>{{ $value }}</td>@endforeach</tr>
    </table>

    <div class="two-col">
        <div class="col">
            <h2>Distribución Geográfica</h2>
            <table>
                <tr><th>Estado</th><th>Empresas</th></tr>
                @foreach ($geografia['labels'] as $i => $label)
                    <tr><td>{{ $label }}</td><td>{{ $geografia['values'][$i] }}</td></tr>
                @endforeach
            </table>
        </div>
        <div class="col">
            <h2>Distribución por Cámara / Capítulo</h2>
            <table>
                <tr><th>Cámara</th><th>Empresas</th></tr>
                @foreach ($camaras['labels'] as $i => $label)
                    <tr><td>{{ $label }}</td><td>{{ $camaras['values'][$i] }}</td></tr>
                @endforeach
            </table>
        </div>
    </div>
</body>
</html>
