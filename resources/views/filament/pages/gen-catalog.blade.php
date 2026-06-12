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
                                        <th class="border px-3 py-2 text-xm text-gray-500">ID</th>
                                        <th class="border px-6 py-2 text-xm text-gray-500">NOMBRE</th>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">SECTOR</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">DIRECCIÓN</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">CIUDAD</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">AÑO DE FUNDACIÓN</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">RIF</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">TELEFONO</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">WEBSITE</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">CONTACTOS</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">CÁMARAS</TH>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\GenCatalog::get() as $catalogo)
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $catalogo->id }} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $catalogo->name}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $catalogo->Sector}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $catalogo->street}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $catalogo->CIUDAD}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $catalogo->fundacion}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $catalogo->rif}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $catalogo->phone}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $catalogo->website}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $catalogo->CONTACTOS}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $catalogo->camara}} </td>
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