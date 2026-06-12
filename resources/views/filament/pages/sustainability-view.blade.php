<x-filament::page>
    <div>
        <section>
            <div class="container flex justify-left mx-auto">
                <div class="flex flex-col">
                    <div>
                        <div class="card-header">
                        </div>
                        <div class="overflow-hidden shadow-sm sm:rounded-lg" style="overflow-x: scroll;">
                            <table>
                                <thead>
                                    <tr class="bg-indigo-400">
                                        <th class="border px-3 py-2 text-sm text-gray-500">ID</th>
                                        <th class="border px-3 py-2 text-xm text-gray-500">NOMBRE</th>
                                        <TH class="border px-3 py-2 text-xm text-gray-500">SECTOR</TH>
                                        <TH class="border px-3 py-2 text-xm text-gray-500">MAXIMIZACIÓN DE LA EFICIENCIA MATERIAL Y ENERGÉTICA</TH>
                                        <TH class="border px-3 py-2 text-xm text-gray-500">CREACIÓN DE VALOR A PARTIR DE LOS DESECHOS</TH>
                                        <TH class="border px-3 py-2 text-xm text-gray-500">USO DE ENERGÍAS RENOVABLES Y PROCESOS NATURALES</TH>
                                        <TH class="border px-3 py-2 text-xm text-gray-500">FUNCIONALIDAD EN VEZ DE PROPIEDAD</TH>
                                        <TH class="border px-3 py-2 text-xm text-gray-500">PARTICIPACIÓN PROACTIVA CON LOS STAKEHOLDERS</TH>
                                        <TH class="border px-3 py-2 text-xm text-gray-500">FOMENTO DE LA SUFICIENCIA</TH>
                                        <TH class="border px-3 py-2 text-xm text-gray-500">REORIENTACIÓN DEL OBJETO POR Y PARA LA SOCIEDAD O EL AMBIENTE</TH>
                                        <TH class="border px-3 py-2 text-xm text-gray-500">DESARROLLO DE SOLUCIONES A ESCALA</TH>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\SustainabilityViewModel::get() as $sustainabilities)
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $sustainabilities->id }} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $sustainabilities->name}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $sustainabilities->Sector}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $sustainabilities->Maximizacion}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $sustainabilities->Creacion}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $sustainabilities->Energias}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $sustainabilities->Funcionalidad}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $sustainabilities->Participacion}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $sustainabilities->Fomento}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $sustainabilities->Reorientacion}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $sustainabilities->Desarrollo}} </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</x-filament::page>