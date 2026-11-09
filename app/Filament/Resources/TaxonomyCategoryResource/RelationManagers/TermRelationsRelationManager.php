<?php

namespace App\Filament\Resources\TaxonomyCategoryResource\RelationManagers;

use App\Filament\Resources\Concerns\ManagesTaxonomyRelationReview;
use App\Filament\Resources\TaxonomyTermCpvRelationResource;
use App\Models\TaxonomyCategory;
use App\Models\TaxonomyTerm;
use App\Models\TaxonomyTermCpvRelation;
use App\Services\Taxonomy\TaxonomyLookup;
use App\Services\Taxonomy\TermCategoryLinker;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * TASK-0014 (Issue #2 `6736283026`, punto 4): pestaña «Términos del diccionario» de la ficha de una
 * categoría, familia o grupo.
 *
 * **Qué muestra:** las relaciones término→CPV que apuntan a este nodo, en cualquier estado, con el
 * término, su canónico y sus alias. Es la misma tabla que «Relaciones Término↔CPV», vista desde la
 * categoría.
 *
 * **Asociar un término existente:** la acción lo busca en el diccionario y nunca crea un término. La
 * relación nueva queda en revisión; las reglas viven en `TermCategoryLinker`.
 *
 * **Aprobar y rechazar:** las mismas acciones de `ManagesTaxonomyRelationReview`, con sus permisos
 * (`taxonomy_publish`, `taxonomy_edit_relations`), su motivo obligatorio y su auditoría.
 *
 * Aquí no se edita ni se borra nada. Para eso, «Abrir relación» lleva a la ficha de la relación.
 * La pestaña se ve con el permiso de listar relaciones, que la policy aplica por defecto.
 */
class TermRelationsRelationManager extends RelationManager
{
    use ManagesTaxonomyRelationReview;

    protected static string $relationship = 'termRelations';

    protected static ?string $recordTitleAttribute = 'cpv_code';

    protected static ?string $title = 'Términos del diccionario';

    public static ?string $label = 'Relación con un término';

    protected static ?string $pluralModelLabel = 'Términos del diccionario';

    public const ASSOCIATE_ACTION_LABEL = 'Asociar término existente';

    public const ASSOCIATE_HELP = 'Busque un término que ya exista en el diccionario (término, canónico o alias). No se crea ningún término nuevo. La relación queda «en revisión» y llega al buscador de las empresas solo cuando alguien con permiso de publicar la aprueba, con motivo.';

    public function table(Table $table): Table
    {
        return $table
            ->description('Términos del diccionario relacionados con este nodo, en cualquier estado. Solo las relaciones aprobadas intervienen en el buscador de las empresas. No confundir con la pestaña «Sinónimos», que es otra lista.')
            ->modifyQueryUsing(fn ($query) => $query->with(['term.aliases']))
            ->columns([
                Tables\Columns\TextColumn::make('term.term')->label('Término')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('term.canonical_term')->label('Canónico')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('aliases')
                    ->label('Alias')
                    ->getStateUsing(fn (TaxonomyTermCpvRelation $record) => $record->term?->aliases->pluck('alias')->implode(', '))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('relation_type')->label('Tipo')->badge(),
                Tables\Columns\TextColumn::make('weight')->label('Peso')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst(TaxonomyLookup::RELATION_STATUS_LABELS[$state] ?? $state))
                    ->color(fn (string $state) => match ($state) {
                        TaxonomyTermCpvRelation::STATUS_APPROVED => 'success',
                        TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW => 'warning',
                        TaxonomyTermCpvRelation::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('source')->label('Fuente')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('reviewed_at')->label('Revisada')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(collect(TaxonomyLookup::RELATION_STATUS_LABELS)->map(fn ($label) => ucfirst($label))->all()),
            ])
            ->defaultSort('weight', 'desc')
            ->headerActions([
                $this->associateAction(),
            ])
            ->actions([
                static::reviewApproveAction(),
                static::reviewRejectAction(),
                Tables\Actions\Action::make('abrirRelacion')
                    ->label('Abrir relación')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (TaxonomyTermCpvRelation $record) => TaxonomyTermCpvRelationResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (TaxonomyTermCpvRelation $record) => Gate::allows('update', $record)),
            ])
            ->bulkActions([]);
    }

    private function associateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('asociarTermino')
            ->label(self::ASSOCIATE_ACTION_LABEL)
            ->icon('heroicon-o-link')
            ->visible(fn () => Gate::allows('create', TaxonomyTermCpvRelation::class))
            ->modalDescription(self::ASSOCIATE_HELP)
            ->modalSubmitActionLabel('Asociar (queda en revisión)')
            ->form([
                Forms\Components\Select::make('term_id')
                    ->label('Término del diccionario')
                    ->searchable()
                    ->required()
                    ->live()
                    ->getSearchResultsUsing(fn (string $search) => $this->termOptions($search))
                    ->getOptionLabelUsing(fn ($value) => TaxonomyTerm::find($value)?->term),
                Forms\Components\Placeholder::make('relaciones_del_termino')
                    ->label('Relaciones actuales de este término')
                    ->visible(fn (Get $get) => filled($get('term_id')))
                    ->content(fn (Get $get) => $this->termRelationsSummary((int) $get('term_id'))),
                Forms\Components\TextInput::make('weight')
                    ->label('Peso')
                    ->numeric()->step(0.0001)->minValue(0)->maxValue(1)
                    ->default(0.8)
                    ->required()
                    ->helperText('Fuerza entre este término y esta categoría. Se puede ajustar al revisarla.'),
                Forms\Components\Textarea::make('motivo')
                    ->label('Motivo')
                    ->required()
                    ->minLength(3)
                    ->rows(2)
                    ->helperText('Por qué este término corresponde a esta categoría. Queda en la auditoría.'),
                Forms\Components\Toggle::make('reabrir')
                    ->label('Reabrir la relación rechazada o descartada para revisarla de nuevo')
                    ->visible(fn (Get $get) => $this->hasOnlyClosedRelation((int) $get('term_id'))),
            ])
            ->action(function (array $data) {
                $term = TaxonomyTerm::find($data['term_id']);

                if (! $term) {
                    Notification::make()->danger()->title('El término ya no existe en el diccionario')->send();

                    return;
                }

                try {
                    $outcome = app(TermCategoryLinker::class)->link(
                        Auth::user(),
                        $this->category(),
                        $term,
                        (float) $data['weight'],
                        (string) $data['motivo'],
                        (bool) ($data['reabrir'] ?? false),
                    );
                } catch (AuthorizationException $e) {
                    Notification::make()->danger()->title('No autorizado')->body($e->getMessage())->send();

                    return;
                }

                $status = TaxonomyLookup::RELATION_STATUS_LABELS[$outcome['relation']->status] ?? $outcome['relation']->status;

                match ($outcome['result']) {
                    TermCategoryLinker::RESULT_CREATED => Notification::make()->success()
                        ->title('Término asociado, en revisión')
                        ->body("«{$term->term}» quedó relacionado con esta categoría en revisión. No interviene en el buscador hasta que se apruebe.")
                        ->send(),
                    TermCategoryLinker::RESULT_REOPENED => Notification::make()->success()
                        ->title('Relación reabierta, en revisión')
                        ->body("La relación previa de «{$term->term}» con esta categoría volvió a revisión. No se creó otra.")
                        ->send(),
                    TermCategoryLinker::RESULT_NEEDS_REOPEN => Notification::make()->warning()
                        ->title('Ya existe una relación '.$status)
                        ->body("«{$term->term}» ya tuvo una relación con esta categoría y quedó {$status}. No se creó otra. Para revisarla de nuevo, marque «Reabrir».")
                        ->persistent()
                        ->send(),
                    default => Notification::make()->warning()
                        ->title('Ya existe una relación '.$status)
                        ->body("«{$term->term}» ya está relacionado con esta categoría (estado: {$status}). No se creó otra.")
                        ->send(),
                };
            });
    }

    private function category(): TaxonomyCategory
    {
        /** @var TaxonomyCategory */
        return $this->getOwnerRecord();
    }

    /**
     * Términos del diccionario por término, canónico o alias, sin distinguir tildes ni mayúsculas. Cada
     * opción dice si ya tiene relación con esta categoría y en qué estado.
     *
     * @return array<int, string>
     */
    private function termOptions(string $search): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        $like = '%'.addcslashes($search, '\\%_').'%';

        $terms = TaxonomyTerm::query()
            ->whereRaw(<<<'SQL'
                unaccent(term) ilike unaccent(?)
                or unaccent(coalesce(canonical_term, '')) ilike unaccent(?)
                or exists (select 1 from taxonomy_term_aliases a where a.term_id = taxonomy_terms.id and unaccent(a.alias) ilike unaccent(?))
            SQL, [$like, $like, $like])
            ->with('aliases')
            ->orderBy('term')
            ->limit(50)
            ->get();

        $category = $this->category();
        $statuses = TaxonomyTermCpvRelation::query()
            ->whereIn('term_id', $terms->pluck('id'))
            ->where(fn ($q) => $q->where('category_id', $category->id)->orWhere('cpv_code', $category->code))
            ->get(['term_id', 'status'])
            ->groupBy('term_id');

        return $terms->mapWithKeys(function (TaxonomyTerm $term) use ($statuses) {
            $label = $term->term;

            if (filled($term->canonical_term) && $term->canonical_term !== $term->term) {
                $label .= ' · canónico: '.$term->canonical_term;
            }

            if ($term->aliases->isNotEmpty()) {
                $label .= ' · alias: '.$term->aliases->pluck('alias')->take(3)->implode(', ');
            }

            if ($existing = $statuses->get($term->id)) {
                $label .= ' · ya relacionado aquí: '.$existing->pluck('status')
                    ->map(fn ($s) => TaxonomyLookup::RELATION_STATUS_LABELS[$s] ?? $s)
                    ->unique()
                    ->implode(', ');
            }

            return [$term->id => $label];
        })->all();
    }

    private function termRelationsSummary(int $termId): string
    {
        $relations = TaxonomyTermCpvRelation::query()
            ->where('term_id', $termId)
            ->orderByRaw("case status when 'approved' then 0 when 'needs_review' then 1 else 2 end")
            ->orderByDesc('weight')
            ->limit(10)
            ->get(['cpv_code', 'status', 'relation_type', 'weight']);

        if ($relations->isEmpty()) {
            return 'Este término todavía no tiene relaciones con ninguna categoría.';
        }

        $total = TaxonomyTermCpvRelation::query()->where('term_id', $termId)->count();

        return $relations
            ->map(fn (TaxonomyTermCpvRelation $r) => sprintf(
                '%s — %s (%s, peso %s)',
                $r->cpv_code,
                TaxonomyLookup::RELATION_STATUS_LABELS[$r->status] ?? $r->status,
                $r->relation_type,
                number_format((float) $r->weight, 2, ',', ''),
            ))
            ->implode(' · ').($total > 10 ? " · y {$total} en total" : '');
    }

    private function hasOnlyClosedRelation(int $termId): bool
    {
        if ($termId === 0) {
            return false;
        }

        $term = TaxonomyTerm::find($termId);

        if (! $term) {
            return false;
        }

        $statuses = app(TermCategoryLinker::class)->existing($this->category(), $term)->pluck('status');

        return $statuses->isNotEmpty() && $statuses->every(fn ($s) => TermCategoryLinker::isClosed($s));
    }
}
