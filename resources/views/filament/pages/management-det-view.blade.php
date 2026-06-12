<x-filament::page>
    <div>
        <section>
            <div class="container flex justify-left mx-auto overflow-x-auto ">
                <div class="flex flex-col">
                    <div>
                        <div class="shadow-sm sm:rounded-lg">
                            <table>
                                <thead>
                                    <tr class="bg-indigo-400">
                                        <th colspan="2" class="border px-3 py-2 text-sm text-gray-500"></th>
                                        <th colspan="3" class="border px-6 py-2 text-sm text-gray-500">Calidad</th>
                                        <th colspan="3" class="border px-6 py-2 text-sm text-gray-500">Ambiente</th>
                                        <TH colspan="3" class="border px-6 py-2 text-sm text-gray-500">Credibilidad y Transparencia</TH>
                                        <TH colspan="3" class="border px-6 py-2 text-sm text-gray-500">Seguridad</TH>
                                        <TH colspan="2" class="border px-6 py-2 text-sm text-gray-500">Gestión de Proyectos</TH>
                                        <TH colspan="2" class="border px-6 py-2 text-sm text-gray-500">Seguridad de la Información</TH>
                                    </tr>
                                    <tr class="bg-indigo-400">
                                        <th class="border px-3 py-2 text-sm text-gray-500">ID</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">NOMBRE</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">ISO9001</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">ISO17025</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CALIDAD: OTROS</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">ISO14001</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">ISO50001</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">AMBIENTE: OTROS</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">DUN & BRADSTREET</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">ISO37001</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CREDIBILIDAD: OTROS</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">ISO45001</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">COVID</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">SEGURIDAD: OTROS</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">PMI</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">PMI: OTROS</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">SISTEMAS DE GESTIÓN DE SEGURIDAD DE LA INFORMACIÓN</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">SISTEMAS DE SEGURIDAD: OTROS</TH>

                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\ManagementDetView::get() as $managementdet)
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $managementdet->id}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->name}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->iso9001}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->iso17025}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->QUALITY_OTROS}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->iso14001}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->iso50001}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->ENVIRONMENT_OTROS}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->dun}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->iso37001}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->CREDIBILITY_OTROS}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->iso45001}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->ovid}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->SECURITY_OTROS}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->pmi}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->PMI_OTROS}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->iso27001}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $managementdet->INFO_OTROS}} </td>
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