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
                                    <tr class="bg-indigo-400">
                                        <th class="border px-3 py-2 text-sm text-gray-500">ID</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">NOMBRE</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">SECTOR</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">CANT. EMPLEADOS</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">FACTURACION EN VENEZUELA</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">ESTADO ACTUAL</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">CAPITAL</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">ORIGEN</TH>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\FinanceViewModel::get() as $finances)
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $finances->id }} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $finances->name}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $finances->Sector}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $finances->rrhh}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $finances->BILLING}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $finances->ESTADO}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $finances->CAPITAL}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $finances->ORIGEN}} </td>
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