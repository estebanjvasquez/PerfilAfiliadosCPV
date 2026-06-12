<?php

namespace App\Filament\Pages;


use Filament\Forms;
use App\Models\Area;
use App\Exports\Catalog;
use Filament\Pages\Page;
use App\Models\FacilityView;
use App\Models\ResourceView;
use Illuminate\Http\Request;
use App\Models\MachineryView;
use App\Models\InventoryView;
use Forms\Components\Actions;
use App\Models\JoinViewsModel;
use App\Models\Sustainability;
use App\Exports\JoinViewExport;
use App\Models\chamber_empresa;
use App\Models\PresenceViewModel;
use Filament\Pages\Actions\Action;
use App\Models\ExperienceViewModel;
use Illuminate\Support\Facades\App;
use Maatwebsite\Excel\Facades\Excel;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

use App\Models\Sector;


class JoinViews extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.join-views';

    protected static ?string $navigationLabel = 'Join Views';

    protected static ?string $title = '';

    protected static ?string $slug = 'join-views';

    protected static bool $shouldRegisterNavigation = false;

    use HasPageShield;

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllJoinViewsXls'),
            Action::make('Pdf')->action('exportAllCatalogPdf'),
        ];
    }

    public function exportAllJoinViewsXls()
    {
        return Excel::download(new JoinViewExport, 'join-views.xlsx');
    }

    //CONSULTA PARA TODOS LOS DATOS EXCEPTO LOS RELACIONADOS A LAS CONSULTAS MÁS ABAJO......................
    public static function index($a)
    {
        $data = JoinViewsModel::leftjoin('catalogoView', 'catalogoView.id', '=', 'capacityView.id')
            ->leftjoin('ClientsView', 'ClientsView.id', '=', 'capacityView.id')
            ->leftjoin('FinanceView', 'FinanceView.id', '=', 'capacityView.id')
            ->leftjoin('ManagementDetView', 'ManagementDetView.id', '=', 'capacityView.id')
            ->where('capacityView.id', '=', $a)
            ->get([
                'catalogoView.name as nombre', 'catalogoView.rif as rif', 'catalogoView.street as direccion',
                'catalogoView.CIUDAD as ciudad', 'catalogoView.website', 'catalogoView.phone as telefono', 'catalogoView.CONTACTOS as contactos',
                'catalogoView.fundacion', 'FinanceView.CAPITAL as capital', 'FinanceView.ORIGEN as origen',
                'FinanceView.BILLING as facturacion_anual', 'FinanceView.ESTADO as estado_actual', 'FinanceView.rrhh as empleados',
                'capacityView.Sector as sector', 'capacityView.Servicios as servicios',
                'capacityView.instalaciones as inst', 'ClientsView.cliente as clientes', 'ClientsView.pais as cli_pais',
                'ManagementDetView.iso9001', 'ManagementDetView.iso17025', 'ManagementDetView.QUALITY_OTROS as quality_otros',
                'ManagementDetView.iso14001', 'ManagementDetView.iso50001', 'ManagementDetView.ENVIRONMENT_OTROS as environment_otros',
                'ManagementDetView.dun', 'ManagementDetView.iso37001', 'ManagementDetView.CREDIBILITY_OTROS as credibility_otros',
                'ManagementDetView.iso45001', 'ManagementDetView.ovid', 'ManagementDetView.SECURITY_OTROS as security_otros',
                'ManagementDetView.pmi', 'ManagementDetView.PMI_OTROS as pmi_otros', 'ManagementDetView.iso27001',
                'ManagementDetView.INFO_OTROS as info_otros',

            ]);

        return view('filament.pages.join-views', compact('data'));
    }

    //SECTORES Y SERVICIOS.......................................................................
    public static function index_sec_ser($a)
    {
        $data_sec_ser = Sector::leftjoin('services', 'services.sectors_id', '=', 'sectors.id')
            ->leftjoin('empresa_sector_service', 'services.id', '=', 'empresa_sector_service.service_id')

            ->where('empresa_sector_service.empresa_id', '=', $a)
            ->groupBy('sectors.name', 'services.name')
            ->orderBy('sectors.name')
            ->get(['sectors.name as sector', 'services.name as servicio']);

        return view('filament.pages.join-views', compact('data_sec_ser'));
    }

    //EXPERIENCIA RELEVANTE......................................................................
    public static function index_exp($a)
    {
        $data_exp = ExperienceViewModel::where('ExperienceView.id', '=', $a)->orderby('ExperienceView.ano')
            ->get([
                'ExperienceView.ano as ano',
                'ExperienceView.sectorind as sectorind', 'ExperienceView.tipoind as tipoind', 'ExperienceView.systemind',
                'ExperienceView.regionind', 'ExperienceView.facilityind', 'ExperienceView.magnitud',
                'ExperienceView.prof_tech', 'manpower',
                'ExperienceView.sector as exp_sector', 'ExperienceView.service as exp_service', 'ExperienceView.descripcion',
            ]);
        return view('filament.pages.join-views', compact('data_exp'));
    }

    //CAMARAS.................................................................
    public static function index_chambers($a)
    {
        $data_chambers = chamber_empresa::join('chambers', 'chamber_empresa.chamber_id', '=', 'chambers.id')
            ->where('chamber_empresa.empresa_id', '=', $a)
            ->get([
                'chamber_empresa.empresa_id', 'chambers.name'
            ]);
        return view('filament.pages.join-views', compact('data_chambers'));
    }


    //PRESENCIA INTERNACIONAL....................................................................
    public static function index_pres($a)
    {
        $data_pres = PresenceViewModel::where('PresenceView.id', '=', $a)->where('PresenceView.hasOfficesYes', '=', 'X')
            ->get([
                'PresenceView.hasOfficesYes', 'PresenceView.hasExperienceYes', 'PresenceView.pais as pais',
                'PresenceView.mts', 'PresenceView.emp_q', 'PresenceView.activa',
                'PresenceView.paisx', 'PresenceView.proj_q', 'PresenceView.role', 'PresenceView.montox',
                'PresenceView.expemployees', 'PresenceView.clients'
            ]);
        return view('filament.pages.join-views', compact('data_pres'));
    }

    //ENFOQUE A SOSTENIBILIDAD.................................................................
    public static function index_sust($a)
    {
        $data_sust = Sustainability::join('areas', 'areas.id', '=', 'sustainabilities.areas_id')
            ->where('sustainabilities.empresa_id', '=', $a)
            ->get([
                'areas.id', 'areas.sust_title', 'areas.sust_description', 'sustainabilities.sust_status'
            ]);
        return view('filament.pages.join-views', compact('data_sust'));
    }

    public static function index_recursos($a)
    {
        $titles = json_decode(file_get_contents(storage_path() . "/tituloshr.json"), true);
        $value_emp = $titles['personal'];
        $value_maq = $titles['maquinaria'];
        $value_fac = $titles['instalaciones'];
        $value_inv = $titles['inventario'];

        $data_rec = ResourceView::where('ResourceView.id', '=', $a)
            ->get(
                [
                    'Bachilleres_Junior', 'Bachilleres_Medium', 'Bachilleres_Senior', 'Tecnicos_Junior', 'Tecnicos_Medium', 'Tecnicos_Senior',
                    'Ingenieros_Junior', 'Ingenieros_Medium', 'Ingenieros_Senior', 'Administrativos_Junior', 'Administrativos_Medium', 'Administrativos_Senior',
                    'Gerentes_Junior', 'Gerentes_Medium', 'Gerentes_Senior', 'Directivos_Junior', 'Directivos_Medium', 'Directivos_Senior',
                    'Total'
                ]
            );

        $data_maq = MachineryView::where('MachineryView.id', '=', $a)
            ->get(
                [
                    'Equip_med_lev_est', 'Equip_mar_flu_qua', 'Equip_mar_flu_est', 'Equip_med_lev_qua', 'Mov_terr_cons_qua', 'Mov_terr_cons_est',
                    'Equip_men_cons_qua', 'Equip_men_cons_est', 'Fab_metal_elec_qua', 'Fab_metal_elec_est', 'Mont_elec_meca_qua',
                    'Mont_elec_meca_est', 'Maq_herr_meca_qua', 'Maq_herr_meca_est', 'Almac_trans_qua', 'Almac_trans_est', 'Serv_poz_inst_qua', 'Serv_poz_inst_est'
                ]
            );

        $data_ins = FacilityView::where('FacilityView.id', '=', $a)
            ->get(
                [
                    'Oficinas_q', 'Oficinas_surf', 'Oficinas_own', 'Talleres_q', 'Talleres_surf', 'Talleres_own',
                    'Manufactura_q', 'Manufactura_surf', 'Manufactura_own', 'Almacenes_q', 'Almacenes_surf', 'Almacenes_own',
                    'Laboratorios_q', 'Laboratorios_surf', 'Laboratorios_own', 'Marinas_q', 'Marinas_surf', 'Marinas_own',
                    'Otros_q', 'Otros_surf', 'Otros_own'
                ]
            );

        $data_inv = InventoryView::where('InventoryView.id', '=', $a)
            ->get(
                [
                    'Materia_q', 'Materia_est', 'Materia_unit', 'Producto_q', 'Producto_est', 'Producto_unit'
                ]
            );

        return view('filament.pages.join-views', compact('value_emp', 'value_maq', 'value_fac', 'value_inv', 'data_rec', 'data_maq', 'data_ins', 'data_inv'));
    }
}
