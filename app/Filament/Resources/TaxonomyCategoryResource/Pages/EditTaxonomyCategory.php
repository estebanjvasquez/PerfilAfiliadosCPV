<?php

namespace App\Filament\Resources\TaxonomyCategoryResource\Pages;

use App\Filament\Resources\Concerns\ManagesInlineTranslations;
use App\Filament\Resources\TaxonomyCategoryResource;
use App\Models\TaxonomyCategory;
use App\Services\Taxonomy\CategoryCodeChanger;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditTaxonomyCategory extends EditRecord
{
    use ManagesInlineTranslations;

    protected static string $resource = TaxonomyCategoryResource::class;

    public const CHANGE_CODE_LABEL = 'Cambiar código o familia';

    public const MODE_AUTO = 'automatico';

    public const MODE_MANUAL = 'manual';

    protected function getHeaderActions(): array
    {
        return [
            $this->changeCodeAction(),
            Actions\DeleteAction::make(),
        ];
    }

    /** Precarga name_es/name_en/description_es/description_en desde las traducciones ya guardadas. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->mergeExistingTranslationsIntoData($data, $this->getRecord());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingTranslations = $this->pullTranslationsFromData($data);

        // TASK-0015: el guardado normal nunca cambia código, familia ni tipo, aunque alguien envíe esos
        // campos a mano. La única vía es la acción «Cambiar código o familia».
        unset($data['code'], $data['parent_id'], $data['tipo_oferta']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->saveTranslations($this->record, $this->pendingTranslations);
    }

    /**
     * TASK-0015: cambia código, familia o tipo de la categoría con el mismo flujo guiado que al crear
     * (Grupo → Familia → Tipo → correlativo siguiente) o con un código escrito a mano. En los dos
     * casos `CategoryCodeChanger` valida la nomenclatura, actualiza las referencias de texto y registra
     * el motivo en la auditoría.
     */
    private function changeCodeAction(): Actions\Action
    {
        return Actions\Action::make('cambiarCodigo')
            ->label(self::CHANGE_CODE_LABEL)
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn () => Gate::allows('update', $this->getRecord()))
            ->modalHeading(self::CHANGE_CODE_LABEL)
            ->modalDescription('El código debe seguir la nomenclatura de la familia: CPV-<familia>.<correlativo><G|S>, con G para Bien y S para Servicio. Las empresas vinculadas no se pierden; las relaciones del diccionario y de servicios pasan al código nuevo. El motivo queda en la auditoría.')
            ->modalSubmitActionLabel('Cambiar')
            ->fillForm(function () {
                /** @var TaxonomyCategory $record */
                $record = $this->getRecord();

                return [
                    'group_id' => $record->parent?->parent_id,
                    'parent_id' => $record->parent_id,
                    'tipo_oferta' => $record->tipo_oferta,
                    'modo' => self::MODE_MANUAL,
                    'code' => $record->code,
                ];
            })
            ->form([
                Forms\Components\Placeholder::make('actual')
                    ->label('Actual')
                    ->content(function () {
                        /** @var TaxonomyCategory $record */
                        $record = $this->getRecord();

                        return TaxonomyCategoryResource::nodeLabel($record->parent?->parent)
                            .' > '.TaxonomyCategoryResource::nodeLabel($record->parent)
                            .' > '.$record->code;
                    }),
                Forms\Components\Select::make('group_id')
                    ->label('Grupo')
                    ->options(fn () => TaxonomyCategory::query()
                        ->where('level', TaxonomyCategory::LEVEL_GROUP)
                        ->with('translations')
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (TaxonomyCategory $g) => [$g->id => TaxonomyCategoryResource::nodeLabel($g)]))
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Forms\Set $set) {
                        $set('parent_id', null);
                        $set('code', null);
                    }),
                Forms\Components\Select::make('parent_id')
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
                    ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => $this->refreshSuggestedCode($set, $get)),
                Forms\Components\Select::make('tipo_oferta')
                    ->label('Tipo de oferta')
                    ->options([
                        TaxonomyCategory::TIPO_BIEN => 'Bien (G)',
                        TaxonomyCategory::TIPO_SERVICIO => 'Servicio (S)',
                    ])
                    ->native(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => $this->refreshSuggestedCode($set, $get)),
                Forms\Components\Radio::make('modo')
                    ->label('Código nuevo')
                    ->options([
                        self::MODE_AUTO => 'Correlativo siguiente de la familia (como al crear)',
                        self::MODE_MANUAL => 'Escribirlo a mano (se valida igual)',
                    ])
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => $this->refreshSuggestedCode($set, $get)),
                Forms\Components\TextInput::make('code')
                    ->label('Código (CPV)')
                    ->required()
                    ->maxLength(50)
                    ->disabled(fn (Forms\Get $get) => $get('modo') === self::MODE_AUTO)
                    ->dehydrated()
                    ->helperText('Debe empezar con el código de la familia elegida y terminar en un correlativo de 2 o 3 dígitos con G o S según el tipo. No puede repetirse.'),
                Forms\Components\Placeholder::make('impacto')
                    ->label('Qué se actualiza')
                    ->content(function () {
                        $impact = app(CategoryCodeChanger::class)->impact($this->getRecord());

                        return "{$impact['empresas']} empresa(s) vinculada(s), que se conservan. "
                            ."{$impact['term_relations']} relación(es) término↔CPV, {$impact['service_relations']} relación(es) de servicios "
                            ."y {$impact['crawl_matches']} coincidencia(s) de rastreo pasan al código nuevo.";
                    }),
                Forms\Components\Textarea::make('reason')
                    ->label('Motivo del cambio')
                    ->required()
                    ->minLength(10)
                    ->rows(3)
                    ->helperText('Por qué se cambia. Queda en la auditoría junto con el código anterior y el nuevo.'),
            ])
            ->action(function (array $data, Actions\Action $action) {
                try {
                    $result = app(CategoryCodeChanger::class)->change(
                        Auth::user(),
                        $this->getRecord(),
                        (int) $data['parent_id'],
                        (string) $data['tipo_oferta'],
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
                    ->body("{$result['old_code']} → {$result['new_code']}. Referencias actualizadas: {$result['term_relations']} relación(es) término↔CPV, {$result['service_relations']} de servicios, {$result['crawl_matches']} coincidencia(s) de rastreo. Quedó en la auditoría.")
                    ->persistent()
                    ->send();

                $this->redirect(TaxonomyCategoryResource::getUrl('edit', ['record' => $this->getRecord()]));
            });
    }

    private function refreshSuggestedCode(Forms\Set $set, Forms\Get $get): void
    {
        if ($get('modo') !== self::MODE_AUTO) {
            return;
        }

        $set('code', TaxonomyCategoryResource::buildNextCode($get('parent_id') ? (int) $get('parent_id') : null, $get('tipo_oferta')));
    }
}
