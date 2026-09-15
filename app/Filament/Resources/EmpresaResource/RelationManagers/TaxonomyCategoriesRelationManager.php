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

    /**
     * Reescrita (15 sep 2026) tras probar en vivo contra `pruebas.camarapetrolera.app`: 2 búsquedas
     * reales ("servicio valvula", "cabezales") no devolvían nada en pantalla, aunque
     * `TaxonomyCategorySearch::search()` sí devuelve resultados correctos para esas mismas frases
     * probado por tinker (8 resultados cada una) - el bug estaba en el mecanismo de UI, no en la
     * búsqueda: un `TextInput` con `suffixAction` escribiendo a un campo `Hidden` para que un
     * `CheckboxList` lo leyera por `Get` es un combo poco probado en Filament v3, y no refrescaba
     * bien dentro del modal de la Action. Se reemplaza por `Select::make(...)->multiple()
     * ->searchable()->getSearchResultsUsing(...)` - el mismo patrón reactivo (`Select` con
     * `getSearchResultsUsing`/`live()`) que YA funciona en este mismo proyecto
     * (`TaxonomyCategoryResource.php`, cascada Grupo→Familia→Categoría) - built-in de Filament, sin
     * juntar estado a mano entre 2 campos.
     *
     * También responde el pedido de poder "ver las categorías de una Familia": el segundo Select
     * (`familia_a_explorar`) + el `CheckboxList` que depende de él (`categorias_de_familia`) dejan
     * elegir una Familia y ver/marcar sus categorías hijas directamente - antes esto solo era un
     * texto de aviso ("tiene 8 categorías más específicas"), ahora es realmente navegable.
     */
    private function buscarYAgregarAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('buscarYAgregar')
            ->label('Buscar y agregar categoría')
            ->icon('heroicon-o-magnifying-glass')
            ->form([
                Forms\Components\Select::make('categorias')
                    ->label('¿Qué ofrece su empresa? (texto libre, español o inglés)')
                    ->placeholder('ej. mantenimiento de válvulas, cabezales, perforación direccional...')
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => $this->opcionesBusqueda($search))
                    ->getOptionLabelsUsing(fn (array $values) => TaxonomyCategory::query()
                        ->whereIn('id', $values)
                        ->get()
                        ->mapWithKeys(fn (TaxonomyCategory $c) => [$c->id => $c->breadcrumb('es')])
                        ->all())
                    ->helperText('Escriba y espere un instante — los resultados aparecen solos, no hace falta apretar nada.'),
                Forms\Components\Select::make('familia_a_explorar')
                    ->label('¿Prefiere explorar una Familia completa en vez de buscar?')
                    ->helperText('Elija una Familia para ver y marcar directamente sus categorías más específicas.')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => $this->opcionesFamilia($search))
                    ->getOptionLabelUsing(fn ($value) => TaxonomyCategory::find($value)?->breadcrumb('es'))
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('categorias_de_familia', [])),
                Forms\Components\CheckboxList::make('categorias_de_familia')
                    ->label('Categorías de esa Familia')
                    ->options(fn (Get $get) => $this->opcionesHijosDe($get('familia_a_explorar')))
                    ->columns(1)
                    ->visible(fn (Get $get) => filled($get('familia_a_explorar'))),
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
                $categoryIds = collect($data['categorias'] ?? [])
                    ->merge($data['categorias_de_familia'] ?? [])
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

    /** @return array<int, string> category_id => breadcrumb (+ aviso si es una Familia con hijos). */
    private function opcionesBusqueda(string $search): array
    {
        $resultados = app(TaxonomyCategorySearch::class)->search($search, 15);

        $hijosPorFamilia = TaxonomyCategory::query()
            ->whereIn('parent_id', $resultados->where('level', TaxonomyCategory::LEVEL_FAMILY)->pluck('category_id'))
            ->get()
            ->countBy('parent_id');

        return $resultados->mapWithKeys(function (array $r) use ($hijosPorFamilia) {
            $aviso = $r['level'] === TaxonomyCategory::LEVEL_FAMILY && ($hijosPorFamilia[$r['category_id']] ?? 0) > 0
                ? ' — Familia completa ('.$hijosPorFamilia[$r['category_id']].' categorías más específicas debajo, ver "explorar una Familia")'
                : '';

            return [$r['category_id'] => $r['breadcrumb'].$aviso];
        })->all();
    }

    /**
     * @return array<int, string>
     *
     * Postgres distingue tildes en ILIKE (a diferencia de MySQL) - sin `unaccent()` en ambos lados
     * "valvulas" nunca matchea "Válvulas" en la base real. Mismo gotcha ya documentado y resuelto
     * en `perfilafiliados-mcp/src/taxonomy-tools.ts` y en `TaxonomyCategorySearch`.
     */
    private function opcionesFamilia(string $search): array
    {
        return TaxonomyCategory::query()
            ->where('level', TaxonomyCategory::LEVEL_FAMILY)
            ->where('is_active', true)
            ->whereHas('translations', fn ($q) => $q->whereRaw('unaccent(name) ilike unaccent(?)', ['%'.$search.'%']))
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (TaxonomyCategory $c) => [$c->id => $c->breadcrumb('es')])
            ->all();
    }

    /** @return array<int, string> Categorías hijas directas de la Familia elegida en el explorador. */
    private function opcionesHijosDe(mixed $familiaId): array
    {
        if (! $familiaId) {
            return [];
        }

        return TaxonomyCategory::query()
            ->where('parent_id', $familiaId)
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (TaxonomyCategory $c) => [$c->id => $c->breadcrumb('es')])
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
