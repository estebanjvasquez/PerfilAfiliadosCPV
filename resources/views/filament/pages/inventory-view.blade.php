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
                                        <th colspan="3" class="border px-6 py-2 text-sm text-gray-500">Materia Prima</th>
                                        <th colspan="3" class="border px-6 py-2 text-sm text-gray-500">Producto Terminado</th>
                                    </tr>
                                    <tr class="bg-indigo-400">
                                        <th class="border px-3 py-2 text-sm text-gray-500">ID</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">NOMBRE</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">VALOR ESTIMADO</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">UNIDAD</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">VALOR ESTIMADO</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">UNIDAD</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\InventoryView::get() as $inventory)
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $inventory->id}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $inventory->name}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $inventory->Materia_q}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $inventory->Materia_est}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $inventory->Materia_unit}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $inventory->Producto_q}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $inventory->Producto_est}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $inventory->Producto_unit}} </td>
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