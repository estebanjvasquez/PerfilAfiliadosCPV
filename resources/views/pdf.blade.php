    <?php

    use function PHPUnit\Framework\isEmpty;

    $datos = App\Filament\Pages\JoinViews::index($record->id)->data;
    $datos_exp = App\Filament\Pages\JoinViews::index_exp($record->id)->data_exp;
    $datos_pres = App\Filament\Pages\JoinViews::index_pres($record->id)->data_pres;
    $datos_sust = App\Filament\Pages\JoinViews::index_sust($record->id)->data_sust;
    $datos_rec = App\Filament\Pages\JoinViews::index_recursos($record->id)->value_emp; //TITULOS DE RRHH.....
    $datos_recursos = App\Filament\Pages\JoinViews::index_recursos($record->id)->data_rec;
    $datos_maq = App\Filament\Pages\JoinViews::index_recursos($record->id)->value_maq; // TITULOS DE MAQUINARIAS....
    $datos_maquinaria = App\Filament\Pages\JoinViews::index_recursos($record->id)->data_maq;
    $datos_ins = App\Filament\Pages\JoinViews::index_recursos($record->id)->value_fac; //TITULOS DE <INSTALACIONES class=""></INSTALACIONES>
    $datos_instalaciones = App\Filament\Pages\JoinViews::index_recursos($record->id)->data_ins;
    $datos_inv = App\Filament\Pages\JoinViews::index_recursos($record->id)->value_inv; //TITULOS DE INVENTARIOS.....
    $datos_inventario = App\Filament\Pages\JoinViews::index_recursos($record->id)->data_inv;
    $datos_chambers = App\Filament\Pages\JoinViews::index_chambers($record->id)->data_chambers;
    $datos_sec_ser = App\Filament\Pages\JoinViews::index_sec_ser($record->id)->data_sec_ser;

    ?>

    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            * {
                box-sizing: border-box;
            }

            .column1 {
                float: left;
                width: 48%;
                padding: 5px;
                height: 100px;
                vertical-align: top;
                /*border: solid;*/
            }

            .column2 {
                float: right;
                width: 48%;
                padding: 5px;
                height: 100px;
                vertical-align: middle;
                /*border: solid;*/
            }

            .right {
                float: right;
                width: 300px;
                padding: 10px;
            }

            .row:after {
                content: "";
                display: table;
                clear: both;
                font-size: 15px;
            }

            @media screen and (max-width: 600px) {
                .column {
                    width: 100%;
                }
            }

            .titulo {
                text-align: center;
                font-size: 20px;
                color: black;
            }

            .titulo2 {
                text-align: left;
                font-size: 2vw;
                color: black;
                padding: 5px;
            }

            .titulo3 {
                text-align: left;
                font-size: 1.5vw;
                color: black;
                padding-bottom: 5pxM;
            }

            .gris-claro {
                background-color: #C0DEDE;
            }

            .amarillo {
                background-color: #FBB805;
            }

            .gris-oscuro {
                background-color: #D3D3D3
            }

            table {
                border: 2px solid #D3D3D3;
                border-collapse: collapse;
                margin: 0;
                padding: 0;
                width: 100%;
                table-layout: fixed;
            }

            th,
            td {

                padding-top: 10px;
                padding-bottom: 10px;
                padding-left: 25px;
                padding-right: 25px;
                text-align: left;
                border: none;
            }

            th,
            tr.noBorder td {

                border-bottom: 1px solid #CCC;
                border-top: 1px solid #808080;
                border-right: 0;
                border-left: 0;
            }

            fieldset {
                border: 1px solid #ddd;
                margin-top: 1px;
                width: 100%;
                border-radius: 8px;
                padding: 1px;
            }

            input[type="checkbox"] {
                display: inline-block;
                vertical-align: top;
            }

            h3 {
                margin-top: 0px;
                margin-bottom: 3px;
                padding: 1px;
                text-align: center;
            }

            h2 {
                margin-top: 0px;
                margin-bottom: 0px;
                padding: 2px;
            }

            th {
                font-size: 12.5px;
            }

            .card {
                box-shadow: 0 4px 8px 0 rgba(0, 0, 0, 0.2);
                transition: 0.3s;
                width: 33%;
                height: 200px;
                border-radius: 5px;
            }

            .card-sisges {
                box-shadow: 0 4px 8px 0 rgba(0, 0, 0, 0.2);
                transition: 0.3s;
                width: 100%;
                height: 290px;
                border-radius: 5px;
            }

            .card:hover {
                box-shadow: 0 8px 16px 0 rgba(0, 0, 0, 0.2);
            }

            .input-container {
                flex: auto;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;

            }

            .cajacheckbox {
                flex: auto;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
            }

            .fill-height-or-more {
                display: -webkit-box;
                display: -webkit-flex;
                display: -moz-box;
                display: -ms-flexbox;
                display: flex;
                -webkit-box-orient: vertical;
                -webkit-box-direction: normal;
                -webkit-flex-direction: column;
                -moz-box-orient: vertical;
                -moz-box-direction: normal;
                -ms-flex-direction: column;
                flex-direction: column;
                margin-top: 0px;
                display: inline-block;
            }

            .fill-height-or-more>div {
                -webkit-box-flex: 1;
                -webkit-flex: 1;
                -moz-box-flex: 1;
                -ms-flex: 1;
                flex: 1;
                display: -webkit-box;
                display: -webkit-flex;
                display: -moz-box;
                display: -ms-flexbox;
                display: flex;
                -webkit-box-pack: center;
                -webkit-justify-content: center;
                -moz-box-pack: center;
                -ms-flex-pack: center;
                justify-content: center;
                -webkit-box-orient: vertical;
                -webkit-box-direction: normal;
                -webkit-flex-direction: column;
                -moz-box-orient: vertical;
                -moz-box-direction: normal;
                -ms-flex-direction: column;
                flex-direction: column;
                padding-bottom: 10px;
            }


            @page {
                margin: 50px;
            }

            @font-face {
                font-family: Lato;
                font-style: normal;
                font-weight: 300;
                src: url("https://mdn.github.io/web-fonts/LatoReg.ttf");
            }

            body {
                font-family: Lato, sans-serif;
            }

            .page-break {
                page-break-after: always;
            }
        </style>

    </head>

    <body>

        <div class="row amarillo" style="margin-bottom: 10px;">

            <div class="column1">
                <img src="{{ $app_path = public_path('images'); }}/Campet-Logo.png" style="width:20%"></img>
            </div>
            <div class="column2">
                <img class="right" src="{{ $app_path = public_path('images'); }}/Perfil-Logo.png" style="width:60%;"></img>
            </div>
            <div style="width:100%">
                <h3>REPORTE POR EMPRESA DE LOS DATOS REGISTRADOS</h3>
            </div>
        </div>
        <h2 class="titulo2 gris-oscuro">Datos Generales</h2>
        <div style="overflow-x:auto; font-size: 13px; margin: 0px;">
            <table>
                <tr class="noBorder">
                    <td style="width: 35%;">Nombre de la Empresa:</td>
                    <td colspan="2">{{ $datos[0]->nombre }}</td>
                </tr>

                <tr class="noBorder" style="background-color: none">
                    <td>Nro. de RIF:</td>
                    <td colspan="2">{{ $datos[0]->rif }}</td>
                </tr>
                <tr class="noBorder" style="background-color: none">
                    <td>Dirección:</td>
                    <td colspan="2">{{ $datos[0]->direccion }}</td>
                </tr>
                <tr class="noBorder" style="background-color: none">
                    <td>Ciudad / País:</td>
                    <td colspan="2">{{ $datos[0]->ciudad }}</td>
                </tr>

                <tr class="noBorder">
                    <td>Página Web:</td>
                    <td colspan="2">{{ $datos[0]->website }}</td>
                </tr>
                <tr class="noBorder">
                    <td>Teléfono:</td>
                    <td colspan="2">{{ $datos[0]->telefono }} </td>

                </tr>

                <tr class="noBorder" style="vertical-align: top;">
                    <td>Contactos:</td>
                    <td colspan="2">
                        <?php
                        $arr = explode(";", $datos[0]->contactos);
                        if (sizeof($arr) > 1) {

                            foreach ($arr as $value) {
                                $arr_name = explode("(", $value, 2);
                                $nombre = $arr_name[0];
                                echo $nombre;
                                echo "<br>";
                                preg_match('#\((.*?)\)#', $value, $match);
                                $arr_datos = explode(",", $match[1], 3);
                                if (array_key_exists(0, $arr_datos)) echo $arr_datos[0] . "<br>";
                                if (array_key_exists(1, $arr_datos)) echo $arr_datos[1] . "<br>";
                                if (array_key_exists(2, $arr_datos)) echo $arr_datos[2] . "<br>";
                                echo "<hr>";
                        ?> <br>
                        <?php
                            }
                        }
                        ?>
                    </td>
                </tr>
                <tr class="noBorder">
                    <td>Año de Fundación:</td>
                    <td colspan="2">{{ $datos[0]->fundacion }}</td>
                </tr>
                <tr class="noBorder">
                    <td>Capital de la Empresa:</td>
                    <td colspan="2">{{ $datos[0]->capital }} / {{ $datos[0]->origen }}</td>
                </tr>
                <tr class="noBorder">
                    <td style="padding-top: 0px; padding-bottom: 0px; border: 0">Operación en Venezuela</td>
                    <td style="padding-top: 0px; padding-bottom: 0px; border: 0">Facturación Anual:</td>
                    <td style="padding-top: 0px; padding-bottom: 0px;  padding-left: 0px; border: 0">{{ $datos[0]->facturacion_anual }}</td>
                </tr>

                <tr class="noBorder">
                    <td style="padding-top: 0px; padding-bottom: 0px; border: 0"></td>
                    <td style="padding-top: 0px; padding-bottom: 0px; border: 0">Empleados:</td>
                    <td style="padding-top: 0px; padding-bottom: 0px; padding-left: 0px; border: 0">{{ $datos[0]->empleados }}</td>
                </tr>

                <tr class="noBorder">
                    <td style="padding-top: 0px; padding-bottom: 0px; border: 0"></td>
                    <td style="padding-top: 0px; padding-bottom: 0px; border: 0">Estatus Actual:</td>
                    <td style="padding-top: 0px; padding-bottom: 0px; padding-left: 0px; border: 0">{{ $datos[0]->estado_actual }}</td>
                </tr>
            </table>
        </div>
        <div class="page-break"></div>

        <!-- CLIENTES............................................................. -->
        <div class="row amarillo" style="margin-bottom: 10px;">
            <div class="column2">
                <img class="right" src="{{ $app_path = public_path('images'); }}/Perfil-Logo.png" style="width:50%; "></img>
            </div>
        </div>
        <h2 class="titulo2 gris-oscuro">Clientes</h2>
        <div style="overflow-x:auto; font-size: 13px; margin: 0px;">
            <table>
                <tr class="noBorder">
                    <th>Nombre</th>
                    <th>País</th>
                </tr>
                @if (strlen($datos[0]->clientes) > 0)
                @for ($i = 0; $i < count($datos); $i++) <tr>
                    <td style="border: none">
                        {{ $cli = $datos[$i]->clientes }}
                    </td>
                    <td style="border: none">
                        {{ $cli_pais = $datos[$i]->cli_pais }}
                    </td>
                    </tr>
                    @endfor
                    @else
                    <tr>
                        <td colspan="4">
                            <p><b>No existe información</b></p>
                        </td>
                    </tr>
                    @endif

            </table>
        </div>
        <div class="page-break"></div>
        <!-- FIN DE CLIENTES.......................................................... -->

        <!-- CAMARAS............................................................. -->
        <div class="row amarillo" style="margin-bottom: 10px;">
            <div class="column2">
                <img class="right" src="{{ $app_path = public_path('images'); }}/Perfil-Logo.png" style="width:50%; "></img>
            </div>
        </div>
        <h2 class="titulo2 gris-oscuro">Cámaras</h2>
        <div style="overflow-x:auto; font-size: 13px; margin: 0px;">
            <table>
                <tr class="noBorder">
                    <th>Nombre</th>
                </tr>
                @if (count($datos_chambers) > 0)
                @for ($i = 0; $i < count($datos_chambers); $i++) <tr>
                    <td style="border: none">
                        {{ $datos_chambers[$i]->name }}
                    </td>
                    </tr>
                    @endfor
                    @else
                    <tr>
                        <td colspan="4">
                            <p><b>No existe información</b></p>
                        </td>
                    </tr>
                    @endif
            </table>
        </div>
        <div class="page-break"></div>
        <!-- FIN DE CAMARAS.......................................................... -->

        <!-- SECTORES Y SERVICIOS.................................................... -->
        <div class="row amarillo" style="margin-bottom: 10px;">
            <div class="column2">
                <img class="right" src="{{ $app_path = public_path('images'); }}/Perfil-Logo.png" style="width:50%; "></img>
            </div>
        </div>
        <div style="font-size: 13px;">
            <table>
                <tr>
                    <td colspan="4" style="padding: 0px; border: none">
                        <h2 class="titulo2 gris-oscuro">Sectores de Actividad Económica y Servicios</h2>
                    </td>
                </tr>

                <tr>
                    <td style="border: none; width: 35%"><b>Sector</b></td>
                    <td colspan="3" style="border: none"><b>Servicios</b></td>
                </tr>
                @if (count($datos_sec_ser) > 0)
                @php $vari = null; @endphp
                @for ($i = 0; $i < count($datos_sec_ser); $i++) <tr>

                    <td style="border: none">
                        @if ($datos_sec_ser[$i]->sector == $vari)

                        @else

                        {{ $datos_sec_ser[$i]->sector }}
                        @endif

                        @php $vari = $datos_sec_ser[$i]->sector; @endphp
                    </td>
                    <td colspan="3" style="border: none">
                        {{ $datos_sec_ser[$i]->servicio }}
                    </td>
                    </tr>

                    @endfor
                    @else
                    <tr>
                        <td colspan="4">
                            <p><b>No existe información</b></p>
                        </td>
                    </tr>
                    @endif

            </table>
        </div>
        </div>
        <div class="page-break"></div>
        <!-- FIN DE SECTORES Y SERVICIOS ..............................................................-->

        <!-- ENFOQUE A SOSTENIBILIDAD......................................................................-->
        <div class="row amarillo" style="margin-bottom: 10px;">
            <div class="column2">
                <img class="right" src="{{ $app_path = public_path('images'); }}/Perfil-Logo.png" style="width:50%; "></img>
            </div>
        </div>
        <div style="font-size: 13px;">
            <table>
                <tr>
                    <td colspan="4" style="padding: 0px; border: none">
                        <h2 class="titulo2 gris-oscuro">Enfoque de Sostenibilidad</h2>
                    </td>
                </tr>
                @if (sizeof($datos_sust) > 0)
                <!--
            <tr>
                <td colspan="4" style="padding: 0px; border: none">
                    <p class="amarillo titulo" style="text-align: center; font-size: 1vw">Su empresa ha implementado programas formales de Desarrollo Sostenible dirigidos a alguna de las siguientes tareas?</p>
                </td>
            </tr>
                    -->
                <!-- CODES FOR SUSTAINABILITIES.....................................................               
                '0' => 'No',
                '1' => 'Sí: Inactivo',
                '2' => 'Sí: Activo',
            .................................................................................-->
                <tr>
                    <th style="width:40%;">Áreas</th>
                    <th style="width:20%;">Sí: Activo</th>
                    <th style="width:20%;">Sí: Inactivo</th>
                    <th style="width:20%;">No</th>
                </tr>
                @foreach ($datos_sust as $key=>$row)
                <tr>
                    <!--<td style="width:10%; font-size: 14px">{{ $key+1}}</td>-->
                    <td style="width:30%; font-size: 14px">{{$row->sust_title}}</td>

                    @switch($row->sust_status)
                    @case('2')
                    <td style="width:20%; font-size: 14px;">Sí</td>
                    <td></td>
                    <td></td>
                    @break
                    @case('1')
                    <td></td>
                    <td style="width:20%; font-size: 14px;">Sí</td>
                    <td></td>
                    @break
                    @case('0')
                    <td></td>
                    <td></td>
                    <td style="width:20%; font-size: 14px;">No</td>
                    @break

                    @endswitch

                </tr>
                @endforeach
                @else
                <tr>
                    <td colspan="4">
                        <p><b>No existe información</b></p>
                    </td>
                </tr>
                @endif
            </table>
        </div>
        <div class="page-break"></div>

        <!-- FIN DE ENFOQUE A SOSTENIBILIDAD............................................. -->

        <!-- RECURSOS EN VENEZUELA..............................................................-->
        <div class="row amarillo" style="margin-bottom: 10px;">
            <div class="column2">
                <img class="right" src="{{ $app_path = public_path('images'); }}/Perfil-Logo.png" style="width:50%; "></img>
            </div>
        </div>
        <div style="font-size: 13px; border: none;">
            <table>
                <tr>
                    <td colspan="5" style="padding: 0px; border: none">
                        <h2 class="titulo2 gris-oscuro">Recursos en Venezuela</h2>
                    </td>
                </tr>
                <tr>
                    <td colspan="5" style="padding: 5px; border: none; font-size: 13px;" class="gris-oscuro">
                        <strong>Recursos Humanos</strong>
                    </td>
                </tr>

                @if (sizeof($datos_recursos) > 0)
                <tr>
                    <th style="width: 30%;">Tipo de Recurso</th>
                    <th style="width: 17.5%;">Junior</th>
                    <th style="width: 17.5%;">Medium</th>
                    <th style="width: 17.5%;">Senior</th>
                    <th style="width: 17.5%;">Total</th>
                </tr>

                @foreach ($datos_rec[0] as $key=>$row)
                <tr>
                    <td>{{ $datos_rec[0][$key] }}</td>
                    @if ($key == 0)
                    <td>{{ $datos_recursos[0]->Bachilleres_Junior }}</td>
                    <td>{{ $datos_recursos[0]->Bachilleres_Medium }}</td>
                    <td>{{ $datos_recursos[0]->Bachilleres_Senior }}</td>
                    <td><b>{{ $datos_recursos[0]->Bachilleres_Junior + $datos_recursos[0]->Bachilleres_Medium + $datos_recursos[0]->Bachilleres_Senior}}</b></td>
                    @endif
                    @if ($key == 1)
                    <td>{{ $datos_recursos[0]->Tecnicos_Junior }}</td>
                    <td>{{ $datos_recursos[0]->Tecnicos_Medium }}</td>
                    <td>{{ $datos_recursos[0]->Tecnicos_Senior }}</td>
                    <td><b>{{ $datos_recursos[0]->Tecnicos_Junior + $datos_recursos[0]->Tecnicos_Medium + $datos_recursos[0]->Tecnicos_Senior}}</b></td>
                    @endif
                    @if ($key == 2)
                    <td>{{ $datos_recursos[0]->Ingenieros_Junior }}</td>
                    <td>{{ $datos_recursos[0]->Ingenieros_Medium }}</td>
                    <td>{{ $datos_recursos[0]->Ingenieros_Senior }}</td>
                    <td><b>{{ $datos_recursos[0]->Ingenieros_Junior + $datos_recursos[0]->Ingenieros_Medium + $datos_recursos[0]->Ingenieros_Senior}}</b></td>
                    @endif
                    @if ($key == 3)
                    <td>{{ $datos_recursos[0]->Administrativos_Junior }}</td>
                    <td>{{ $datos_recursos[0]->Administrativos_Medium }}</td>
                    <td>{{ $datos_recursos[0]->Administrativos_Senior }}</td>
                    <td><b>{{ $datos_recursos[0]->Administrativos_Junior + $datos_recursos[0]->Administrativos_Medium + $datos_recursos[0]->Administrativos_Senior}}</b></td>
                    @endif
                    @if ($key == 4)
                    <td>{{ $datos_recursos[0]->Gerentes_Junior }}</td>
                    <td>{{ $datos_recursos[0]->Gerentes_Medium }}</td>
                    <td>{{ $datos_recursos[0]->Gerentes_Senior }}</td>
                    <td><b>{{ $datos_recursos[0]->Gerentes_Junior + $datos_recursos[0]->Gerentes_Medium + $datos_recursos[0]->Gerentes_Senior}}</b></td>

                    @endif
                    @if ($key == 5)
                    <td>{{ $datos_recursos[0]->Directivos_Junior }}</td>
                    <td>{{ $datos_recursos[0]->Directivos_Medium }}</td>
                    <td>{{ $datos_recursos[0]->Directivos_Senior }}</td>
                    <td><b>{{ $datos_recursos[0]->Directivos_Junior + $datos_recursos[0]->Directivos_Medium + $datos_recursos[0]->Directivos_Senior}}</b></td>
                    @endif
                    @endforeach
                </tr>
                <tr>
                    <td><b>Total</b></td>
                    <td><b>{{ $datos_recursos[0]->Bachilleres_Junior + $datos_recursos[0]->Tecnicos_Junior + $datos_recursos[0]->Ingenieros_Junior + $datos_recursos[0]->Administrativos_Junior + $datos_recursos[0]->Gerentes_Junior + $datos_recursos[0]->Directivos_Junior}}</b></td>
                    <td><b>{{ $datos_recursos[0]->Bachilleres_Medium + $datos_recursos[0]->Tecnicos_Medium + $datos_recursos[0]->Ingenieros_Medium + $datos_recursos[0]->Administrativos_Medium + $datos_recursos[0]->Gerentes_Medium + $datos_recursos[0]->Directivos_Medium}}</b></td>
                    <td><b>{{ $datos_recursos[0]->Bachilleres_Senior + $datos_recursos[0]->Tecnicos_Senior + $datos_recursos[0]->Ingenieros_Senior + $datos_recursos[0]->Administrativos_Senior + $datos_recursos[0]->Gerentes_Senior + $datos_recursos[0]->Directivos_Senior}}</b></td>
                    <td><b>{{ $datos_recursos[0]->Total }}</b></td>
                </tr>

                @else
                <tr>
                    <td colspan="4">
                        <p><b>No existe información</b></p>
                    </td>
                </tr>
                @endif
            </table>

            <table>
                <tr>
                    <td colspan="5" style="padding: 5px; border: none; font-size: 13px;" class="gris-oscuro">
                        <strong>Maquinarias y Equipos</strong>
                    </td>
                </tr>

                @if (sizeof($datos_maquinaria) > 0)
                <tr>
                    <th colspan="3" style="width: 17.5%;">Equipos</th>
                    <th>Cantidad (n)</th>
                    <th>Valor estimado USD</th>
                </tr>

                @foreach ($datos_maq[0] as $key=>$row)
                <tr>
                    <td style="border: none; width:auto;" colspan="3">{{ $datos_maq[0][$key]}}</td>
                    @if ($key == 0)
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Equip_med_lev_qua }}</td>
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Equip_med_lev_est }}</td>
                    @endif
                    @if ($key == 1)
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Equip_mar_flu_qua }}</td>
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Equip_mar_flu_est }}</td>
                    @endif
                    @if ($key == 2)
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Mov_terr_cons_qua }}</td>
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Mov_terr_cons_est }}</td>
                    @endif
                    @if ($key == 3)
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Equip_men_cons_qua }}</td>
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Equip_men_cons_est }}</td>
                    @endif
                    @if ($key == 4)
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Fab_metal_elec_qua }}</td>
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Fab_metal_elec_est }}</td>
                    @endif
                    @if ($key == 5)
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Mont_elec_meca_qua }}</td>
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Mont_elec_meca_est }}</td>
                    @endif
                    @if ($key == 6)
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Maq_herr_meca_qua }}</td>
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Maq_herr_meca_est }}</td>
                    @endif
                    @if ($key == 7)
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Almac_trans_qua }}</td>
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Almac_trans_est }}</td>
                    @endif
                    @if ($key == 8)
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Serv_poz_inst_qua }}</td>
                    <td style="border: none; width:auto;">{{ $datos_maquinaria[0]->Serv_poz_inst_qua }}</td>
                    @endif
                </tr>

                @endforeach

                @else
                <tr>
                    <td colspan="4">
                        <p><b>No existe información</b></p>
                    </td>
                </tr>
                @endif
            </table>
            <div class="page-break"></div>

            <!-- INSTALACIONES ...........-->
            <div class="row amarillo" style="margin-bottom: 10px;">
                <div class="column2">
                    <img class="right" src="{{ $app_path = public_path('images'); }}/Perfil-Logo.png" style="width:50%; "></img>
                </div>
            </div>
            <table>
                <tr>
                    <td colspan="5" style="padding: 0px; border: none">
                        <h2 class="titulo2 gris-oscuro">Recursos en Venezuela</h2>
                    </td>
                </tr>
                <tr>
                    <td colspan="5" style="padding: 5px; border: none; font-size: 13px;" class="gris-oscuro">
                        <strong>Instalaciones</strong>
                    </td>
                </tr>
                @if (sizeof($datos_instalaciones) > 0)
                <tr>
                    <th colspan="2">Tipo de Instalación</th>
                    <th>Cantidad</th>
                    <th>Sup. (mt2)</th>
                    <th>Tipo de Propiedad</th>
                </tr>

                @foreach ($datos_ins[0] as $key=>$row)
                <tr>
                    <td colspan="2">{{ $datos_ins[0][$key]}}</td>
                    @if ($key == 0)
                    <td>{{ $datos_instalaciones[0]->Oficinas_q }}</td>
                    <td>{{ $datos_instalaciones[0]->Oficinas_surf }}</td>
                    <td>{{ $datos_instalaciones[0]->Oficinas_own }}</td>

                    @endif
                    @if ($key == 1)
                    <td>{{ $datos_instalaciones[0]->Talleres_q }}</td>
                    <td>{{ $datos_instalaciones[0]->Talleres_surf }}</td>
                    <td>{{ $datos_instalaciones[0]->Talleres_own }}</td>
                    @endif
                    @if ($key == 2)
                    <td>{{ $datos_instalaciones[0]->Manufactura_q }}</td>
                    <td>{{ $datos_instalaciones[0]->Manufactura_surf }}</td>
                    <td>{{ $datos_instalaciones[0]->Manufactura_own }}</td>
                    @endif
                    @if ($key == 3)
                    <td>{{ $datos_instalaciones[0]->Almacenes_q }}</td>
                    <td>{{ $datos_instalaciones[0]->Almacenes_surf }}</td>
                    <td>{{ $datos_instalaciones[0]->Almacenes_own }}</td>
                    @endif
                    @if ($key == 4)
                    <td>{{ $datos_instalaciones[0]->Laboratorios_q }}</td>
                    <td>{{ $datos_instalaciones[0]->Laboratorios_surf }}</td>
                    <td>{{ $datos_instalaciones[0]->Laboratorios_own }}</td>
                    @endif
                    @if ($key == 5)
                    <td>{{ $datos_instalaciones[0]->Marinas_q }}</td>
                    <td>{{ $datos_instalaciones[0]->Marinas_surf }}</td>
                    <td>{{ $datos_instalaciones[0]->Marinas_own }}</td>
                    @endif
                    @if ($key == 6)
                    <td>{{ $datos_instalaciones[0]->Otros_q }}</td>
                    <td>{{ $datos_instalaciones[0]->Otros_surf }}</td>
                    <td>{{ $datos_instalaciones[0]->Otros_own }}</td>
                    @endif
                </tr>
                @endforeach

                <tr>
                    <td colspan="2"><b>Total</b></td>
                    <td><b>{{ (int) str_replace(' ', 0, $datos_instalaciones[0]->Oficinas_q) + (int) str_replace(' ', 0, $datos_instalaciones[0]->Talleres_q) + (int) str_replace(' ', 0, $datos_instalaciones[0]->Manufactura_q) + (int) str_replace(' ', 0, $datos_instalaciones[0]->Almacenes_q) + (int) str_replace(' ', 0, $datos_instalaciones[0]->Laboratorios_q) + (int) str_replace(' ', 0, $datos_instalaciones[0]->Marinas_q) + (int) str_replace(' ', 0, $datos_instalaciones[0]->Otros_q)}}</b></td>
                    <td><b>{{ (int) str_replace(' ', 0, $datos_instalaciones[0]->Oficinas_surf) + (int) str_replace(' ', 0, $datos_instalaciones[0]->Talleres_surf) + (int)str_replace(' ', 0, $datos_instalaciones[0]->Manufactura_surf) + (int) str_replace(' ', 0, $datos_instalaciones[0]->Almacenes_surf) + (int)str_replace(' ', 0, $datos_instalaciones[0]->Laboratorios_surf) + (int) str_replace(' ', 0, $datos_instalaciones[0]->Marinas_surf) + (int) str_replace(' ', 0, $datos_instalaciones[0]->Otros_surf)}}</b></td>
                    <td></td>
                </tr>
                @else
                <tr>
                    <td colspan="4">
                        <p><b>No existe información</b></p>
                    </td>
                </tr>
                @endif
            </table>

            <!-- INVENTARIO........-->
            <table>
                <tr>
                    <td colspan="6" style="padding: 5px; border: none; font-size: 13px;" class="gris-oscuro">
                        <strong>Inventario</strong>
                    </td>
                </tr>
                @if (sizeof($datos_inventario) > 0)
                <tr>
                    <th colspan="3" style="text-align: left; width: 10%;">Tipo de Inventario</th>
                    <th style="white-space: nowrap;">Cantidad (n)</th>
                    <th>Unidad</th>
                    <th>Valor actual Est.</th>
                </tr>

                @foreach ($datos_inv[0] as $key=>$row)
                <tr>
                    <td style="text-align: left; " colspan="3">{{ $datos_inv[0][$key]}}</td>
                    @if ($key == 0)
                    <td>{{ $datos_inventario[0]->Materia_q }}</td>
                    <td>{{ $datos_inventario[0]->Materia_unit }}</td>
                    <td>{{ $datos_inventario[0]->Materia_est }}</td>
                    @endif
                    @if ($key == 1)
                    <td>{{ $datos_inventario[0]->Producto_q }}</td>
                    <td>{{ $datos_inventario[0]->Producto_unit }}</td>
                    <td>{{ $datos_inventario[0]->Producto_est }}</td>
                    @endif
                </tr>

                @endforeach
                @else
                <tr>
                    <td colspan="6">
                        <p><b>No existe información</b></p>
                    </td>
                </tr>
                @endif
            </table>


            <div class="page-break"></div>
            <!-- FIN DE RECURSOS EN VENEZUELA..............................................................-->

            <!-- EXPERIENCIA RELEVANTE.....................................................................-->
            <div class="row amarillo" style="margin-bottom: 10px;">
                <div class="column2">
                    <img class="right" src="{{ $app_path = public_path('images'); }}/Perfil-Logo.png" style="width:50%; "></img>
                </div>
            </div>

            <table>
                <tr>
                    <td colspan="3" style="padding: 0px; border: none">
                        <h2 class="titulo2 gris-oscuro">Experiencia Relevante</h2>
                    </td>
                </tr>

                @if (sizeof($datos_exp) > 0)
                @foreach ($datos_exp as $row)

                <tr class="noBorder">
                    <td style="vertical-align: top;">
                        <fieldset class=" fill-height-or-more" style="margin-top: 0;">
                            <div class="gris-oscuro" style="font-size: 13.5px;">
                                <b>{{ $row->ano }}</b>
                            </div>
                            <div>
                                <strong>Sector:</strong><br>
                                {{ $row->sectorind }} <br>
                            </div>
                            <div>
                                <strong>Tipo:</strong><br>
                                {{ $row->tipoind }} <br>
                            </div>
                            <div>
                                <strong>Sistema:</strong><br>
                                {{ $row->systemind }} <br>
                            </div>
                            <div>
                                <strong>Región:</strong><br>
                                {{ $row->regionind }} <br>
                            </div>
                            <div>
                                <strong>Instalación:</strong>
                                {{ $row->facilityind }} <br>
                            </div>
                        </fieldset>

                    </td>

                    <td style="vertical-align: top;">
                        <fieldset class="fill-height-or-more">
                            <div class="gris-oscuro" style="font-size: 12.5px; ">
                                <b>Orden de Magnitud del Contrato</b>
                            </div>
                            <div>
                                <b>{{ $row->magnitud }}</b>
                            </div>
                            <div>
                                <strong>Esfuerzo H-H:</strong>
                            </div>
                            <div>
                                <strong>Profesionales y Técnicos:</strong><br>
                                {{ $row->prof_tech }}
                            </div>
                            <div>
                                <strong>Mano de Obra directa:</strong><br>
                                {{ $row->manpower }}
                            </div>
                            <div>
                                <b>Clasificación del Trabajo Realizado</b><br>
                            </div>
                            <div>
                                <strong>Sector:</strong><br>
                                {{ $row->exp_sector }}
                            </div>
                            <div>
                                <strong>Servicios:</strong><br>
                                {{ $row->exp_service }}
                            </div>
                        </fieldset>
                    </td>

                    <td style="vertical-align: top;">
                        <fieldset class=" fill-height-or-more">
                            <div class="gris-oscuro" style="font-size: 12.5px"><b>Breve descripción del Trabajo realizado</b>

                            </div>
                            <div>
                                {{ str_replace('null', ' ', $row->descripcion) }}
                            </div>

                        </fieldset>
                    </td>


                </tr>
                <TH COLSPAN=3>
                    <hr>
                    @endforeach

                    @else
                    <tr>
                        <td colspan="3">
                            <p><b>No existe información</b></p>
                        </td>
                    </tr>


                    @endif


            </table>
            <!--</div>-->
        </div>

        </td>

        </table>
        <div class="page-break"></div>
        <!-- PRESENCIA INTERNACIONAL.....................................................................--->
        <div class="row amarillo" style="margin-bottom: 10px;">
            <div class="column2">
                <img class="right" src="{{ $app_path = public_path('images'); }}/Perfil-Logo.png" style="width:50%; "></img>
            </div>
        </div>
        <div style="font-size: 13px; border: none;">
            <table>
                <tr>
                    <td colspan="4" style="padding: 0px; border: none">
                        <h2 class="titulo2 gris-oscuro">Presencia Internacional</h2>
                    </td>
                </tr>
                <tr>
                    <td colspan="4" style="padding: 5px; border: none; font-size: 13px;" class="gris-oscuro">
                        <strong>Tiene Presencia formal en otros Países?</strong>
                    </td>
                </tr>
                @if (sizeof($datos_pres) > 0)
                <!-- PRESENCIA EN OTROS PAISES.............. -->
                <tr>
                    <th>País</th>
                    <th>Oficinas (Mts2)</th>
                    <th>Empleados (n)</th>
                    <th>Activa?</th>
                </tr>

                @foreach ($datos_pres as $row)
                @if ($row->hasOfficesYes == 'X')
                <tr>
                    <td>{{ $row->pais }}</td>
                    <td>{{ $row->mts }}</td>
                    <td>{{ $row->emp_q }}</td>
                    <td>{{ $row->activa }}</td>
                </tr>

                @endif
                @endforeach
                <!-- EXPERIENCIA EN OTROS PAISES............ -->
                <tr>
                    <td colspan="4" style="padding: 5px; border: none; font-size: 13px;" class="gris-oscuro">
                        <strong>Tiene Experiencia desarrollando proyectos en otros Países?</strong>
                    </td>
                </tr>

                @foreach ($datos_pres as $row)
                @if (($row->hasExperienceYes == 'X') && (strlen($row->paisx) > 0))
                <tr>
                    <th colspan=2 style="text-align: left;">País</th>
                    <th style="text-align: left;">Nro. de Proyectos</th>
                    <th style="text-align: left;">Rol</th>

                </tr>
                <tr class="noBorder">
                    <td colspan=2 style="border: none;">{{ $row->paisx }}</td>
                    <td style="border: none;">{{ $row->proj_q }}</td>
                    <td style="border: none;">{{ $row->role }}</td>

                </tr>

                <tr>
                    <th colspan="2" style="text-align: left;">Monto total Ejecutado</th>
                    <th style="text-align: left;">Empleados (n)</th>
                    <th style="text-align: left;">Principales clientes</th>

                </tr>
                <tr class="noBorder">
                    <td colspan=2 style="border: none;">{{ $row->montox }}</td>
                    <td style="border: none;">{{ $row->expemployees }}</td>
                    <td style="border: none;">{{ $row->clients }}</td>

                </tr>
                @endif
                @endforeach
                @else
                <tr>
                    <td colspan="4">
                        <p><b>No existe información</b></p>
                    </td>
                </tr>
                @endif
            </table>
        </div>
        <div class="page-break"></div>
        <!-- SISTEAS DE GESTIÓN..............................................................................-->
        <div class="row amarillo" style="margin-bottom: 10px;">
            <div class="column2">
                <img class="right" src="{{ $app_path = public_path('images'); }}/Perfil-Logo.png" style="width:50%; "></img>
            </div>
        </div>
        <table>
            <tr>
                <td colspan="3" style="padding: 0px; border: none">
                    <h2 class="titulo2 gris-oscuro">Sistemas de Gestión</h2>
                </td>
            </tr>
            <!-- PRIMERA FILA....................................................................... -->
            <tr>
                <td style="padding: 0px; border: solid #bbbbbb">
                    <div class="card-sisges" style="display:inline-block; font-size: 14px; padding: 5px;">
                        <p>Calidad</p>
                        <fieldset class="cajacheckbox" style="border: none; padding: 0px; margin: 0px">
                            @if ($datos[0]->iso9001 == 'SÍ')
                            <input type="checkbox" checked disabled name="iso9001">ISO 9001:2015 Sistema de Gestión de la Calidad<br>

                            @else
                            <input type="checkbox" disabled name="iso9001">ISO 9001:2015 Sistema de Gestión de la Calidad<br>
                            @endif
                            @if ($datos[0]->iso17025 == 'SÍ')
                            <input type="checkbox" checked disabled name="iso17025">ISO 17025: 2005 Laboratorios de Ensayo y de Calibración<br>
                            @else
                            <input type="checkbox" disabled name="iso17025">ISO 17025: 2005 Laboratorios de Ensayo y de Calibración<br>
                            @endif
                            <br>
                            @if ($datos[0]->quality_otros != 'NO')
                            <input type="checkbox" checked disabled name="quality_otros">Otros: {{ $datos[0]->quality_otros}}<br>
                            @endif
                        </fieldset>
                    </div>
                </td>
                <td style="padding: 0px; border: solid #bbbbbb"">
                    <div class=" card-sisges" style="display:inline-block; font-size: 14px; padding: 5px; ">
                    <p>Ambiente</p>
                    <fieldset class="cajacheckbox" style="border: none; padding: 0px; margin: 0px">
                        @if ($datos[0]->iso14001 == 'SÍ')
                        <input type="checkbox" checked disabled name="iso14001">ISO 14001:2015 Sistema de Gestión Ambiental<br>
                        @else
                        <input type="checkbox" disabled name="iso14001">ISO 14001:2015 Sistema de Gestión Ambiental<br>
                        @endif
                        @if ($datos[0]->iso50001 == 'SÍ')
                        <input type="checkbox" checked disabled name="iso50001">ISO 50001: 2018 Sistemas de Gestión de la Energía<br>
                        @else
                        <input type="checkbox" disabled name="iso50001">ISO 50001: 2018 Sistemas de Gestión de la Energía<br>
                        @endif
                        <br>
                        @if ($datos[0]->environment_otros != 'NO')
                        <input type="checkbox" checked disabled name="environment_otros">Otros: {{ $datos[0]->environment_otros}}<br>
                        @endif
                    </fieldset>
                    </div>
                </td>
                <td style="padding: 0px; border: solid #bbbbbb"">
                    <div class=" card-sisges" style="display:inline-block; font-size: 14px; padding: 5px; ">
                    <p>Credibilidad y Transparencia</p>
                    <fieldset class="cajacheckbox" style="border: none; padding: 0px; margin: 0px">
                        @if ($datos[0]->dun == 'SÍ')
                        <input type="checkbox" checked disabled name="dun">Dun y Brad Street<br>
                        @else
                        <input type="checkbox" disabled name="dun">Dun y Brad Street<br>
                        @endif
                        @if ($datos[0]->iso37001 == 'SÍ')
                        <input type="checkbox" checked disabled name="iso37001">ISO 37001: 2016 Sistemas de Gestión Antisoborno<br>
                        @else
                        <input type="checkbox" disabled name="iso37001">ISO 37001: 2016 Sistemas de Gestión Antisoborno<br>
                        @endif
                        <br>
                        @if ($datos[0]->credibility_otros != 'NO')
                        <input type="checkbox" disabled checked name="credibility_otros">Otros: {{ $datos[0]->credibility_otros}}<br>
                        @endif
                    </fieldset>
                    </div>
                </td>
            </tr>

            <!-- SEGUNDA FILA.............................................................................. -->
            <tr>
                <td style="padding: 0px; border: solid #bbbbbb"">
                    <div class=" card-sisges" style="display:inline-block; font-size: 14px; padding: 5px;">
                    <p>Seguridad</p>
                    <fieldset class="cajacheckbox" style="border: none; padding: 0px; margin: 0px">
                        @if ($datos[0]->iso45001 == 'SÍ')
                        <input type="checkbox" checked disabled name="iso45001">ISO 45001:2018 Seguridad y Salud en el Trabajo<br>
                        @else
                        <input type="checkbox" disabled name="iso45001">ISO 45001:2018 Seguridad y Salud en el Trabajo<br>
                        @endif
                        @if ($datos[0]->ovid == 'SÍ')
                        <input type="checkbox" disabled checked name="ovid">COVID<br>
                        @else
                        <input type="checkbox" disabled name="ovid">COVID<br>
                        @endif
                        <br>
                        @if ($datos[0]->security_otros != 'NO')
                        <input type="checkbox" checked disabled name="security_otros">Otros: {{ $datos[0]->security_otros}}<br>
                        @endif
                    </fieldset>
                    </div>
                </td>
                <td style="padding: 0px; border: solid #bbbbbb"">
                    <div class=" card-sisges" style="display:inline-block; font-size: 14px; padding: 5px; ">
                    <p>Gestión de Proyectos</p>
                    <!-- <p><b>Posee personal calificado en:</b></p>-->
                    <fieldset class="cajacheckbox" style="border: none; padding: 0px; margin: 0px">
                        @if ($datos[0]->pmi == 'SÍ')
                        <input type="checkbox" disabled checked name="pmi">Project Management Professional (PMI)<br>
                        @else
                        <input type="checkbox" disabled name="pmi">Project Management Professional (PMI)<br>
                        @endif

                        <br>
                        @if ($datos[0]->pmi_otros != 'NO')
                        <input type="checkbox" disabled checked name="pmi_otros">Otros: {{ $datos[0]->pmi_otros}}<br>
                        @endif
                    </fieldset>
                    </div>
                </td>
                <td style="padding: 0px; border: solid #bbbbbb"">
                    <div class=" card-sisges" style="display:inline-block; font-size: 14px; padding: 5px; ">
                    <p>Seguridad de la Información</p>

                    <fieldset class="cajacheckbox" style="border: none; padding: 0px; margin: 0px">
                        @if ($datos[0]->iso27001 == 'SÍ')
                        <input type="checkbox" disabled checked name="iso27001">ISO 27001 Sistemas de Gestión de la Seguridad de la Información<br>
                        @else
                        <input type="checkbox" disabled name="iso27001">ISO 27001 Sistemas de Gestión de la Seguridad de la Información<br>
                        @endif

                        <br>
                        @if ($datos[0]->info_otros != 'NO')
                        <input type="checkbox" disabled checked name="info_otros">Otros: {{ $datos[0]->info_otros}}<br>
                        @endif
                    </fieldset>
                    </div>
                </td>

            </tr>
        </table>
        <!--<div class="page-break"></div>-->




    </body>