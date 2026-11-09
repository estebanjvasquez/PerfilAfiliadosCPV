<?php

namespace App\Filament\Resources\Concerns;

use App\Models\TaxonomyCategory;
use App\Services\Taxonomy\TaxonomyLookup;
use Filament\Forms;
use Filament\Tables;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * TASK-0014 (Issue #2 `6736283026`): búsqueda de los listados de Categorías, Familias y Grupos con
 * el mismo criterio que el selector de la empresa (`TaxonomyLookup`, alcance de administración).
 *
 * **Qué cambia en cada listado:**
 * - el cuadro de búsqueda encuentra por código, nombre ES o EN, sinónimo de la categoría y término,
 *   término canónico o alias del diccionario, sin distinguir tildes ni mayúsculas;
 * - una Familia aparece si coincide una de sus categorías, y un Grupo si coincide una de sus familias
 *   o categorías;
 * - la columna «Coincidencia» dice por qué apareció cada fila, con el estado de la relación del
 *   diccionario cuando la hay;
 * - el filtro «Término del diccionario» busca en el diccionario y no en los sinónimos.
 *
 * **Qué no cambia:** todos los estados siguen visibles (activa e inactiva). No hay similitud
 * semántica en administración: aquí solo se muestran vínculos que existen.
 */
trait SearchesTaxonomyCoherently
{
    public const SEARCH_PLACEHOLDER = 'Código, nombre ES/EN, sinónimo o término del diccionario';

    public const DICTIONARY_FILTER_LABEL = 'Término del diccionario';

    public const SYNONYM_CONSTRAINT_LABEL = 'Sinónimo de la categoría (no incluye el diccionario)';

    /** Búsqueda coherente para la columna que la lleva: una palabra de la búsqueda por llamada. */
    protected static function coherentSearch(): \Closure
    {
        return fn (Builder $query, string $search): Builder => app(TaxonomyLookup::class)->constrainAdminQuery($query, $search);
    }

    protected static function matchReasonColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('coincidencia')
            ->label('Coincidencia')
            ->getStateUsing(function (TaxonomyCategory $record, $livewire): array {
                $search = $livewire->getTableSearch();

                if (blank($search)) {
                    return [];
                }

                $records = $livewire->getTableRecords();
                $pageIds = collect($records instanceof Paginator ? $records->items() : $records)
                    ->map(fn ($row) => (int) $row->getKey())
                    ->all();

                return app(TaxonomyLookup::class)->adminReasonsFor((int) $record->getKey(), $search, $pageIds);
            })
            ->visible(fn ($livewire) => filled($livewire->getTableSearch()))
            ->listWithLineBreaks()
            ->limitList(4)
            ->expandableLimitedList()
            ->placeholder('—')
            ->wrap();
    }

    /**
     * Filtro por el diccionario de términos (`taxonomy_terms`, `canonical_term` y alias), opcionalmente
     * por estado de la relación término→CPV. Es distinto del filtro de sinónimos de la categoría.
     */
    protected static function dictionaryTermFilter(): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make('termino_diccionario')
            ->label(self::DICTIONARY_FILTER_LABEL)
            ->form([
                Forms\Components\TextInput::make('termino')
                    ->label(self::DICTIONARY_FILTER_LABEL)
                    ->helperText('Término, término canónico o alias; sin distinguir tildes ni mayúsculas.'),
                Forms\Components\Select::make('estado')
                    ->label('Estado de la relación')
                    ->options(collect(TaxonomyLookup::RELATION_STATUS_LABELS)->map(fn ($label) => ucfirst($label))->all())
                    ->placeholder('Cualquier estado'),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $termino = trim((string) ($data['termino'] ?? ''));

                if ($termino === '') {
                    return $query;
                }

                $like = '%'.addcslashes($termino, '\\%_').'%';
                $table = $query->getModel()->getTable();
                $estado = $data['estado'] ?? null;

                return $query->whereRaw(<<<SQL
                    exists (
                        select 1 from taxonomy_term_cpv_relations r
                        join taxonomy_terms t on t.id = r.term_id
                        where r.category_id = {$table}.id
                            and (?::text is null or r.status = ?::text)
                            and (
                                unaccent(t.term) ilike unaccent(?)
                                or unaccent(coalesce(t.canonical_term, '')) ilike unaccent(?)
                                or exists (select 1 from taxonomy_term_aliases a where a.term_id = t.id and unaccent(a.alias) ilike unaccent(?))
                            )
                    )
                SQL, [$estado, $estado, $like, $like, $like]);
            })
            ->indicateUsing(function (array $data): ?string {
                if (blank($data['termino'] ?? null)) {
                    return null;
                }

                $estado = $data['estado'] ?? null;

                return self::DICTIONARY_FILTER_LABEL.': '.$data['termino']
                    .($estado ? ' ('.(TaxonomyLookup::RELATION_STATUS_LABELS[$estado] ?? $estado).')' : '');
            });
    }
}
