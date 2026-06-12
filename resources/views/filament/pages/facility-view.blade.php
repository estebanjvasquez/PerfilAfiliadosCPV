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
                                        <th colspan="3" class="border px-6 py-2 text-sm text-gray-500">Oficinas</th>
                                        <th colspan="3" class="border px-6 py-2 text-sm text-gray-500">Talleres</th>
                                        <TH colspan="3" class="border px-6 py-2 text-sm text-gray-500">Manufactura /fabrica industrial</TH>
                                        <TH colspan="3" class="border px-6 py-2 text-sm text-gray-500">Almacenes y depósitos</TH>
                                        <TH colspan="3" class="border px-6 py-2 text-sm text-gray-500">Laboratorios</TH>
                                        <TH colspan="3" class="border px-6 py-2 text-sm text-gray-500">Facilidades marinas, muelles, etc</TH>
                                        <TH colspan="3" class="border px-6 py-2 text-sm text-gray-500">Otros</TH>
                                    </tr>
                                    <tr class="bg-indigo-400">
                                        <th class="border px-3 py-2 text-sm text-gray-500">ID</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">NOMBRE</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">Mts²</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">PROPIEDAD</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">Mts²</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">PROPIEDAD</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">Mts²</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">PROPIEDAD</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">Mts²</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">PROPIEDAD</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">Mts²</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">PROPIEDAD</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">Mts²</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">PROPIEDAD</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">Mts²</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">PROPIEDAD</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\FacilityView::get() as $facility)
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $facility->id}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->name}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Oficinas_q}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Oficinas_surf}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Oficinas_own}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Talleres_q}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Talleres_surf}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Talleres_own}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Manufactura_q}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Manufactura_surf}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Manufactura_own}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Almacenes_q}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Almacenes_surf}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Almacenes_own}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Laboratorios_q}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Laboratorios_surf}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Laboratorios_own}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Marinas_q}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Marinas_surf}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Marinas_own}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Otros_q}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Otros_surf}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $facility->Otros_own}} </td>
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