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
                                        <th colspan="2" class="border px-6 py-2 text-sm text-gray-500">¿Tiene presencia formal en otros países?</th>
                                        <th colspan="3" class="border px-3 py-2 text-sm text-gray-500"></th>
                                        <th colspan="2" class="border px-6 py-2 text-sm text-gray-500">¿Tiene experiencia desarrollando proyectos en otros países?</th>
                                        <th colspan="7" class="border px-3 py-2 text-sm text-gray-500"></th>
                                    </tr>
                                    <tr class="bg-indigo-400">
                                        <th class="border px-3 py-2 text-xm text-gray-500">ID</th>
                                        <th class="border px-6 py-2 text-xm text-gray-500">NOMBRE</th>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">NO</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">SÍ</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">PÁÍS</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">OFICINAS (Mts2)</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">EMPLEADOS (No.)</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">ESTATUS ACTUAL</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">NO</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">SÍ</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">PAÍS</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">N° DE PROYECTOS</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">ROL</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">MONTO TOTAL EJECUTADO</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">EMPLEADOS</TH>
                                        <TH class="border px-6 py-2 text-xm text-gray-500">PRINCIPALES CLIENTES</TH>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\PresenceViewModel::get() as $presence)
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->id }} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->name}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->hasOfficesNo}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->hasOfficesYes}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $presence->pais}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->mts}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->emp_q}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->activa}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->hasExperienceNo}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->hasExperienceYes}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->paisx}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->proj_q}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->role}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->montox}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->expemployees}} </td>
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $presence->clients}} </td>
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