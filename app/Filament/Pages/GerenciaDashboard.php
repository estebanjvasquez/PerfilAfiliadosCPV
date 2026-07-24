<?php

namespace App\Filament\Pages;

use App\Filament\Pages\GerenciaDashboard\Widgets\AlcanceInternacionalWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\CalidadPerfilWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\CapitalWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\CapitulosWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\CertificacionesWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\CoberturaServiciosWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\CrecimientoWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\DiversificacionWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\EmpleoWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\EmpresasEstancadasWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\FacturacionWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\GeografiaWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\NoAplicaWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\RecursosRadarWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\ResumenStatsWidget;
use App\Filament\Pages\GerenciaDashboard\Widgets\TopSectoresWidget;
use App\Filament\Support\GerenciaMetrics;
use App\Models\Chamber;
use App\Models\Sector;
use App\Models\State;
use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Actions\Action;
use Filament\Pages\Page;

class GerenciaDashboard extends Page
{
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Tablero Gerencial';

    protected static ?string $title = 'Tablero de Métricas Gerenciales';

    protected static ?string $slug = 'gerencia-dashboard';

    protected static ?string $navigationGroup = 'Gerencia';

    protected static string $view = 'filament.pages.gerencia-dashboard';

    public function getSectores()
    {
        return Sector::orderBy('name')->pluck('name', 'id');
    }

    public function getCamaras()
    {
        return Chamber::orderBy('name')->pluck('name', 'id');
    }

    public function getEstados()
    {
        return State::orderBy('state_name')->pluck('state_name', 'id');
    }

    public function getFiltros(): array
    {
        return GerenciaMetrics::filtersFromRequest();
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ResumenStatsWidget::class,
            CalidadPerfilWidget::class,
            NoAplicaWidget::class,
            EmpresasEstancadasWidget::class,
            TopSectoresWidget::class,
            CoberturaServiciosWidget::class,
            DiversificacionWidget::class,
            EmpleoWidget::class,
            FacturacionWidget::class,
            CapitalWidget::class,
            RecursosRadarWidget::class,
            CertificacionesWidget::class,
            AlcanceInternacionalWidget::class,
            CrecimientoWidget::class,
            GeografiaWidget::class,
            CapitulosWidget::class,
        ];
    }

    protected function getHeaderWidgetsColumns(): int | array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 3];
    }

    protected function getActions(): array
    {
        return [
            Action::make('Descargar PDF')->action('exportPdf'),
        ];
    }

    public function exportPdf()
    {
        $filters = GerenciaMetrics::filtersFromRequest();

        return Pdf::loadView('pdf.gerencia-dashboard', [
            'resumen' => GerenciaMetrics::resumen($filters),
            'calidadPerfil' => GerenciaMetrics::calidadPerfil($filters),
            'noAplica' => GerenciaMetrics::noAplicaPorModulo($filters),
            'topSectores' => GerenciaMetrics::topSectores($filters),
            'coberturaServicios' => GerenciaMetrics::coberturaServicios($filters),
            'diversificacion' => GerenciaMetrics::diversificacionSectorial($filters),
            'empleo' => GerenciaMetrics::empleoPorRango($filters),
            'facturacion' => GerenciaMetrics::facturacionPorRango($filters),
            'capital' => GerenciaMetrics::composicionCapital($filters),
            'coberturaRecursos' => GerenciaMetrics::coberturaRecursos($filters),
            'certificaciones' => GerenciaMetrics::certificaciones($filters),
            'alcanceInternacional' => GerenciaMetrics::alcanceInternacional($filters),
            'crecimiento' => GerenciaMetrics::crecimientoAfiliacion($filters),
            'geografia' => GerenciaMetrics::distribucionGeografica($filters),
            'camaras' => GerenciaMetrics::distribucionCamaras($filters),
        ])->download('tablero-gerencial-' . now()->format('Y-m-d') . '.pdf');
    }
}
