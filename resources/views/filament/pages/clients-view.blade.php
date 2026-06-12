<x-filament::page>
    <div>
        <section>
            <div class="container flex justify-center mx-auto">
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
                                        <TH class="border px-6 py-2 text-sm text-gray-500">CLIENTE</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">PAÍS</TH>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\ClientsViewModel::get() as $clients)
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $clients->id }} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $clients->name}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $clients->Sector}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $clients->cliente}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $clients->pais}} </td>
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