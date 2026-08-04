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
                                        <TH class="border px-6 py-2 text-xm text-gray-500">CALIDAD</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">AMBIENTE</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">SEGURIDAD</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">GESTION DE PROYECTOS</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">CREDIBILIDAD Y TRANSPARENCIA</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">SEGURIDAD DE LA INFORMACION</TH>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\ManagementView::get() as $managements)
                                    @php
                                        $esModuloCompleto = isset($noAplicaWholeIds[$managements->id]);
                                        $subTiposNA = $noAplicaSubTypesByEmpresa->get($managements->id, []);
                                        $marca = fn (string $subType, $valor) => ($esModuloCompleto || in_array($subType, $subTiposNA))
                                            ? \App\Models\EmpresaModuleStatus::NO_APLICA_LABEL_LARGO
                                            : $valor;
                                    @endphp
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $managements->id }} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $managements->name}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $managements->Sector}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $marca('calidad', $managements->Calidad)}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $marca('ambiente', $managements->Ambiente)}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $marca('seguridad', $managements->Seguridad)}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $marca('proyectos', $managements->Gestion)}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $marca('credibilidad', $managements->Credibilidad)}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $marca('seguridad_info', $managements->Informacion)}} </td>
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