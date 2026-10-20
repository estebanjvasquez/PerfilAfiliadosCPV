{{--
    Perfil completo de UNA empresa (CPV-COMPLETE-COMPANY-PDF, Issue #2 `6270558322`). Es el reporte de siempre, reparado:
    mismas secciones, encabezado amarillo con los logos y títulos grises. Los datos llegan ya reunidos, con valores vacíos
    seguros, desde App\Support\CompanyDossier::data(): este archivo no consulta la base. Lo genera solo
    EmpresaExportController::pdf(), DESPUÉS de autorizar.

    Cada sección distingue tres casos:
    - hay datos: se muestran;
    - la empresa la marcó «No aplica»: se muestra `$naLabel`;
    - no hay nada registrado: «No hay información registrada».
    Nunca se rellena con supuestos.
--}}
@php
    $want = fn (string $section) => in_array($section, $sections, true);
    $vacio = 'No hay información registrada.';
    $has = fn ($value) => filled($value) && trim((string) $value) !== '';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Perfil de {{ $record->name }}</title>
    <style>
        * { box-sizing: border-box; }

        @page { margin: 150px 50px 60px 50px; }

        body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #111; }

        /* PDF-01 (`6272035810`): la franja tiene ALTO FIJO (112px) y entra siempre en el margen superior de 150px, en todas las
           páginas. Antes crecía con el contenido: un nombre de 100 caracteres pasaba a dos líneas y la franja casi tocaba el
           cuerpo. Ahora el nombre usa a lo sumo dos líneas de 12px dentro de la franja, y «RIF …» no se corta. */
        .page-header { position: fixed; left: 0; right: 0; top: -140px; height: 112px; }

        .page-header .amarillo { height: 112px; overflow: hidden; }

        .row:after { content: ""; display: table; clear: both; }

        .column1 { float: left; width: 48%; padding: 4px 5px; height: 56px; }

        .column2 { float: right; width: 48%; padding: 4px 5px; height: 56px; text-align: right; }

        .amarillo { background-color: #FBB805; }

        .gris-oscuro { background-color: #D3D3D3; }

        .page-header h3 { margin: 0; padding: 3px 6px 0 6px; text-align: center; font-size: 13px; }

        .page-header .empresa { margin: 0; padding: 0 6px 3px 6px; text-align: center; font-size: 10px; line-height: 12px; }

        .page-header .rif { white-space: nowrap; }

        h2.titulo2 { font-size: 15px; text-align: left; color: #000; padding: 5px; margin: 14px 0 0 0; }

        h4.subtitulo { font-size: 12.5px; padding: 4px 5px; margin: 6px 0 0 0; }

        table { border: 2px solid #D3D3D3; border-collapse: collapse; margin: 0; padding: 0; width: 100%; table-layout: fixed; }

        th, td { padding: 6px 10px; text-align: left; vertical-align: top; border: none; word-wrap: break-word; }

        th { font-size: 11.5px; border-bottom: 1px solid #CCC; border-top: 1px solid #808080; }

        tr.noBorder td { border-bottom: 1px solid #E2E2E2; }

        td.etiqueta { width: 32%; color: #333; }

        .vacio { margin: 0; padding: 8px 10px; font-weight: bold; }

        .nota { font-size: 10.5px; color: #444; margin: 4px 0; }

        .contenido { border: 1px solid #CCC; padding: 6px 10px; margin-bottom: 4px; }

        .contenido p { margin: 2px 0; }

        .experiencia-card { width: 100%; margin: 6px 0 0 0; border: 1px solid #ccc; padding: 6px; page-break-inside: avoid; }

        .exp-grupo { margin-bottom: 3px; }

        .card { border: 1px solid #bbbbbb; padding: 6px; }

        .card p.card-titulo { margin: 0 0 4px 0; font-weight: bold; }

        tr { page-break-inside: avoid; }

        /* Un título con su tabla: no queda un encabezado solo al pie de una página. Si el bloque no cabe en una página,
           dompdf lo corta igual, y el <thead> de la tabla se repite en la página siguiente. */
        .bloque { page-break-inside: avoid; }

        h2.titulo2, h4.subtitulo { page-break-after: avoid; }
    </style>
</head>
<body>

    <div class="page-header">
        <div class="row amarillo">
            <div class="column1">
                <img src="{{ public_path('images/Campet-Logo.png') }}" style="height: 52px;">
            </div>
            <div class="column2">
                <img src="{{ public_path('images/Perfil-Logo.png') }}" style="height: 48px;">
            </div>
            <div style="width: 100%; clear: both;">
                <h3>{{ $complete ? 'PERFIL COMPLETO DE LA EMPRESA' : 'PERFIL DE LA EMPRESA — SECCIONES SELECCIONADAS' }}</h3>
                <p class="empresa">{{ $record->name }} · <span class="rif">RIF {{ $record->rif }}</span></p>
            </div>
        </div>
    </div>

    {{-- CONTENIDO ................................................................................ --}}
    <div class="contenido">
        <p><b>Generado:</b> {{ $generatedAt->format('d/m/Y H:i') }} (hora de Venezuela)</p>
        @if ($complete)
            <p><b>Contenido:</b> todas las secciones del perfil ({{ implode(', ', \App\Support\CompanyDossier::SECTIONS) }}).</p>
        @else
            <p><b>Secciones incluidas:</b> {{ implode(', ', array_map(fn ($s) => \App\Support\CompanyDossier::SECTIONS[$s], $sections)) }}.</p>
            <p><b>Secciones omitidas por elección del usuario:</b> {{ implode(', ', $omitted) }}. Este documento no es el perfil completo.</p>
        @endif
        <p class="nota">Información declarada por la empresa en el sistema Perfil de Afiliados de la Cámara Petrolera de Venezuela. No es una calificación crediticia, una certificación ni un aval de la Cámara.</p>
    </div>

    {{-- DATOS GENERALES ........................................................................... --}}
    @if ($want('generales'))
        <h2 class="titulo2 gris-oscuro">Datos generales</h2>
        <table>
            <tr class="noBorder"><td class="etiqueta">Nombre de la empresa:</td><td>{{ $g->nombre ?? $record->name }}</td></tr>
            <tr class="noBorder"><td class="etiqueta">Nro. de RIF:</td><td>{{ $g->rif ?? $record->rif }}</td></tr>
            <tr class="noBorder"><td class="etiqueta">Dirección:</td><td>{{ $has($g->direccion) ? $g->direccion : $vacio }}</td></tr>
            <tr class="noBorder"><td class="etiqueta">Ciudad / País:</td><td>{{ $has($g->ciudad) ? $g->ciudad : $vacio }}</td></tr>
            <tr class="noBorder"><td class="etiqueta">Página web:</td><td>{{ $has($g->website) ? $g->website : $vacio }}</td></tr>
            <tr class="noBorder"><td class="etiqueta">Teléfono:</td><td>{{ $has($g->telefono) ? $g->telefono : $vacio }}</td></tr>
            <tr class="noBorder">
                <td class="etiqueta">Redes sociales:</td>
                <td>
                    @forelse ($redes as $red => $valor)
                        {{ $red }}: {{ $valor }}<br>
                    @empty
                        {{ $vacio }}
                    @endforelse
                </td>
            </tr>
            <tr class="noBorder"><td class="etiqueta">Año de fundación:</td><td>{{ $has($g->fundacion) ? $g->fundacion : $vacio }}</td></tr>
            <tr class="noBorder"><td class="etiqueta">Capital de la empresa / Origen:</td><td>{{ $has($g->capital) || $has($g->origen) ? trim(($g->capital ?? '').' / '.($g->origen ?? ''), ' /') : $vacio }}</td></tr>
            <tr class="noBorder"><td class="etiqueta">Sector principal:</td><td>{{ $sectorPrincipal ?? $vacio }}</td></tr>
            <tr class="noBorder"><td class="etiqueta">Sector secundario:</td><td>{{ $sectorSecundario ?? 'No declarado' }}</td></tr>
        </table>

        <div class="bloque">
            <h4 class="subtitulo gris-oscuro">Operación en Venezuela</h4>
            <table>
                <tr class="noBorder"><td class="etiqueta">Facturación anual:</td><td>{{ $has($g->facturacion_anual) ? $g->facturacion_anual : $vacio }}</td></tr>
                <tr class="noBorder"><td class="etiqueta">Empleados (rango declarado):</td><td>{{ $empleadosRango ?? $vacio }}</td></tr>
                <tr class="noBorder"><td class="etiqueta">Empleados (total en Recursos humanos):</td><td>{{ $has($g->empleados) ? $g->empleados : $vacio }}</td></tr>
                <tr class="noBorder"><td class="etiqueta">Estatus actual:</td><td>{{ $has($g->estado_actual) ? $g->estado_actual : $vacio }}</td></tr>
            </table>
        </div>

        <div class="bloque">
            <h4 class="subtitulo gris-oscuro">Contactos</h4>
            <table>
                @forelse ($contactos as $contacto)
                    <tr class="noBorder">
                        <td class="etiqueta"><b>{{ $contacto->name }}</b>@if ($contacto->pivot?->is_principal)<br>(contacto principal)@endif</td>
                        <td>
                            @if ($has($contacto->position)) Cargo: {{ $contacto->position }}<br>@endif
                            @if ($has($contacto->phone)) Teléfono: {{ $contacto->phone }}<br>@endif
                            @if ($has($contacto->email)) Correo: {{ $contacto->email }}@endif
                        </td>
                    </tr>
                @empty
                    <tr><td><p class="vacio">{{ $vacio }}</p></td></tr>
                @endforelse
            </table>
        </div>
    @endif

    {{-- CLIENTES ................................................................................. --}}
    @if ($want('clientes'))
        <div class="bloque">
            <h2 class="titulo2 gris-oscuro">Clientes</h2>
            <table>
                @if ($clientes->isNotEmpty())
                    <thead><tr><th>Nombre</th><th>País</th></tr></thead>
                    @foreach ($clientes as $cliente)
                        <tr><td>{{ $cliente['nombre'] }}</td><td>{{ $cliente['pais'] }}</td></tr>
                    @endforeach
                @else
                    <tr><td><p class="vacio">{{ $vacio }}</p></td></tr>
                @endif
            </table>
        </div>
    @endif

    {{-- CÁMARAS .................................................................................. --}}
    @if ($want('camaras'))
        <div class="bloque">
            <h2 class="titulo2 gris-oscuro">Cámaras</h2>
            <table>
                @if (count($camaras) > 0)
                    <thead><tr><th>Nombre</th></tr></thead>
                    @foreach ($camaras as $camara)
                        <tr><td>{{ $camara->name }}</td></tr>
                    @endforeach
                @else
                    <tr><td><p class="vacio">{{ $vacio }}</p></td></tr>
                @endif
            </table>
        </div>
    @endif

    {{-- SECTORES Y SERVICIOS ..................................................................... --}}
    @if ($want('sectores'))
        <div class="bloque">
            <h2 class="titulo2 gris-oscuro">Sectores de actividad económica y servicios</h2>
            <table>
                @if (count($secSer) > 0)
                    <thead><tr><th style="width: 35%;">Sector</th><th>Servicios</th></tr></thead>
                    @php $anterior = null; @endphp
                    @foreach ($secSer as $fila)
                        <tr>
                            <td>{{ $fila->sector === $anterior ? '' : $fila->sector }}</td>
                            <td>{{ $fila->servicio }}</td>
                        </tr>
                        @php $anterior = $fila->sector; @endphp
                    @endforeach
                @else
                    <tr><td><p class="vacio">{{ $vacio }}</p></td></tr>
                @endif
            </table>
        </div>
    @endif

    {{-- CATEGORÍAS CPV ............................................................................ --}}
    @if ($want('categorias'))
        <div class="bloque">
            <h2 class="titulo2 gris-oscuro">Categorías CPV</h2>
            <table>
                @if ($categorias->isNotEmpty())
                    <thead><tr><th style="width: 60%;">Categoría (Grupo › Familia › Categoría)</th><th>Principal</th><th>Origen</th></tr></thead>
                    @foreach ($categorias as $categoria)
                        <tr>
                            <td>{{ $categoria['nombre'] }}</td>
                            <td>{{ $categoria['principal'] ? 'Sí' : 'No' }}</td>
                            <td>{{ $categoria['origen'] }}</td>
                        </tr>
                    @endforeach
                @else
                    <tr><td><p class="vacio">{{ $vacio }}</p></td></tr>
                @endif
            </table>
            <p class="nota">Solo categorías declaradas por la empresa o confirmadas. Las sugerencias sin confirmar no se incluyen.</p>
        </div>
    @endif

    {{-- ENFOQUE DE SOSTENIBILIDAD ................................................................. --}}
    @if ($want('sostenibilidad'))
        <div class="bloque">
            <h2 class="titulo2 gris-oscuro">Enfoque de sostenibilidad</h2>
            <table>
                @if (count($sust) > 0)
                    <thead><tr><th style="width: 40%;">Áreas</th><th>Sí: Activo</th><th>Sí: Inactivo</th><th>No</th></tr></thead>
                    @foreach ($sust as $fila)
                        <tr>
                            <td>{{ $fila->sust_title }}</td>
                            <td>{{ (string) $fila->sust_status === '2' ? 'Sí' : '' }}</td>
                            <td>{{ (string) $fila->sust_status === '1' ? 'Sí' : '' }}</td>
                            <td>{{ (string) $fila->sust_status === '0' ? 'No' : '' }}</td>
                        </tr>
                    @endforeach
                @else
                    <tr><td><p class="vacio">{{ $na['sostenibilidad'] ? $naLabel : $vacio }}</p></td></tr>
                @endif
            </table>
        </div>
    @endif

    {{-- RECURSOS EN VENEZUELA ..................................................................... --}}
    @if ($want('recursos'))
        @php
            $sinDatos = fn (string $sub) => ($na['recursos'] || $naRecursos[$sub]) ? $naLabel : $vacio;
            $personal = $rec->data_rec[0] ?? null;
            $maquinaria = $rec->data_maq[0] ?? null;
            $instalaciones = $rec->data_ins[0] ?? null;
            $inventario = $rec->data_inv[0] ?? null;
            $num = fn ($value) => (int) round((float) trim((string) $value));
            $tiposPersonal = ['Bachilleres', 'Tecnicos', 'Ingenieros', 'Administrativos', 'Gerentes', 'Directivos'];
            $tiposMaquinaria = ['Equip_med_lev', 'Equip_mar_flu', 'Mov_terr_cons', 'Equip_men_cons', 'Fab_metal_elec', 'Mont_elec_meca', 'Maq_herr_meca', 'Almac_trans', 'Serv_poz_inst'];
            $tiposInstalacion = ['Oficinas', 'Talleres', 'Manufactura', 'Almacenes', 'Laboratorios', 'Marinas', 'Otros'];
            $tiposInventario = ['Materia', 'Producto'];
            $nivel = fn (string $level) => $personal ? collect($tiposPersonal)->sum(fn ($tipo) => $num($personal->{$tipo.'_'.$level})) : 0;
        @endphp
        <div class="bloque">
            <h2 class="titulo2 gris-oscuro">Recursos en Venezuela</h2>
            <h4 class="subtitulo gris-oscuro">Recursos humanos</h4>
            <table>
                @if ($personal)
                    <thead><tr><th style="width: 32%;">Tipo de recurso</th><th>Junior</th><th>Medium</th><th>Senior</th><th>Total</th></tr></thead>
                    @foreach ($tiposPersonal as $i => $tipo)
                        <tr>
                            <td>{{ $rec->value_emp[0][$i] ?? $tipo }}</td>
                            <td>{{ $num($personal->{$tipo.'_Junior'}) }}</td>
                            <td>{{ $num($personal->{$tipo.'_Medium'}) }}</td>
                            <td>{{ $num($personal->{$tipo.'_Senior'}) }}</td>
                            <td><b>{{ $num($personal->{$tipo.'_Junior'}) + $num($personal->{$tipo.'_Medium'}) + $num($personal->{$tipo.'_Senior'}) }}</b></td>
                        </tr>
                    @endforeach
                    <tr>
                        <td><b>Total</b></td>
                        <td><b>{{ $nivel('Junior') }}</b></td>
                        <td><b>{{ $nivel('Medium') }}</b></td>
                        <td><b>{{ $nivel('Senior') }}</b></td>
                        <td><b>{{ $nivel('Junior') + $nivel('Medium') + $nivel('Senior') }}</b></td>
                    </tr>
                @else
                    <tr><td><p class="vacio">{{ $sinDatos('employee') }}</p></td></tr>
                @endif
            </table>
        </div>

        <div class="bloque">
            <h4 class="subtitulo gris-oscuro">Maquinarias y equipos</h4>
            <table>
                @php $filas = $maquinaria ? collect($tiposMaquinaria)->filter(fn ($t) => $has($maquinaria->{$t.'_qua'}) || $has($maquinaria->{$t.'_est'})) : collect(); @endphp
                @if ($filas->isNotEmpty())
                    <thead><tr><th style="width: 50%;">Equipos</th><th>Cantidad (n)</th><th>Valor estimado USD</th></tr></thead>
                    @foreach ($filas as $i => $tipo)
                        <tr>
                            <td>{{ $rec->value_maq[0][$i] ?? $tipo }}</td>
                            <td>{{ $maquinaria->{$tipo.'_qua'} }}</td>
                            <td>{{ $maquinaria->{$tipo.'_est'} }}</td>
                        </tr>
                    @endforeach
                @else
                    <tr><td><p class="vacio">{{ $sinDatos('machinery') }}</p></td></tr>
                @endif
            </table>
        </div>

        <div class="bloque">
            <h4 class="subtitulo gris-oscuro">Instalaciones</h4>
            <table>
                @php $filas = $instalaciones ? collect($tiposInstalacion)->filter(fn ($t) => $has($instalaciones->{$t.'_q'}) || $has($instalaciones->{$t.'_surf'}) || $has($instalaciones->{$t.'_own'})) : collect(); @endphp
                @if ($filas->isNotEmpty())
                    <thead><tr><th style="width: 40%;">Tipo de instalación</th><th>Cantidad</th><th>Sup. (m²)</th><th>Tipo de propiedad</th></tr></thead>
                    @foreach ($filas as $i => $tipo)
                        <tr>
                            <td>{{ $rec->value_fac[0][$i] ?? $tipo }}</td>
                            <td>{{ $instalaciones->{$tipo.'_q'} }}</td>
                            <td>{{ $instalaciones->{$tipo.'_surf'} }}</td>
                            <td>{{ $instalaciones->{$tipo.'_own'} }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td><b>Total</b></td>
                        <td><b>{{ $filas->sum(fn ($t) => $num($instalaciones->{$t.'_q'})) }}</b></td>
                        <td><b>{{ $filas->sum(fn ($t) => $num($instalaciones->{$t.'_surf'})) }}</b></td>
                        <td></td>
                    </tr>
                @else
                    <tr><td><p class="vacio">{{ $sinDatos('facility') }}</p></td></tr>
                @endif
            </table>
        </div>

        <div class="bloque">
            <h4 class="subtitulo gris-oscuro">Inventario</h4>
            <table>
                @php $filas = $inventario ? collect($tiposInventario)->filter(fn ($t) => $has($inventario->{$t.'_q'}) || $has($inventario->{$t.'_unit'}) || $has($inventario->{$t.'_est'})) : collect(); @endphp
                @if ($filas->isNotEmpty())
                    <thead><tr><th style="width: 34%;">Tipo de inventario</th><th>Cantidad (n)</th><th>Unidad</th><th>Valor actual estimado</th></tr></thead>
                    @foreach ($filas as $i => $tipo)
                        <tr>
                            <td>{{ $rec->value_inv[0][$i] ?? $tipo }}</td>
                            <td>{{ $inventario->{$tipo.'_q'} }}</td>
                            <td>{{ $inventario->{$tipo.'_unit'} }}</td>
                            <td>{{ $inventario->{$tipo.'_est'} }}</td>
                        </tr>
                    @endforeach
                @else
                    <tr><td><p class="vacio">{{ $sinDatos('inventory') }}</p></td></tr>
                @endif
            </table>
        </div>
    @endif

    {{-- EXPERIENCIA RELEVANTE ..................................................................... --}}
    @if ($want('experiencia'))
        @forelse ($exp as $fila)
            {{-- El título va dentro del bloque de la primera experiencia, para no quedar solo al pie de una página. --}}
            <div class="{{ $loop->first ? 'bloque' : '' }}">
                @if ($loop->first)
                    <h2 class="titulo2 gris-oscuro">Experiencia relevante</h2>
                @endif
                <div class="experiencia-card">
                    <div class="gris-oscuro" style="font-size: 13px; padding: 3px;"><b>{{ $has($fila->ano) ? $fila->ano : 'Año no indicado' }}</b></div>
                    @foreach ([
                        'Sector' => $fila->sectorind,
                        'Tipo' => $fila->tipoind,
                        'Sistema' => $fila->systemind,
                        'Región' => $fila->regionind,
                        'Instalación' => $fila->facilityind,
                        'Orden de magnitud del contrato' => $fila->magnitud,
                        'Esfuerzo H-H — Profesionales y técnicos' => $fila->prof_tech,
                        'Esfuerzo H-H — Mano de obra directa' => $fila->manpower,
                        'Clasificación del trabajo — Sector' => $fila->exp_sector,
                        'Clasificación del trabajo — Servicios' => $fila->exp_service,
                    ] as $etiqueta => $valor)
                        @if ($has($valor))
                            <div class="exp-grupo"><strong>{{ $etiqueta }}:</strong> {{ $valor }}</div>
                        @endif
                    @endforeach
                    @if ($has($fila->descripcion))
                        <div class="exp-grupo"><strong>Breve descripción del trabajo realizado:</strong><br>{{ $fila->descripcion }}</div>
                    @endif
                </div>
            </div>
        @empty
            <div class="bloque">
                <h2 class="titulo2 gris-oscuro">Experiencia relevante</h2>
                <table><tr><td><p class="vacio">{{ $na['experiencias'] ? $naLabel : $vacio }}</p></td></tr></table>
            </div>
        @endforelse
    @endif

    {{-- PRESENCIA INTERNACIONAL ................................................................... --}}
    @if ($want('presencia'))
        <div class="bloque">
            <h2 class="titulo2 gris-oscuro">Presencia internacional</h2>
            @if (! $presencia['registrada'])
                <table><tr><td><p class="vacio">{{ $na['presencia'] ? $naLabel : $vacio }}</p></td></tr></table>
            @else
                <h4 class="subtitulo gris-oscuro">¿Tiene presencia formal en otros países? {{ $presencia['has_offices'] ? 'Sí' : 'No' }}</h4>
                @if (count($presencia['oficinas']) > 0)
                    <table>
                        <thead><tr><th>País</th><th>Oficinas (m²)</th><th>Empleados (n)</th><th>¿Activa?</th></tr></thead>
                        @foreach ($presencia['oficinas'] as $oficina)
                            <tr><td>{{ $oficina['pais'] }}</td><td>{{ $oficina['mts'] }}</td><td>{{ $oficina['empleados'] }}</td><td>{{ $oficina['activa'] }}</td></tr>
                        @endforeach
                    </table>
                @endif
            @endif
        </div>

        @if ($presencia['registrada'])
            <div class="bloque">
                <h4 class="subtitulo gris-oscuro">¿Tiene experiencia desarrollando proyectos en otros países? {{ $presencia['has_experience'] ? 'Sí' : 'No' }}</h4>
                @foreach ($presencia['experiencias'] as $experiencia)
                    <table style="margin-bottom: 4px;">
                        <tr><th>País</th><th>Nro. de proyectos</th><th>Rol</th></tr>
                        <tr class="noBorder"><td>{{ $experiencia['pais'] }}</td><td>{{ $experiencia['proyectos'] }}</td><td>{{ $experiencia['rol'] }}</td></tr>
                        <tr><th>Monto total ejecutado</th><th>Empleados (n)</th><th>Principales clientes</th></tr>
                        <tr><td>{{ $experiencia['monto'] }}</td><td>{{ $experiencia['empleados'] }}</td><td>{{ $experiencia['clientes'] }}</td></tr>
                    </table>
                @endforeach
            </div>
        @endif
    @endif

    {{-- SISTEMAS DE GESTIÓN ....................................................................... --}}
    @if ($want('gestion'))
        @php
            $tarjetas = [
                'calidad' => ['Calidad', ['iso9001' => 'ISO 9001:2015 Sistema de Gestión de la Calidad', 'iso17025' => 'ISO 17025:2005 Laboratorios de Ensayo y de Calibración']],
                'ambiente' => ['Ambiente', ['iso14001' => 'ISO 14001:2015 Sistema de Gestión Ambiental', 'iso50001' => 'ISO 50001:2018 Sistemas de Gestión de la Energía']],
                'credibilidad' => ['Credibilidad y transparencia', ['dun' => 'Dun & Bradstreet (registro declarado por la empresa)', 'iso37001' => 'ISO 37001:2016 Sistemas de Gestión Antisoborno']],
                'seguridad' => ['Seguridad', ['iso45001' => 'ISO 45001:2018 Seguridad y Salud en el Trabajo', 'ovid' => 'OVID']],
                'proyectos' => ['Gestión de proyectos', ['pmi' => 'Project Management Professional (PMI)']],
                'seguridad_info' => ['Seguridad de la información', ['iso27001' => 'ISO 27001 Sistemas de Gestión de la Seguridad de la Información']],
            ];
        @endphp
        <div class="bloque">
            <h2 class="titulo2 gris-oscuro">Sistemas de gestión</h2>
            @if ($na['gestion'])
                <table><tr><td><p class="vacio">{{ $naLabel }}</p></td></tr></table>
            @elseif (! $gestion && ! in_array(true, $naGestion, true))
                <table><tr><td><p class="vacio">{{ $vacio }}</p></td></tr></table>
            @else
                <table style="border: none;">
                    @foreach (array_chunk($tarjetas, 2, true) as $par)
                        <tr>
                            @foreach ($par as $clave => [$titulo, $items])
                                <td style="width: 50%; padding: 3px;">
                                    <div class="card">
                                        <p class="card-titulo">{{ $titulo }}</p>
                                        @if ($naGestion[$clave] && ! ($gestion && (in_array(true, array_intersect_key($gestion['flags'], $items), true) || $gestion['otros'][$clave])))
                                            {{ $naLabel }}
                                        @elseif (! $gestion)
                                            {{ $vacio }}
                                        @else
                                            @foreach ($items as $campo => $etiqueta)
                                                {{ $etiqueta }}: <b>{{ $gestion['flags'][$campo] ? 'Sí' : 'No' }}</b><br>
                                            @endforeach
                                            @foreach ($gestion['otros'][$clave] as $otro)
                                                Otros: <b>{{ $otro }}</b><br>
                                            @endforeach
                                        @endif
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    @endif

</body>
</html>
