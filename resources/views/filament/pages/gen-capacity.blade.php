<x-filament::page>
    <div>
        <section>
            <div class="container flex justify-left mx-auto">
                <div class="flex flex-col">
                    <div>
                        <div class="card-header">
                        </div>
                        <div class="overflow-hidden shadow-sm sm:rounded-lg">
                            <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.1.1/jquery.min.js"></script>
                            <table>
                                <thead>
                                    <tr class="bg-indigo-400">
                                        <th class="border px-3 py-2 text-xm text-gray-500">ID</th>
                                        <th class="border px-6 py-2 text-xm text-gray-500">NOMBRE</th>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">SECTOR</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">SERVICIOS</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">TOTAL RRHH</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">CANT. INSTALACIONES</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">CANT. MAQUINARIA</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">VALOR DE INVENTARIO</TH>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\GenCapacity::get() as $capacidades)
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $capacidades->id }} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $capacidades->name}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $capacidades->Sector}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $capacidades->Servicios}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ number_format($capacidades->rrhh) }} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ number_format($capacidades->instalaciones)}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $capacidades->maquinaria}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $capacidades->inventario }} </td>
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