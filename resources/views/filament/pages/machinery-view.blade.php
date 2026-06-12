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
                                        <th colspan="2" class="border px-6 py-2 text-sm text-gray-500">Equipos de medición, levantamiento (survey)</th>
                                        <th colspan="2" class="border px-6 py-2 text-sm text-gray-500">Equipos marino-costeros fluviales o costa afuera</th>
                                        <TH colspan="2" class="border px-6 py-2 text-sm text-gray-500">Movimiento de tierra y construcción</TH>
                                        <TH colspan="2" class="border px-6 py-2 text-sm text-gray-500">Equipos menores de construcción</TH>
                                        <TH colspan="2" class="border px-6 py-2 text-sm text-gray-500">Fabricación metalmecánica / electromecánica / electrónica</TH>
                                        <TH colspan="2" class="border px-6 py-2 text-sm text-gray-500">Montaje eléctrico/mecánico</TH>
                                        <TH colspan="2" class="border px-6 py-2 text-sm text-gray-500">Máquinas herramientas / Metalmecánica</TH>
                                        <TH colspan="2" class="border px-6 py-2 text-sm text-gray-500">Almacenamiento y transporte</TH>
                                        <TH colspan="2" class="border px-6 py-2 text-sm text-gray-500">Servicios a pozos e instalaciones petroleras</TH>
                                    </tr>
                                    <tr class="bg-indigo-400">
                                        <th class="border px-3 py-2 text-sm text-gray-500">ID</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">NOMBRE</th>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">VALOR ESTIMADO</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">VALOR ESTIMADO</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">VALOR ESTIMADO</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">VALOR ESTIMADO</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">VALOR ESTIMADO</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">VALOR ESTIMADO</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">VALOR ESTIMADO</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">VALOR ESTIMADO</TH>
                                        <th class="border px-6 py-2 text-sm text-gray-500">CANTIDAD</th>
                                        <TH class="border px-6 py-2 text-sm text-gray-500">VALOR ESTIMADO</TH>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (App\Models\MachineryView::get() as $machinery)
                                    <tr class="even:bg-amber-100 odd:bg-blue-100">
                                        <td class="border px-3 py-4 text-sm nowrap"> {{ $machinery->id}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->name}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Equip_med_lev_qua}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Equip_med_lev_est}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Equip_mar_flu_qua}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Equip_mar_flu_est}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Mov_terr_cons_qua}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Mov_terr_cons_est}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Equip_men_cons_qua}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Equip_men_cons_est}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Fab_metal_elec_qua}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Fab_metal_elec_est}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Mont_elec_meca_qua}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Mont_elec_meca_est}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Maq_herr_meca_qua}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Maq_herr_meca_est}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Almac_trans_qua}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Almac_trans_est}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Serv_poz_inst_qua}} </td>
                                        <td class="border px-3 py-4 text-sm"> {{ $machinery->Serv_poz_inst_est}} </td>
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