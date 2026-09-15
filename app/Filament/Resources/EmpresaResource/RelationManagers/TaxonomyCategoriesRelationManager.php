<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use App\Models\EmpresaTaxonomyCategory;
use App\Models\TaxonomyCategory;
use App\Models\TaxonomySelectionSettings;
use App\Services\TaxonomyCategorySearch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Fase 4 del proyecto de taxonomía (ver docs/taxonomia/plan_mcp_cira.md y el plan de esta fase):
 * reemplazo real de `ServicesRelationManager` (hoy solo lectura, catálogo viejo) - acá la empresa
 * autoselecciona sus categorías de la taxonomía CPV nueva con el buscador de `TaxonomyCategorySearch`,
 * en vez de navegar el árbol de 3.497 nodos a mano.
 *
 * No es un `belongsToMany` con Attach/Detach estándar de Filament: `EmpresaTaxonomyCategory` es un
 * modelo propio con su propio id/origen/es_principal, así que la relación en `Empresa` es un
 * `hasMany` y esta pestaña arma su propia Action de alta en vez de usar `AttachAction` (pensado
 * para pivotes puros).
 */
class TaxonomyCategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'taxonomyCategories';

    protected static ?string $recordTitleAttribute = 'id';

    public static ?string $label = 'Categorías CPV';

    public static ?string $navigationLabel = 'Categorías CPV';

    protected static ?string $pluralModelLabel = 'Categorías CPV';

    protected static ?string $title = 'Categorías CPV (taxonomía nueva)';

    // El alta no pasa por el formulario de Crear por defecto de Filament - ver headerActions().
    protected function canCreate(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with('category.translations'))
            ->columns([
                Tables\Columns\TextColumn::make('breadcrumb')
                    ->label('Categoría')
                    ->getStateUsing(fn (EmpresaTaxonomyCategory $record) => $record->category?->breadcrumb('es') ?? '—')
                    ->wrap(),
                Tables\Columns\IconColumn::make('es_principal')
                    ->label('Principal')
                    ->boolean(),
                Tables\Columns\TextColumn::make('origen')
                    ->label('Origen')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        EmpresaTaxonomyCategory::ORIGEN_SUGGESTED => 'Sugerida (sin confirmar)',
                        EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED => 'Declarada por la empresa',
                        EmpresaTaxonomyCategory::ORIGEN_VALIDATED => 'Confirmada',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        EmpresaTaxonomyCategory::ORIGEN_SUGGESTED => 'warning',
                        EmpresaTaxonomyCategory::ORIGEN_VALIDATED => 'success',
                        default => 'gray',
                    }),
            ])
            ->headerActions([
                $this->buscarYAgregarAction(),
            ])
            ->actions([
                Tables\Actions\Action::make('confirmar')
                    ->label('Confirmar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (EmpresaTaxonomyCategory $record) => $record->origen === EmpresaTaxonomyCategory::ORIGEN_SUGGESTED)
                    ->action(fn (EmpresaTaxonomyCategory $record) => $record->update(['origen' => EmpresaTaxonomyCategory::ORIGEN_VALIDATED])),
                Tables\Actions\Action::make('togglePrincipal')
                    ->label(fn (EmpresaTaxonomyCategory $record) => $record->es_principal ? 'Marcar como secundaria' : 'Marcar como principal')
                    ->icon('heroicon-o-star')
                    ->action(function (EmpresaTaxonomyCategory $record) {
                        if (! $record->es_principal && $bloqueo = $this->limiteExcedido($record->empresa_id, principal: true, agregar: 1)) {
                            Notification::make()->danger()->title('Límite alcanzado')->body($bloqueo)->send();

                            return;
                        }

                        $record->update(['es_principal' => ! $record->es_principal]);
                    }),
                // Action custom en vez de DeleteAction::make(): esa clase resuelve la política
                // 'delete' del modelo por defecto, y no existe una EmpresaTaxonomyCategoryPolicy
                // (a diferencia de Service/Sector, que sí la tienen) - sin política registrada,
                // Laravel deniega por defecto salvo el bypass de Super Admin de filament-shield,
                // lo que hubiera dejado a cualquier otro rol sin poder usar el botón. Una Action
                // simple queda con la misma visibilidad que "confirmar"/"togglePrincipal" de acá
                // arriba, gobernada por el acceso ya existente a esta pestaña (EmpresaResource).
                Tables\Actions\Action::make('quitar')
                    ->label('Quitar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (EmpresaTaxonomyCategory $record) => $record->delete()),
            ])
            ->bulkActions([
                //
            ])
            ->filters([
                //
            ]);
    }

    private function buscarYAgregarAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('buscarYAgregar')
            ->label('Buscar y agregar categoría')
            ->icon('heroicon-o-magnifying-glass')
            ->form([
                Forms\Components\TextInput::make('busqueda')
                    ->label('¿Qué ofrece su empresa? (texto libre, español o inglés)')
                    ->placeholder('ej. mantenimiento de válvulas, servicios de perforación direccional...')
                    ->suffixAction(
                        Forms\Components\Actions\Action::make('buscar')
                            ->icon('heroicon-m-magnifying-glass')
                            ->action(function (Get $get, Set $set) {
                                $resultados = app(TaxonomyCategorySearch::class)
                                    ->search((string) $get('busqueda'), 12)
                                    ->all();

                                $set('resultados', $resultados);
                            })
                    ),
                Forms\Components\Hidden::make('resultados')->default([]),
                Forms\Components\CheckboxList::make('seleccion_busqueda')
                    ->label('Resultados')
                    ->options(fn (Get $get) => collect($get('resultados') ?? [])->pluck('breadcrumb', 'category_id'))
                    ->descriptions(fn (Get $get) => $this->descripcionesDeHijos($get('resultados') ?? []))
                    ->columns(1)
                    ->visible(fn (Get $get) => ! empty($get('resultados'))),
                Forms\Components\Select::make('categoria_arbol')
                    ->label('O elegí directamente una categoría existente')
                    ->helperText('Alternativa a la búsqueda, para quien prefiera encontrarla por nombre exacto.')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => $this->opcionesArbol($search))
                    ->getOptionLabelUsing(fn ($value) => TaxonomyCategory::find($value)?->breadcrumb('es')),
                Forms\Components\Radio::make('tipo')
                    ->label('Guardar como')
                    ->options([
                        'principal' => 'Principal (lo que la empresa realmente hace)',
                        'secundaria' => 'Secundaria (servicio relacionado u ocasional)',
                    ])
                    ->default('secundaria')
                    ->inline()
                    ->required(),
            ])
            ->action(function (array $data) {
                $categoryIds = collect($data['seleccion_busqueda'] ?? [])
                    ->merge(array_filter([$data['categoria_arbol'] ?? null]))
                    ->unique()
                    ->map(fn ($id) => (int) $id)
                    ->values();

                if ($categoryIds->isEmpty()) {
                    Notification::make()->warning()->title('No se seleccionó ninguna categoría')->send();

                    return;
                }

                $esPrincipal = $data['tipo'] === 'principal';
                $empresaId = $this->getOwnerRecord()->id;

                if ($bloqueo = $this->limiteExcedido($empresaId, $esPrincipal, $categoryIds->count())) {
                    Notification::make()->danger()->title('Límite alcanzado')->body($bloqueo)->persistent()->send();

                    return;
                }

                $yaVinculadas = EmpresaTaxonomyCategory::query()
                    ->where('empresa_id', $empresaId)
                    ->whereIn('category_id', $categoryIds)
                    ->pluck('category_id');

                foreach ($categoryIds->diff($yaVinculadas) as $categoryId) {
                    $this->getOwnerRecord()->taxonomyCategories()->create([
                        'category_id' => $categoryId,
                        'origen' => EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED,
                        'es_principal' => $esPrincipal,
                    ]);
                }

                Notification::make()->success()->title('Categorías agregadas')->send();
            });
    }

    /**
     * @return array<int, string>
     *
     * Postgres distingue tildes en ILIKE (a diferencia de MySQL) - sin `unaccent()` en ambos lados
     * "valvulas" nunca matchea "Válvulas" en la base real. Mismo gotcha ya documentado y resuelto
     * en `perfilafiliados-mcp/src/taxonomy-tools.ts` y en `TaxonomyCategorySearch` - encontrado acá
     * probando este método contra la Supabase real antes de darlo por terminado.
     */
    private function opcionesArbol(string $search): array
    {
        return TaxonomyCategory::query()
            ->where('level', '!=', TaxonomyCategory::LEVEL_GROUP)
            ->where('is_active', true)
            ->whereHas('translations', fn ($q) => $q->whereRaw('unaccent(name) ilike unaccent(?)', ['%'.$search.'%']))
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (TaxonomyCategory $c) => [$c->id => $c->breadcrumb('es')])
            ->all();
    }

    /** Aviso de un clic (sin forzar navegación) cuando el match es una Familia con hijos - ver el PDF de diseño, sección 4. */
    private function descripcionesDeHijos(array $resultados): array
    {
        $familiaIds = collect($resultados)
            ->where('level', TaxonomyCategory::LEVEL_FAMILY)
            ->pluck('category_id');

        if ($familiaIds->isEmpty()) {
            return [];
        }

        return TaxonomyCategory::query()
            ->whereIn('parent_id', $familiaIds)
            ->get()
            ->groupBy('parent_id')
            ->map(fn ($hijos, $parentId) => "Tiene {$hijos->count()} categorías más específicas — ¿alguna aplica mejor?")
            ->all();
    }

    /** Null si no hay bloqueo, o el mensaje a mostrar si se supera el límite configurado. */
    private function limiteExcedido(int $empresaId, bool $principal, int $agregar): ?string
    {
        $settings = TaxonomySelectionSettings::current();
        $limite = $principal ? $settings->max_categorias_principales : $settings->max_categorias_secundarias;

        if ($limite === null) {
            return null;
        }

        $actuales = EmpresaTaxonomyCategory::query()
            ->where('empresa_id', $empresaId)
            ->where('es_principal', $principal)
            ->count();

        if (($actuales + $agregar) > $limite) {
            $tipo = $principal ? 'principales' : 'secundarias';

            return "Esta empresa ya tiene {$actuales} categoría(s) {$tipo} — el máximo configurado es {$limite}.";
        }

        return null;
    }
}
