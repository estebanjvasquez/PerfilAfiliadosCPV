<?php

namespace App\Providers;

use App\Models\Asset;
use App\Models\Management;
use App\Models\EmpresaModuleStatus;
use App\Models\Presence;
use App\Models\Experience;
use App\Models\Sustainability;
use App\Models\TaxonomyCategory;
use App\Observers\EmpresaCompletionObserver;
use App\Observers\TaxonomyCategoryObserver;
use App\Services\Taxonomy\IntentContaminationDetector;
use Filament\Facades\Filament;

use Illuminate\Support\Facades\Schema;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\ServiceProvider;
use Filament\Navigation\NavigationGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;



class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Phase 3: IntentContaminationDetector recibe su vocabulario de marcadores por
        // constructor (testeable con fixtures sintéticos, sin depender de la BD) - en producción
        // se resuelve siempre desde taxonomy_intent_markers (vocabulario gobernado, sección 2/13
        // del pedido de Phase 3).
        $this->app->bind(IntentContaminationDetector::class, fn () => IntentContaminationDetector::fromGovernedVocabulary());

        // TASK-0016: proveedor de IA y buscador de fuentes del análisis de solicitudes, por configuración. Hoy solo
        // existe `disabled` (no llama a nadie): un proveedor real se agrega aquí cuando el propietario autorice cuál,
        // su clave y su presupuesto. Un nombre desconocido cae a `disabled`, nunca a un servicio de pago.
        $this->app->bind(\App\Services\CategoryAnalysis\Contracts\AnalysisProvider::class, fn () => match (config('category_analysis.provider')) {
            default => new \App\Services\CategoryAnalysis\DisabledAnalysisProvider,
        });
        $this->app->bind(\App\Services\CategoryAnalysis\Contracts\SourceRetriever::class, fn () => match (config('category_analysis.sources')) {
            default => new \App\Services\CategoryAnalysis\DisabledSourceRetriever,
        });

        // TASK-0014: una instancia por petición, así los motivos de coincidencia de una página del
        // listado se calculan una sola vez y nunca sobreviven a la petición.
        $this->app->scoped(\App\Services\Taxonomy\TaxonomyLookup::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Portado de feature/supplhi-postgres-buscador: mantiene
        // empresas.completion_percentage (cache de Empresa::completionPercentage(), ver
        // migracion 2026_09_01_130000) al dia cuando cambia cualquier dato que afecte el
        // calculo. No cubre servicios/contactos (belongsToMany, ver hooks ->after() en
        // ServicesRelationManager/ContactsRelationManager) porque un attach()/detach() de
        // pivote no dispara eventos de modelo.
        Asset::observe(EmpresaCompletionObserver::class);
        Management::observe(EmpresaCompletionObserver::class);
        EmpresaModuleStatus::observe(EmpresaCompletionObserver::class);
        Presence::observe(EmpresaCompletionObserver::class);
        Experience::observe(EmpresaCompletionObserver::class);
        Sustainability::observe(EmpresaCompletionObserver::class);

        // Mantiene level/path de la taxonomia CPV sincronizados con parent_id/code y previene
        // ciclos (ver app/Observers/TaxonomyCategoryObserver.php).
        TaxonomyCategory::observe(TaxonomyCategoryObserver::class);

        /*  Page::$reportValidationErrorUsing = function (ValidationException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();
        }; */

        /*  Schema::defaultStringLength(191);

        Filament::serving(function () {
            Filament::registerNavigationGroups([
                NavigationGroup::make()
                    ->label('Maintenance')
                    ->icon('heroicon-o-pencil-square')
                    ->collapsed(),

                NavigationGroup::make()
                    ->label('Settings')
                    ->icon('heroicon-s-cog')


            ]);
        });

        NavigationItem::make('Maintenance')
            ->url('https://filament.pirsch.io', shouldOpenInNewTab: true)
            ->icon('heroicon-o-presentation-chart-line')
            ->group('MAINTENANCE')
            ->sort(4); */
    }
}
