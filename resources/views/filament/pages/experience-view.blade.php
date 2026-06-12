<x-filament::page>
    <div>
        <section>
            <div class="container flex justify-left mx-auto">
                <div class="flex flex-col">
                    <div>
                        <div class="card-header">
                        </div>
                        <div class="overflow-hidden shadow-sm sm:rounded-lg">
                            <table>
                                <thead>
                                    <style>
                                        table th {
                                            position: -webkit-sticky;
                                            position: sticky;
                                            top: 0;
                                            z-index: 1;

                                        }
                                    </style>
                                    <tr class="bg-indigo-400">
                                        <th class="position: sticky border px-3 py-2 text-sm text-gray-500">ID</th>
                                        <th class="position: sticky border px-6 py-2 text-sm text-gray-500">NOMBRE</th>
                                        <TH class="position: sticky border px-6 py-2 text-sm text-gray-500">SECTOR INDUSTRIAL</TH>
                                        <TH class="position: sticky border px-6 py-2 text-sm text-gray-500">TIPO</TH>
                                        <TH class="position: sticky border px-6 py-2 text-sm text-gray-500">SISTEMA</TH>
                                        <TH class="position: sticky border px-6 py-2 text-sm text-gray-500">REGION</TH>
                                        <TH class="position: sticky border px-6 py-2 text-sm text-gray-500">INSTALACIÓN</TH>
                                        <TH class="position: sticky border px-6 py-2 text-sm text-gray-500">SECTOR</TH>
                                        <TH class="position: sticky border px-6 py-2 text-sm text-gray-500">SERVICIO</TH>
                                        <TH class="position: sticky border px-6 py-2 text-sm text-gray-500">AÑO</TH>
                                        <TH class="position: sticky border px-6 py-2 text-sm text-gray-500">MAGNITUD DEL CONTRATO</TH>
                                        <TH class="position: sticky border px-6 py-2 text-sm text-gray-500">PROF. Y TECNICOS</TH>
                                        <TH class="position: sticky border px-6 py-2 text-sm text-gray-500">MANO DE OBRA</TH>
                                        <!--<TH class="position: sticky border px-6 py-2 text-sm text-gray-500">BREVE DESCRIPCION</TH>-->
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\ExperienceViewModel::get() as $experiences)
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $experiences->id }} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $experiences->name}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $experiences->sectorind}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $experiences->tipoind}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $experiences->systemind}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $experiences->regionind}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $experiences->facilityind}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $experiences->sector}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $experiences->service}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $experiences->ano}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $experiences->magnitud}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $experiences->prof_tech}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $experiences->manpower}} </td>
                                        <!--<td class="border px-3 py-4 text-sm"> {{ $experiences->descripcion}} </td>-->
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