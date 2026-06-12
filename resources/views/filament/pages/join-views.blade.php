<?php
$record = explode("?", explode("?", $_SERVER['REQUEST_URI'])[1]);
$datos = App\Filament\Pages\JoinViews::index($record[0])->data;
$datos_exp = App\Filament\Pages\JoinViews::index_exp($record[0])->data_exp;
$datos_pres = App\Filament\Pages\JoinViews::index_pres($record[0])->data_pres;

?>

<x-filament::page>

    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            * {
                box-sizing: border-box;
            }

            .column {
                float: left;
                width: 50%;
                padding: 10px;
                height: 200px;
                vertical-align: middle;
                /* Should be removed. Only for demonstration */
            }

            /* Clear floats after the columns */
            .row:after {
                content: "";
                display: table;
                clear: both;
            }

            /* Responsive layout - makes the two columns stack on top of each other instead of next to each other */
            @media screen and (max-width: 600px) {
                .column {
                    width: 100%;
                }
            }

            .titulo {
                text-align: center;
                font-size: 2vw;
                color: black;
            }

            .titulo2 {
                text-align: left;
                font-size: 2vw;
                color: black;
            }

            .gris-claro {
                background-color: #C0DEDE;
            }

            .amarillo {
                background-color: #FBB805;
            }

            .gris-oscuro {
                background-color: #7E9898
            }

            table {
                border: 2px solid #ccc;
                border-collapse: collapse;
                margin: 0;
                padding: 0;
                width: 100%;
                table-layout: fixed;
            }

            th,
            td {
                border: 2px solid;
                padding-top: 10px;
                padding-bottom: 20px;
                padding-left: 30px;
                padding-right: 40px;
            }

            .card {
                box-shadow: 0 4px 8px 0 rgba(0, 0, 0, 0.2);
                transition: 0.3s;
                width: 33%;
                height: 200px;
                border-radius: 5px;
            }

            .card:hover {
                box-shadow: 0 8px 16px 0 rgba(0, 0, 0, 0.2);
            }

            /* tr:nth-child(even) {
                background-color: #ADC5C5;
                color: black;
            }*/
        </style>
        <div class="row amarillo">

            <div class="column">
                <img src="/images/Campet-Logo.png" style="width:20%"></img>
            </div>
            <div class="column">
                <img src="/images/Perfil-Logo.png" style="width:60%"></img>
            </div>
            <div class="row">
                <h1 class="titulo">REPORTE POR EMPRESA DE LOS DATOS REGISTRADOS</h1>
            </div>
        </div>

    </head>

    <body>
        <h2 class="titulo2 gris-oscuro">Datos Generales</h2>
        <div class="row" style="overflow-x:auto;">
            <table>
                <tr>
                    <td>Nombre de la Empresa:</td>
                    <td>{{ $datos[0]->nombre }}</td>
                </tr>

                <tr style="background-color: none">
                    <td>Nro. de RIF:</td>
                    <td>{{ $datos[0]->rif }}</td>
                </tr>
                <tr>
                    <td colspan="2" style="padding: 0px; border: none">
                        <table>
                            <tr>
                                <td style="border: none;">Dirección</td>
                                <td style="border: none">{{ $datos[0]->direccion }}</td>
                            </tr>
                            <tr>
                                <td style="border: none; text-align:right">Ciudad / País</td>
                                <td style="border: none">{{ $datos[0]->ciudad }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td>Página Web:</td>
                    <td>{{ $datos[0]->website }}</td>
                </tr>
                <tr>
                    <td>Teléfono:</td>
                    <td>{{ $datos[0]->telefono }}</td>
                </tr>
                <tr>
                    <td>Contactos:</td>
                    <td>
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
                                echo $arr_datos[0] . "<br>";
                                echo $arr_datos[1] . "<br>";
                                echo $arr_datos[2] . "<br>";
                                echo "<hr>";
                        ?> <br>
                        <?php
                            }
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td>Año de Fundación:</td>
                    <td>{{ $datos[0]->fundacion }}</td>
                </tr>
                <tr>
                    <td>Capital de la Empresa:</td>
                    <td>{{ $datos[0]->capital }} / {{ $datos[0]->origen }}</td>
                </tr>
                <tr>
                    <td colspan="2">
                        <table style="border: none;">
                            <tr>
                                <td style="border: none; padding: 2px">Operación en Venezuela</td>
                                <td style="border: none; padding: 2px"></td>
                            </tr>
                            <tr>
                                <td style="border: none; text-align:right; padding: 2px; padding-right:50px">Facturación Anual:</td>
                                <td style="border: none; padding: 2px; padding-left:30px">{{ $datos[0]->facturacion_anual }}</td>
                            </tr>
                            <tr>
                                <td style="border: none; text-align:right;  padding: 2px; padding-right:50px">Empleados:</td>
                                <td style="border: none;  padding: 2px; padding-left:30px">{{ $datos[0]->empleados }}</td>
                            </tr>
                            <tr>
                                <td style="border: none; text-align:right;  padding: 2px; padding-right:50px">Estatus Actual:</td>
                                <td style="border: none;  padding: 2px; padding-left:30px">{{ $datos[0]->estado_actual }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="border: none" colspan="2">Sectores de Actividad Económica y Servicios</td>
                </tr>
                <tr>
                    <td style="border: none">Sector</td>
                    <td style="border: none">Servicios</td>

                </tr>

                <tr>
                    <td style="border: none">
                        <?php
                        $arr = explode(";", $datos[0]->sector);
                        $arr_sectores = explode(",", $arr[0], 20);
                        foreach ($arr_sectores as $value) {
                            echo $value . '<br>';
                            echo '<hr>';
                        }
                        ?>
                    </td>
                    <td style="border: none">
                        <?php
                        $arr = explode(";", $datos[0]->servicios);
                        $arr_servicios = explode(",", $arr[0], 20);
                        foreach ($arr_servicios as $value) {
                            echo $value . '<br>';
                            echo '<hr>';
                        }
                        ?>

                    </td>
                </tr>

                <!-- EXPERIENCIA RELEVANTE.....................................................................-->

        </div>

        <tr>
            <td colspan="2" style="padding: 0px; border: none">
                <h2 class="titulo2 gris-oscuro">Experiencia Relevante</h2>
            </td>
        </tr>
        <?php
        if ($datos_exp) {
            foreach ($datos_exp as $row) {
        ?>
                <tr>
                    <td colspan="2">

                        <!-- PRIMERA TARJETA.................... -->
                        <div class="card" style="display: inline-block; font-size: 12px;">
                            <table>
                                <tr>
                                    <th style="border: none">Año</th>
                                    <th style="border: none" colspan="3">Infraestructura en la que trabajó</th>
                                </tr>
                                <tr>
                                    <td style="border: none">
                                        {{ $row->ano }}
                                    </td>
                                    <td colspan="3" style="padding: 0px; border: none">
                                        <table style="border: none">
                                            <tr>
                                                <td width="30%" style="padding: 0px; border: none">Sector</td>
                                                <td width="70%" style="padding: 0px; border: none">{{ $row->sectorind }}</td>
                                            </tr>

                                            <tr>
                                                <td width="30%" style="padding: 0px; border: none">Tipo</td>
                                                <td width="70%" style="padding: 0px; border: none">{{ $row->tipoind }}</td>
                                            </tr>

                                            <tr>
                                                <td width="30%" style="padding: 0px; border: none">Sistema</td>
                                                <td width="70%" style="padding: 0px; border: none">{{ $row->systemind }}</td>
                                            </tr>
                                            <tr>
                                                <td width="30%" style="padding: 0px; border: none">Región o Distrito</td>
                                                <td width="70%" style="padding: 0px; border: none">{{ $row->regionind }}</td>
                                            </tr>
                                            <tr>
                                                <td width="30%" style="padding: 0px; border: none">Instalación</td>
                                                <td width="70%" style="padding: 0px; border: none">{{ $row->facilityind }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 5px; border: none">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 5px; border: none">&nbsp;</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                            </table>
                        </div>
                        <!-- SEGUNDA TARJETA........................................................... -->
                        <div class="card" style="display: inline-block; font-size: 12px;">
                            <table>
                                <tr>
                                    <th style="border: none; padding: 0px">Magnitud del Contrato</th>
                                    <th style="border: none; padding: 0px" colspan="3">{{ $row->magnitud }}</th>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; border: none">&nbsp;</td>
                                </tr>

                                <tr>
                                    <td style="padding: 5px; border: none">Sector</td>
                                    <td colspan="3" style="padding: 5px; border: none ">{{ $row->exp_sector }}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; border: none">Servicio</td>
                                    <td colspan="3" style="padding: 5px; border: none">{{ substr($row->exp_service, 0, 250) . ' ...' }}</td>
                                </tr>


                            </table>
                        </div>
                        <!-- TERCERA TARJETA.......................................................... -->
                        <div class="card" style="display: inline-block; font-size: 12px;">
                            <table>
                                <tr>
                                    <th style="border: none; padding: 4px" colspan="4">Descripción del Trabajo Realizado:</th>
                                </tr>

                                <tr>
                                    <td style="padding: 5px; border: none">&nbsp;</td>
                                </tr>

                                <tr>
                                    <td colspan="4" style="padding: 4px; border: none">{{ str_replace('null', ' ', substr($row->descripcion, 0, 350)) . ' ...'}}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 0px; border: none">&nbsp;</td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; border: none">&nbsp;</td>
                                </tr>
                            </table>
                        </div>
                    </td>
                </tr>
        <?php
            }
        }
        ?>

        <!-- PRESENCIA INTERNACIONAL.....................................................................-->

        <tr>
            <td colspan="2" style="padding: 0px; border: none">
                <h2 class="titulo2 gris-oscuro">Presencia Internacional</h2>
            </td>
        </tr>


        </table>
        </div>

        </td>

        </table>
        <table>
            <tr>
                <th>País</th>
                <th>Oficinas (Mts2)</th>
                <th>Empleados (n)</th>
                <th>Activa?</th>
            </tr>
            @foreach ($datos_pres as $row)
            <tr>
                <td>{{ $row->pais }}</td>
                <td>{{ $row->mts }}</td>
                <td>{{ $row->emp_q }}</td>
                <td>{{ $row->activa }}</td>
            </tr>
            @endforeach
        </table>



    </body>
</x-filament::page>