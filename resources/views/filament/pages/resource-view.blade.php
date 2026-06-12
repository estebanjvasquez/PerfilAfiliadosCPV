<x-filament::page>
    <div>
        <section>
            <div class="container flex justify-left mx-auto overflow-x-auto">
                <div class="flex flex-col">
                    <div>
                        <div class="shadow-sm sm:rounded-lg">
                            <table style="overflow-x: scroll">
                                <thead>
                                    <tr class="bg-indigo-400">
                                        <th class="border px-3 py-2 text-sm text-gray-500">ID</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">NOMBRE</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">BACHILLERES JUNIOR</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">BACHILLERES MEDIUM</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">BACHILLERES SENIOR</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">TÉCNICOS JUNIOR</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">TÉCNICOS MEDIUM</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">TÉCNICOS SENIOR</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">INGENIEROS JUNIOR</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">INGENIEROS MEDIUM</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">INGENIEROS SENIOR</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">ADMINISTRATIVOS JUNIOR</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">ADMINISTRATIVOS MEDIUM</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">ADMINISTRATIVOS SENIOR</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">GERENTES JUNIOR</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">GERENTES MEDIUM</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">GERENTES SENIOR</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">DIRECTIVOS JUNIOR</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">DIRECTIVOS MEDIUM</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">DIRECTIVOS SENIOR</TH>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">TOTAL</TH>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\ResourceView::get() as $resources)
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $resources->id}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->name}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Bachilleres_Junior}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Bachilleres_Medium}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Bachilleres_Senior}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Tecnicos_Junior}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Tecnicos_Medium}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Tecnicos_Senior}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Ingenieros_Junior}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Ingenieros_Medium}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Ingenieros_Senior}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Administrativos_Junior}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Administrativos_Medium}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Administrativos_Senior}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Gerentes_Junior}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Gerentes_Medium}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Gerentes_Senior}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Directivos_Junior}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Directivos_Medium}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Directivos_Senior}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $resources->Total}} </td>
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