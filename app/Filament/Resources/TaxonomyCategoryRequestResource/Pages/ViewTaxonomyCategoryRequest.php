<?php

namespace App\Filament\Resources\TaxonomyCategoryRequestResource\Pages;

use App\Filament\Resources\TaxonomyCategoryRequestResource;
use App\Models\TaxonomyCategoryRequestAnalysis;
use App\Services\CategoryAnalysis\CategoryAnalysisException;
use App\Services\CategoryAnalysis\CategoryAnalysisService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class ViewTaxonomyCategoryRequest extends ViewRecord
{
    protected static string $resource = TaxonomyCategoryRequestResource::class;

    public const ANALYZE_LABEL = 'Analizar con IA';

    public function getTitle(): string
    {
        return 'Solicitud de categoría #'.$this->getRecord()->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->analyzeAction(),
            TaxonomyCategoryRequestResource::configureRetry(Action::make('reintentarAviso'))
                // Tras el reintento el estado y el historial cambian: se recarga el registro.
                ->after(fn () => $this->getRecord()->refresh()),
        ];
    }

    /**
     * TASK-0016: el administrador elige una o varias categorías VIGENTES del catálogo y pide el análisis. La IA solo
     * asesora: el resultado queda en el historial de esta página y la solicitud no cambia. El servicio vuelve a
     * comprobar el rol, la selección y los límites.
     */
    private function analyzeAction(): Action
    {
        $service = app(CategoryAnalysisService::class);

        return Action::make('analizarConIa')
            ->label(self::ANALYZE_LABEL)
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->visible(fn () => Auth::user()?->can('analyzeWithAi', $this->getRecord()) ?? false)
            ->disabled(fn () => ! $service->enabled())
            ->tooltip(fn () => $service->enabled() ? null : TaxonomyCategoryRequestAnalysis::ERRORS['provider_disabled'].' Se configura en «Configuración del análisis con IA».')
            ->modalHeading(fn () => 'Analizar con IA la solicitud #'.$this->getRecord()->getKey())
            ->modalDescription('La IA compara lo que pidió el afiliado con las categorías que usted elija y redacta un informe. No aprueba, no publica, no vincula categorías y no cambia el estado de la solicitud.')
            ->modalSubmitActionLabel('Analizar ahora')
            ->modalWidth('3xl')
            ->form(fn () => [
                Placeholder::make('candidatas')
                    ->label('Catálogo: categorías vigentes encontradas con el texto de la solicitud')
                    ->content(fn () => $this->candidatesHtml($service)),
                Select::make('category_ids')
                    ->label('Categorías del catálogo que quiere comparar')
                    ->helperText('Escriba un nombre, un sinónimo o un código. Solo aparecen categorías vigentes (no Grupos). Puede elegir varias, hasta '.config('category_analysis.max_selected_categories').'.')
                    ->multiple()
                    ->searchable()
                    ->required()
                    ->maxItems((int) config('category_analysis.max_selected_categories'))
                    ->default(fn () => array_column(array_slice($service->catalogCandidates($this->getRecord()), 0, 3), 'category_id'))
                    ->getSearchResultsUsing(fn (string $search) => $service->searchCatalog($search))
                    ->getOptionLabelsUsing(fn (array $values) => collect($service->snapshot(array_map('intval', $values)))
                        ->mapWithKeys(fn ($c) => [$c['category_id'] => "{$c['breadcrumb']} · {$c['code']}"])->all()),
                Placeholder::make('fuentes')
                    ->label('Fuentes externas')
                    ->content($service->sourcesAvailable()
                        ? 'Se buscarán fuentes públicas técnicas y se comprobarán sus enlaces cuando sea posible.'
                        : 'La búsqueda de fuentes externas no está disponible: el informe saldrá marcado como limitado y no citará fuentes.'),
            ])
            ->action(function (array $data) use ($service) {
                try {
                    $analysis = $service->run($this->getRecord(), Auth::user(), $data['category_ids'] ?? []);
                } catch (AuthorizationException) {
                    Notification::make()->danger()->title('No autorizado')->send();

                    return;
                } catch (CategoryAnalysisException $e) {
                    Notification::make()->warning()->title('No se ejecutó el análisis')->body($e->getMessage())->send();

                    return;
                }

                $analysis->status === TaxonomyCategoryRequestAnalysis::STATUS_COMPLETED
                    ? Notification::make()->success()->title('Análisis listo')
                        ->body(($analysis->limited ? 'Análisis limitado: sin fuentes externas verificadas. ' : '').'El informe quedó al final de esta página, en «Análisis con IA».')->send()
                    : Notification::make()->danger()->title('El análisis falló')
                        ->body($analysis->errorLabel().' El intento quedó registrado en «Análisis con IA». La solicitud no cambió.')->send();

                $this->getRecord()->refresh();
            });
    }

    private function candidatesHtml(CategoryAnalysisService $service): HtmlString
    {
        $candidates = $service->catalogCandidates($this->getRecord());
        if ($candidates === []) {
            return new HtmlString('<span class="text-gray-500">El buscador del catálogo no encontró categorías con ese texto. Búsquelas abajo.</span>');
        }

        $items = collect($candidates)->map(fn ($c) => '<li><span class="font-medium">'.e($c['breadcrumb']).'</span> · '.e($c['code'])
            .' <span class="text-xs text-gray-500">(catálogo)</span></li>')->implode('');

        return new HtmlString('<ul class="list-disc ps-5 text-sm">'.$items.'</ul>');
    }
}
