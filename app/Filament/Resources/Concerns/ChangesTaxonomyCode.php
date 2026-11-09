<?php

namespace App\Filament\Resources\Concerns;

use App\Filament\Resources\TaxonomyCategoryResource;
use App\Models\TaxonomyCategory;
use App\Services\Taxonomy\CategoryCodeChanger;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * TASK-0015: acción «Cambiar código…» de las páginas de edición de Grupo, Familia y Categoría. Es la
 * única vía para cambiar código, padre o tipo: los formularios de edición ya no los envían.
 *
 * **Código nuevo:** el siguiente libre (el mismo criterio que al crear) o escrito a mano. En los dos
 * casos `CategoryCodeChanger` valida la nomenclatura, renumera los descendientes, actualiza las
 * referencias de texto y deja el motivo en la auditoría.
 *
 * **Campos según el nivel:**
 * - Categoría: Grupo, Familia y Tipo;
 * - Familia: Grupo;
 * - Grupo: solo el código.
 *
 * La página que lo usa debe declarar `CHANGE_CODE_LABEL`.
 */
trait ChangesTaxonomyCode
{
    public const MODE_AUTO = 'automatico';

    public const MODE_MANUAL = 'manual';

    /** Campos que el guardado normal de la ficha nunca envía (TASK-0015). */
    protected function withoutLockedCodeFields(array $data): array
    {
        unset($data['code'], $data['parent_id'], $data['tipo_oferta']);

        return $data;
    }

    protected function changeCodeAction(): Actions\Action
    {
        return Actions\Action::make('cambiarCodigo')
            ->label(static::CHANGE_CODE_LABEL)
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn () => Gate::allows('update', $this->getRecord()))
            ->modalHeading(static::CHANGE_CODE_LABEL)
            ->modalDescription(fn () => $this->changeCodeDescription())
            ->modalSubmitActionLabel('Cambiar')
            ->fillForm(function () {
                $record = $this->taxonomyNode();

                return [
                    'group_id' => $record->level === TaxonomyCategory::LEVEL_CATEGORY ? $record->parent?->parent_id : null,
                    'parent_id' => $record->parent_id,
                    'tipo_oferta' => $record->tipo_oferta,
                    'modo' => self::MODE_MANUAL,
                    'code' => $record->code,
                ];
            })
            ->form(fn () => $this->changeCodeForm())
            ->action(function (array $data, Actions\Action $action) {
                $record = $this->taxonomyNode();

                try {
                    $result = app(CategoryCodeChanger::class)->change(
                        Auth::user(),
                        $record,
                        isset($data['parent_id']) ? (int) $data['parent_id'] : null,
                        $data['tipo_oferta'] ?? null,
                        (string) $data['code'],
                        (string) $data['reason'],
                    );
                } catch (ValidationException $e) {
                    Notification::make()->danger()
                        ->title('No se cambió el código')
                        ->body(collect($e->errors())->flatten()->implode(' '))
                        ->persistent()
                        ->send();
                    $action->halt();

                    return;
                } catch (AuthorizationException $e) {
                    Notification::make()->danger()->title('No autorizado')->body($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()
                    ->title('Código cambiado')
                    ->body("{$result['old_code']} → {$result['new_code']}. Renumerados: {$result['descendants']} descendiente(s). Referencias actualizadas: {$result['term_relations']} relación(es) término↔CPV, {$result['service_relations']} de servicios, {$result['crawl_matches']} coincidencia(s) de rastreo. Quedó en la auditoría.")
                    ->persistent()
                    ->send();

                $this->redirect(static::getResource()::getUrl('edit', ['record' => $record]));
            });
    }

    private function taxonomyNode(): TaxonomyCategory
    {
        /** @var TaxonomyCategory */
        return $this->getRecord();
    }

    private function changeCodeDescription(): string
    {
        return match ($this->taxonomyNode()->level) {
            TaxonomyCategory::LEVEL_GROUP => 'El código de un Grupo es CPV-NN. Todas sus familias y categorías cambian ese prefijo y conservan su propio correlativo. El motivo queda en la auditoría.',
            TaxonomyCategory::LEVEL_FAMILY => 'El código de una Familia es CPV-<grupo>.NN. Todas sus categorías cambian ese prefijo y conservan su correlativo y su letra. El motivo queda en la auditoría.',
            default => 'El código debe seguir la nomenclatura de la familia: CPV-<familia>.<correlativo><G|S>, con G para Bien y S para Servicio. El motivo queda en la auditoría.',
        }.' Las empresas vinculadas se conservan; las relaciones del diccionario y de servicios pasan al código nuevo.';
    }

    /** @return array<Forms\Components\Component> */
    private function changeCodeForm(): array
    {
        $record = $this->taxonomyNode();
        $level = (int) $record->level;
        $groups = fn () => TaxonomyCategory::query()
            ->where('level', TaxonomyCategory::LEVEL_GROUP)
            ->with('translations')
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (TaxonomyCategory $g) => [$g->id => TaxonomyCategoryResource::nodeLabel($g)]);
        $refresh = fn (Forms\Set $set, Forms\Get $get) => $this->refreshSuggestedCode($set, $get);

        $fields = [
            Forms\Components\Placeholder::make('actual')
                ->label('Actual')
                ->content(fn () => collect([$record->parent?->parent, $record->parent])
                    ->filter()
                    ->map(fn (TaxonomyCategory $n) => TaxonomyCategoryResource::nodeLabel($n))
                    ->push($record->code)
                    ->implode(' > ')),
        ];

        if ($level === TaxonomyCategory::LEVEL_CATEGORY) {
            $fields[] = Forms\Components\Select::make('group_id')
                ->label('Grupo')
                ->options($groups)
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(function (Forms\Set $set) {
                    $set('parent_id', null);
                    $set('code', null);
                });
            $fields[] = Forms\Components\Select::make('parent_id')
                ->label('Familia')
                ->options(fn (Forms\Get $get) => TaxonomyCategory::query()
                    ->where('level', TaxonomyCategory::LEVEL_FAMILY)
                    ->where('parent_id', $get('group_id') ?: 0)
                    ->with('translations')
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (TaxonomyCategory $f) => [$f->id => TaxonomyCategoryResource::nodeLabel($f)]))
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated($refresh);
            $fields[] = Forms\Components\Select::make('tipo_oferta')
                ->label('Tipo de oferta')
                ->options([
                    TaxonomyCategory::TIPO_BIEN => 'Bien (G)',
                    TaxonomyCategory::TIPO_SERVICIO => 'Servicio (S)',
                ])
                ->native(false)
                ->required()
                ->live()
                ->afterStateUpdated($refresh);
        }

        if ($level === TaxonomyCategory::LEVEL_FAMILY) {
            $fields[] = Forms\Components\Select::make('parent_id')
                ->label('Grupo')
                ->options($groups)
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated($refresh);
        }

        $impact = app(CategoryCodeChanger::class)->impact($record);

        return array_merge($fields, [
            Forms\Components\Radio::make('modo')
                ->label('Código nuevo')
                ->options([
                    self::MODE_AUTO => $level === TaxonomyCategory::LEVEL_GROUP ? 'Siguiente código de grupo libre' : 'Correlativo siguiente (como al crear)',
                    self::MODE_MANUAL => 'Escribirlo a mano (se valida igual)',
                ])
                ->required()
                ->live()
                ->afterStateUpdated($refresh),
            Forms\Components\TextInput::make('code')
                ->label('Código (CPV)')
                ->required()
                ->maxLength(50)
                ->disabled(fn (Forms\Get $get) => $get('modo') === self::MODE_AUTO)
                ->dehydrated()
                ->helperText('Se valida la nomenclatura del nivel y que no se repita.'),
            Forms\Components\Placeholder::make('impacto')
                ->label('Qué se actualiza')
                ->content("{$impact['descendants']} descendiente(s) renumerado(s). {$impact['empresas']} empresa(s) vinculada(s), que se conservan. "
                    ."{$impact['term_relations']} relación(es) término↔CPV, {$impact['service_relations']} relación(es) de servicios "
                    ."y {$impact['crawl_matches']} coincidencia(s) de rastreo pasan al código nuevo."),
            Forms\Components\Textarea::make('reason')
                ->label('Motivo del cambio')
                ->required()
                ->minLength(10)
                ->rows(3)
                ->helperText('Por qué se cambia. Queda en la auditoría junto con el código anterior y el nuevo.'),
        ]);
    }

    private function refreshSuggestedCode(Forms\Set $set, Forms\Get $get): void
    {
        if ($get('modo') !== self::MODE_AUTO) {
            return;
        }

        $parentId = $get('parent_id') ? (int) $get('parent_id') : null;

        $set('code', match ((int) $this->taxonomyNode()->level) {
            TaxonomyCategory::LEVEL_GROUP => CategoryCodeChanger::nextParentCode(null),
            TaxonomyCategory::LEVEL_FAMILY => $parentId ? CategoryCodeChanger::nextParentCode($parentId) : null,
            default => TaxonomyCategoryResource::buildNextCode($parentId, $get('tipo_oferta')),
        });
    }
}
