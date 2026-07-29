<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 130px 40px 60px 40px; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1f2937; }

        #header { position: fixed; top: -130px; left: 0; right: 0; height: 110px; }
        #header table { width: 100%; border: none; }
        #header td { border: none; padding: 0; vertical-align: middle; }
        #header .logo { width: 70px; }
        #header .logo img { height: 46px; }
        #header .title { text-align: right; }
        #header h1 { font-size: 16px; margin: 0; color: #0284c7; }
        #header .doc-subtitle { font-size: 9px; color: #6b7280; margin-top: 2px; }
        #header hr { border: none; border-bottom: 2px solid #0284c7; margin-top: 8px; }

        h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; color: #111827; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.data th, table.data td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        table.data th { background: #f3f4f6; }

        .kpi-row table { width: 100%; border: none; }
        .kpi-row td { border: none; padding: 4px; width: 20%; }
        .kpi { background: #f3f4f6; border-radius: 4px; padding: 8px; }
        .kpi .value { font-size: 16px; font-weight: bold; }
        .kpi .label { font-size: 9px; color: #6b7280; }

        .layout table { width: 100%; border: none; }
        .layout td { border: none; vertical-align: top; padding: 0; }
        .layout td.chart-col { width: 46%; padding-right: 4%; }

        .section { page-break-inside: avoid; }
        .chart { text-align: center; }
        .chart svg { width: 100%; height: auto; display: block; }

        .glossary-item { page-break-inside: avoid; margin-bottom: 9px; }
        .glossary-title { font-size: 11px; font-weight: bold; color: #111827; }
        .glossary-desc { font-size: 10px; color: #374151; margin-top: 1px; }

        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <div id="header">
        <table>
            <tr>
                <td class="logo"><img src="{{ public_path('images/Campet-Logo.png') }}"></td>
                <td class="title">
                    <h1>Informe gerencial perfil de afiliados</h1>
                    <div class="doc-subtitle">Cámara Petrolera de Venezuela — generado el {{ now()->format('d/m/Y H:i') }}</div>
                </td>
            </tr>
        </table>
        <hr>
    </div>

    <div class="kpi-row">
        <table>
            <tr>
                <td>
                    <div class="kpi"><div class="value">{{ $resumen['total_empresas'] }}</div><div class="label">Empresas activas</div></div>
                </td>
                <td>
                    <div class="kpi"><div class="value">{{ $resumen['completitud_promedio'] }}%</div><div class="label">Completado promedio</div></div>
                </td>
                <td>
                    <div class="kpi"><div class="value">{{ $resumen['frescura_dato'] }}%</div><div class="label">Frescura del dato</div></div>
                </td>
                <td>
                    <div class="kpi"><div class="value">{{ $resumen['sedes'] }}</div><div class="label">Sedes c/ infraestructura</div></div>
                </td>
                <td>
                    <div class="kpi"><div class="value">{{ $resumen['proyectos'] }}</div><div class="label">Historial de proyectos</div></div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Segmentación por calidad de perfil</h2>
        <div class="layout"><table><tr>
            <td class="chart-col"><div class="chart">{!! \App\Filament\Support\PdfCharts::donut(array_keys($calidadPerfil), array_values($calidadPerfil), ['#ef4444', '#f59e0b', '#22c55e']) !!}</div></td>
            <td>
                <table class="data">
                    <tr>@foreach ($calidadPerfil as $label => $count)<th>{{ $label }}</th>@endforeach</tr>
                    <tr>@foreach ($calidadPerfil as $count)<td>{{ $count }}</td>@endforeach</tr>
                </table>
            </td>
        </tr></table></div>
    </div>

    <div class="section">
        <h2>Tasa de "No Aplica" por módulo</h2>
        <div class="chart">{!! \App\Filament\Support\PdfCharts::groupedBar($noAplica['labels'], $noAplica['completo'], $noAplica['parcial'], 'Módulo completo', 'Sub-tipo parcial', '#ef4444', '#f59e0b') !!}</div>
        <table class="data">
            <tr><th>Módulo</th><th>NA completo</th><th>NA parcial</th></tr>
            @foreach ($noAplica['labels'] as $i => $label)
                <tr><td>{{ $label }}</td><td>{{ $noAplica['completo'][$i] }}</td><td>{{ $noAplica['parcial'][$i] }}</td></tr>
            @endforeach
        </table>
    </div>

    <div class="section">
        <h2>Top sectores de afiliados</h2>
        <div class="chart">{!! \App\Filament\Support\PdfCharts::horizontalBar($topSectores['labels'], $topSectores['values'], '#0284c7') !!}</div>
        <table class="data">
            <tr><th>Sector</th><th>Empresas</th></tr>
            @foreach ($topSectores['labels'] as $i => $label)
                <tr><td>{{ $label }}</td><td>{{ $topSectores['values'][$i] }}</td></tr>
            @endforeach
        </table>
    </div>

    <div class="section">
        <h2>Cobertura de servicios técnicos</h2>
        <div class="chart">{!! \App\Filament\Support\PdfCharts::horizontalBar($coberturaServicios['labels'], $coberturaServicios['values'], '#0ea5e9') !!}</div>
        <table class="data">
            <tr><th>Servicio</th><th>Empresas</th></tr>
            @foreach ($coberturaServicios['labels'] as $i => $label)
                <tr><td>{{ $label }}</td><td>{{ $coberturaServicios['values'][$i] }}</td></tr>
            @endforeach
        </table>
    </div>

    <div class="section">
        <h2>Índice de diversificación sectorial</h2>
        <div class="layout"><table><tr>
            <td class="chart-col"><div class="chart">{!! \App\Filament\Support\PdfCharts::donut(array_keys($diversificacion), array_values($diversificacion), ['#0284c7', '#22c55e']) !!}</div></td>
            <td>
                <table class="data">
                    <tr>@foreach ($diversificacion as $label => $count)<th>{{ $label }}</th>@endforeach</tr>
                    <tr>@foreach ($diversificacion as $count)<td>{{ $count }}</td>@endforeach</tr>
                </table>
            </td>
        </tr></table></div>
    </div>

    <div class="section">
        <h2>Generación de empleo directo</h2>
        <div class="layout"><table><tr>
            <td class="chart-col"><div class="chart">{!! \App\Filament\Support\PdfCharts::bar($empleo['labels'], $empleo['values'], '#6366f1') !!}</div></td>
            <td>
                <table class="data">
                    <tr>@foreach ($empleo['labels'] as $label)<th>{{ $label }}</th>@endforeach</tr>
                    <tr>@foreach ($empleo['values'] as $value)<td>{{ $value }}</td>@endforeach</tr>
                </table>
            </td>
        </tr></table></div>
    </div>

    <div class="section">
        <h2>Estratificación financiera</h2>
        <div class="layout"><table><tr>
            <td class="chart-col"><div class="chart">{!! \App\Filament\Support\PdfCharts::bar($facturacion['labels'], $facturacion['values'], '#8b5cf6') !!}</div></td>
            <td>
                <table class="data">
                    <tr>@foreach ($facturacion['labels'] as $label)<th>{{ $label }}</th>@endforeach</tr>
                    <tr>@foreach ($facturacion['values'] as $value)<td>{{ $value }}</td>@endforeach</tr>
                </table>
            </td>
        </tr></table></div>
    </div>

    <div class="section">
        @php
            $capitalLabels = [...array_keys($capital['origen']), ...array_keys($capital['propiedad'])];
            $capitalValues = [...array_values($capital['origen']), ...array_values($capital['propiedad'])];
        @endphp
        <h2>Composición de capital</h2>
        <div class="layout"><table><tr>
            <td class="chart-col"><div class="chart">{!! \App\Filament\Support\PdfCharts::bar($capitalLabels, $capitalValues, ['#0284c7', '#0284c7', '#22c55e', '#22c55e']) !!}</div></td>
            <td>
                <table class="data">
                    <tr><th>Origen: Nacional</th><th>Origen: Internacional</th><th>Propiedad: Privado</th><th>Propiedad: Público</th></tr>
                    <tr>
                        <td>{{ $capital['origen']['Nacional'] }}</td>
                        <td>{{ $capital['origen']['Internacional'] }}</td>
                        <td>{{ $capital['propiedad']['Privado'] }}</td>
                        <td>{{ $capital['propiedad']['Público'] }}</td>
                    </tr>
                </table>
            </td>
        </tr></table></div>
    </div>

    <div class="section">
        <h2>Cobertura de recursos industriales</h2>
        <div class="layout"><table><tr>
            <td class="chart-col"><div class="chart">{!! \App\Filament\Support\PdfCharts::radar($coberturaRecursos['labels'], $coberturaRecursos['values'], '#0284c7') !!}</div></td>
            <td>
                <table class="data">
                    <tr>@foreach ($coberturaRecursos['labels'] as $label)<th>{{ $label }}</th>@endforeach</tr>
                    <tr>@foreach ($coberturaRecursos['values'] as $value)<td>{{ $value }}%</td>@endforeach</tr>
                </table>
            </td>
        </tr></table></div>
    </div>

    <div class="section">
        <h2>Penetración de certificaciones y estándares</h2>
        <div class="layout"><table><tr>
            <td class="chart-col"><div class="chart">{!! \App\Filament\Support\PdfCharts::horizontalBar(array_keys($certificaciones), array_values($certificaciones), '#14b8a6', 500, 20) !!}</div></td>
            <td>
                <table class="data">
                    <tr><th>Estándar</th><th>% de adopción</th></tr>
                    @foreach ($certificaciones as $label => $percentage)
                        <tr><td>{{ $label }}</td><td>{{ $percentage }}%</td></tr>
                    @endforeach
                </table>
            </td>
        </tr></table></div>
    </div>

    <div class="section">
        <h2>Alcance internacional</h2>
        <div class="layout"><table><tr>
            <td class="chart-col"><div class="chart">{!! \App\Filament\Support\PdfCharts::donut(array_keys($alcanceInternacional), array_values($alcanceInternacional), ['#22c55e', '#94a3b8']) !!}</div></td>
            <td>
                <table class="data">
                    <tr>@foreach ($alcanceInternacional as $label => $count)<th>{{ $label }}</th>@endforeach</tr>
                    <tr>@foreach ($alcanceInternacional as $count)<td>{{ $count }}</td>@endforeach</tr>
                </table>
            </td>
        </tr></table></div>
    </div>

    <div class="section">
        <h2>Crecimiento de afiliación (últimos 12 meses)</h2>
        <div class="chart">{!! \App\Filament\Support\PdfCharts::line($crecimiento['labels'], $crecimiento['values'], '#0284c7') !!}</div>
        <table class="data">
            <tr>@foreach ($crecimiento['labels'] as $label)<th>{{ $label }}</th>@endforeach</tr>
            <tr>@foreach ($crecimiento['values'] as $value)<td>{{ $value }}</td>@endforeach</tr>
        </table>
    </div>

    <div class="section">
        <h2>Distribución geográfica</h2>
        <div class="chart">{!! \App\Filament\Support\PdfCharts::horizontalBar($geografia['labels'], $geografia['values'], '#f97316') !!}</div>
        <table class="data">
            <tr><th>Estado</th><th>Empresas</th></tr>
            @foreach ($geografia['labels'] as $i => $label)
                <tr><td>{{ $label }}</td><td>{{ $geografia['values'][$i] }}</td></tr>
            @endforeach
        </table>
    </div>

    <div class="section">
        <h2>Distribución por cámara / capítulo</h2>
        <div class="chart">{!! \App\Filament\Support\PdfCharts::horizontalBar($camaras['labels'], $camaras['values'], '#d946ef', 500, 20, 42) !!}</div>
        <table class="data">
            <tr><th>Cámara</th><th>Empresas</th></tr>
            @foreach ($camaras['labels'] as $i => $label)
                <tr><td>{{ $label }}</td><td>{{ $camaras['values'][$i] }}</td></tr>
            @endforeach
        </table>
    </div>

    <div class="page-break">
        <h2>Glosario de métricas</h2>
        <p style="color:#6b7280; margin-bottom: 10px;">Guía de referencia rápida para interpretar cada indicador de este informe.</p>

        <div class="glossary-item">
            <div class="glossary-title">Empresas activas</div>
            <div class="glossary-desc">Cantidad total de empresas afiliadas con estatus "Activa" en el sistema. Es la base sobre la que se calculan todas las demás métricas de este informe (las empresas inactivas no se cuentan).</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">% Completado promedio</div>
            <div class="glossary-desc">Promedio del porcentaje de completitud del perfil entre todas las empresas activas. Mide qué tan llenos están, en conjunto, los perfiles registrados en la plataforma.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Frescura del dato</div>
            <div class="glossary-desc">Porcentaje de empresas que actualizaron su perfil en los últimos 12 meses. Un valor bajo indica que buena parte de los perfiles puede tener información desactualizada.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Sedes con infraestructura</div>
            <div class="glossary-desc">Cantidad de empresas que reportaron al menos una instalación física (oficina, planta, taller, etc.) en el módulo de Recursos en Venezuela.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Historial de proyectos</div>
            <div class="glossary-desc">Cantidad total de experiencias/proyectos registrados por el conjunto de empresas activas en el módulo de Experiencia Relevante.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Segmentación por calidad de perfil</div>
            <div class="glossary-desc">Agrupa a las empresas en 3 rangos según su % de completitud (menos de 50%, entre 50% y 89%, y 90% o más), para identificar cuántas necesitan completar más su perfil.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Tasa de "No Aplica" por módulo</div>
            <div class="glossary-desc">Cantidad de empresas que marcaron un módulo como "No Aplica" en vez de cargar datos, separando cuando se marcó el módulo completo de cuando solo se marcó alguno de sus tipos o secciones internas.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Top sectores de afiliados</div>
            <div class="glossary-desc">Los sectores económicos con más empresas vinculadas, ya sea como sector principal o secundario.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Cobertura de servicios técnicos</div>
            <div class="glossary-desc">Los servicios técnicos más ofrecidos por las empresas afiliadas, según los servicios que cada una vinculó en su perfil.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Índice de diversificación sectorial</div>
            <div class="glossary-desc">Cuántas empresas operan en un solo sector económico frente a las que operan en dos sectores (el máximo permitido por empresa).</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Generación de empleo directo</div>
            <div class="glossary-desc">Distribución de las empresas según el rango de cantidad de empleados que declararon en su perfil.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Estratificación financiera</div>
            <div class="glossary-desc">Distribución de las empresas según su rango de facturación anual promedio declarada.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Composición de capital</div>
            <div class="glossary-desc">Cuántas empresas son de capital nacional frente a internacional, y cuántas son de propiedad privada frente a pública.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Cobertura de recursos industriales</div>
            <div class="glossary-desc">Porcentaje de empresas que reportaron datos en cada tipo de recurso (Recursos Humanos, Maquinaria y Equipos, Instalaciones, Inventario), dentro del módulo Recursos en Venezuela.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Penetración de certificaciones y estándares</div>
            <div class="glossary-desc">Porcentaje de empresas que cuentan con cada certificación o estándar (ISO, PMI, DUN, etc.) registrado en el módulo Sistemas de Gestión.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Alcance internacional</div>
            <div class="glossary-desc">Cantidad de empresas con presencia formal (oficinas) o experiencia de proyectos fuera de Venezuela, frente a las que no reportan ninguna de las dos.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Crecimiento de afiliación</div>
            <div class="glossary-desc">Cantidad de nuevas empresas afiliadas por mes durante los últimos 12 meses. Permite ver si el padrón de afiliados está creciendo, estancado o en descenso.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Distribución geográfica</div>
            <div class="glossary-desc">Cantidad de empresas afiliadas por estado de Venezuela donde tienen su sede registrada.</div>
        </div>
        <div class="glossary-item">
            <div class="glossary-title">Distribución por cámara / capítulo</div>
            <div class="glossary-desc">Cantidad de empresas afiliadas a cada cámara o capítulo regional/sectorial de la Cámara Petrolera de Venezuela.</div>
        </div>
    </div>
</body>
</html>
