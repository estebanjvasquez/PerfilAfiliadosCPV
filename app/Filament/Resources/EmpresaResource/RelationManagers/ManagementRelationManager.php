<?php

namespace App\Filament\Resources\EmpresaResource\RelationManagers;

use App\Filament\Support\NoAplicaAction;
use App\Models\EmpresaModuleStatus;
use App\Models\Management;
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Table;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

class ManagementRelationManager extends RelationManager
{
    protected static string $relationship = 'management';

    public static ?string $label = 'Sistemas de Gestión';

    public static ?string $navigationLabel = 'Sistemas de Gestión';

    protected static ?string $pluralModelLabel = 'Sistemas de Gestión';

    protected static function saveFields(RelationManager $livewire, array $data): void
    {
        $management = $livewire->ownerRecord->management()->first();

        if (! $management) {
            $management = $livewire->ownerRecord->management()->create([]);
        }

        $management->update($data);
    }

    protected static function fillSection(RelationManager $livewire, array $fields): array
    {
        $management = $livewire->ownerRecord->management()->first();

        return $management ? Arr::only($management->attributesToArray(), $fields) : [];
    }

    protected static function otrosName(?array $data, string $field): array
    {
        return collect($data)->pluck($field)->filter()->values()->all();
    }

    /**
     * Switch "No Aplica" embebido en el form de una sección individual de Sistemas de
     * Gestión (no confundir con NoAplicaAction, que marca el módulo completo).
     */
    protected static function noAplicaToggle(string $subType): Toggle
    {
        $subTypeLabel = EmpresaModuleStatus::SUB_TYPES[EmpresaModuleStatus::MODULE_GESTION][$subType];

        return Toggle::make('no_aplica')
            ->label('No Aplica')
            ->helperText("Si lo activa, \"{$subTypeLabel}\" contará como completado en el perfil aunque no cargue datos. Si guarda datos, la marca se elimina automáticamente.")
            ->reactive()
            ->default(fn (RelationManager $livewire) => EmpresaModuleStatus::isNoAplica(
                (int) $livewire->ownerRecord->id,
                EmpresaModuleStatus::MODULE_GESTION,
                $subType
            ));
    }

    protected static function saveFieldsWithNoAplica(RelationManager $livewire, string $subType, array $data): void
    {
        EmpresaModuleStatus::setStatus(
            (int) $livewire->ownerRecord->id,
            EmpresaModuleStatus::MODULE_GESTION,
            (bool) ($data['no_aplica'] ?? false),
            $subType
        );

        static::saveFields($livewire, Arr::except($data, ['no_aplica']));
    }

    protected static function summaryColumn(string $name, string $subType, string $label, array $checks, string $otrosField): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make($name)
            ->label($label)
            ->getStateUsing(fn (?Management $record) => $record)
            ->formatStateUsing(function ($state, RelationManager $livewire) use ($subType, $checks, $otrosField) {
                $checked = [];
                foreach ($checks as $field => $checkLabel) {
                    $checked[$checkLabel] = $state ? (bool) $state->{$field} : false;
                }

                $extra = $state ? static::otrosName($state->{$otrosField}, str_replace('_data', '_otros_name', $otrosField)) : [];

                return new HtmlString(
                    view('filament.tables.columns.checklist-summary', [
                        'checks' => $checked,
                        'extra' => $extra,
                        'noAplica' => EmpresaModuleStatus::isNoAplica(
                            (int) $livewire->ownerRecord->id,
                            EmpresaModuleStatus::MODULE_GESTION,
                            $subType
                        ),
                    ])->render()
                );
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                static::summaryColumn('calidad', 'calidad', 'Calidad', [
                    'iso9001' => 'ISO 9001',
                    'iso17025' => 'ISO 17025',
                ], 'quality_data'),
                static::summaryColumn('ambiente', 'ambiente', 'Ambiente', [
                    'iso14001' => 'ISO 14001:2015',
                    'iso50001' => 'ISO 50001:2018',
                ], 'environment_data'),
                static::summaryColumn('credibilidad', 'credibilidad', 'Credibilidad y Transparencia', [
                    'dun' => 'Dun & Bradstreet',
                    'iso37001' => 'ISO 37001:2016',
                ], 'credibility_data'),
                static::summaryColumn('seguridad', 'seguridad', 'Seguridad', [
                    'iso45001' => 'ISO 45001:2018',
                    'ovid' => 'COVID',
                ], 'security_data'),
                static::summaryColumn('proyectos', 'proyectos', 'Gestión de Proyectos', [
                    'pmi' => 'PMI',
                ], 'pmi_data'),
                static::summaryColumn('seguridad_info', 'seguridad_info', 'Seguridad de la Información', [
                    'iso27001' => 'ISO 27001',
                ], 'info_data'),
            ])
            ->headerActions([
                NoAplicaAction::make(EmpresaModuleStatus::MODULE_GESTION),

                Action::make('calidad')
                    ->label('Calidad')
                    ->icon('heroicon-o-badge-check')
                    ->modalHeading('Agregar / Editar: Calidad')
                    ->form([
                        static::noAplicaToggle('calidad'),
                        Group::make([
                            Checkbox::make('iso9001'),
                            Checkbox::make('iso17025'),
                            Toggle::make('quality_otros')->label('Otros (Especifíque)')->reactive(),
                            Repeater::make('quality_data')
                                ->schema([
                                    TextInput::make('quality_otros_name')->label('Calidad: otros')->required()
                                        ->afterStateUpdated(fn ($component, $state, $set) => $set($component, mb_strtoupper($state))),
                                ])
                                ->orderable(false)
                                ->disableLabel()
                                ->hidden(fn (callable $get) => $get('quality_otros') === false),
                        ])->hidden(fn (callable $get) => (bool) $get('no_aplica')),
                    ])
                    ->mountUsing(fn (ComponentContainer $form, RelationManager $livewire) => $form->fill(array_merge(
                        static::fillSection($livewire, ['iso9001', 'iso17025', 'quality_otros', 'quality_data']),
                        ['no_aplica' => EmpresaModuleStatus::isNoAplica((int) $livewire->ownerRecord->id, EmpresaModuleStatus::MODULE_GESTION, 'calidad')]
                    )))
                    ->action(fn (array $data, RelationManager $livewire) => static::saveFieldsWithNoAplica($livewire, 'calidad', $data)),

                Action::make('ambiente')
                    ->label('Ambiente')
                    ->icon('heroicon-o-badge-check')
                    ->modalHeading('Agregar / Editar: Ambiente')
                    ->form([
                        static::noAplicaToggle('ambiente'),
                        Group::make([
                            Checkbox::make('iso14001')->label('ISO 14001:2015 Sistemas de Gestión Ambiental'),
                            Checkbox::make('iso50001')->label('ISO 50001:2018 Sistemas de Gestión de la Energía.'),
                            Toggle::make('environment_otros')->label('Otros (Especifíque)')->reactive(),
                            Repeater::make('environment_data')
                                ->schema([
                                    TextInput::make('environment_otros_name')->label('Ambiente: otros')->required()
                                        ->afterStateUpdated(fn ($component, $state, $set) => $set($component, mb_strtoupper($state))),
                                ])
                                ->orderable(false)
                                ->disableLabel()
                                ->hidden(fn (callable $get) => $get('environment_otros') === false),
                        ])->hidden(fn (callable $get) => (bool) $get('no_aplica')),
                    ])
                    ->mountUsing(fn (ComponentContainer $form, RelationManager $livewire) => $form->fill(array_merge(
                        static::fillSection($livewire, ['iso14001', 'iso50001', 'environment_otros', 'environment_data']),
                        ['no_aplica' => EmpresaModuleStatus::isNoAplica((int) $livewire->ownerRecord->id, EmpresaModuleStatus::MODULE_GESTION, 'ambiente')]
                    )))
                    ->action(fn (array $data, RelationManager $livewire) => static::saveFieldsWithNoAplica($livewire, 'ambiente', $data)),

                Action::make('credibilidad')
                    ->label('Credibilidad y Transparencia')
                    ->icon('heroicon-o-badge-check')
                    ->modalHeading('Agregar / Editar: Credibilidad y Transparencia')
                    ->form([
                        static::noAplicaToggle('credibilidad'),
                        Group::make([
                            Checkbox::make('dun')->label('Dun & Bradstreet.'),
                            Checkbox::make('iso37001')->label('ISO 37001:2016 Sistemas de Gestión Antisoborno.'),
                            Toggle::make('credibility_otros')->label('Otros (Especifíque)')->reactive(),
                            Repeater::make('credibility_data')
                                ->schema([
                                    TextInput::make('credibility_otros_name')->label('Credibilidad: otros')->required()
                                        ->afterStateUpdated(fn ($component, $state, $set) => $set($component, mb_strtoupper($state))),
                                ])
                                ->orderable(false)
                                ->disableLabel()
                                ->hidden(fn (callable $get) => $get('credibility_otros') === false),
                        ])->hidden(fn (callable $get) => (bool) $get('no_aplica')),
                    ])
                    ->mountUsing(fn (ComponentContainer $form, RelationManager $livewire) => $form->fill(array_merge(
                        static::fillSection($livewire, ['dun', 'iso37001', 'credibility_otros', 'credibility_data']),
                        ['no_aplica' => EmpresaModuleStatus::isNoAplica((int) $livewire->ownerRecord->id, EmpresaModuleStatus::MODULE_GESTION, 'credibilidad')]
                    )))
                    ->action(fn (array $data, RelationManager $livewire) => static::saveFieldsWithNoAplica($livewire, 'credibilidad', $data)),

                Action::make('seguridad')
                    ->label('Seguridad')
                    ->icon('heroicon-o-badge-check')
                    ->modalHeading('Agregar / Editar: Seguridad')
                    ->form([
                        static::noAplicaToggle('seguridad'),
                        Group::make([
                            Checkbox::make('iso45001')->label('ISO 45001:2018 Seguridad y Salud en el Trabajo.'),
                            Checkbox::make('ovid')->label('COVID.'),
                            Toggle::make('security_otros')->label('Otros (Especifíque)')->reactive(),
                            Repeater::make('security_data')
                                ->schema([
                                    TextInput::make('security_otros_name')->label('Seguridad: otros')->required()
                                        ->afterStateUpdated(fn ($component, $state, $set) => $set($component, mb_strtoupper($state))),
                                ])
                                ->orderable(false)
                                ->disableLabel()
                                ->hidden(fn (callable $get) => $get('security_otros') === false),
                        ])->hidden(fn (callable $get) => (bool) $get('no_aplica')),
                    ])
                    ->mountUsing(fn (ComponentContainer $form, RelationManager $livewire) => $form->fill(array_merge(
                        static::fillSection($livewire, ['iso45001', 'ovid', 'security_otros', 'security_data']),
                        ['no_aplica' => EmpresaModuleStatus::isNoAplica((int) $livewire->ownerRecord->id, EmpresaModuleStatus::MODULE_GESTION, 'seguridad')]
                    )))
                    ->action(fn (array $data, RelationManager $livewire) => static::saveFieldsWithNoAplica($livewire, 'seguridad', $data)),

                Action::make('proyectos')
                    ->label('Gestión de Proyectos')
                    ->icon('heroicon-o-badge-check')
                    ->modalHeading('Agregar / Editar: Gestión de Proyectos')
                    ->form([
                        static::noAplicaToggle('proyectos'),
                        Group::make([
                            Checkbox::make('pmi')->label('Project Management Professional (PMI).'),
                            Toggle::make('pmi_otros')->label('Otros (Especifíque)')->reactive(),
                            Repeater::make('pmi_data')
                                ->schema([
                                    TextInput::make('pmi_otros_name')->label('PMI: otros')->required()
                                        ->afterStateUpdated(fn ($component, $state, $set) => $set($component, mb_strtoupper($state))),
                                    TextInput::make('pmi_otros_q')->label('PMI: otros')->numeric()->default(0)->required(),
                                ])
                                ->columns(2)
                                ->orderable(false)
                                ->disableLabel()
                                ->hidden(fn (callable $get) => $get('pmi_otros') === false),
                        ])->hidden(fn (callable $get) => (bool) $get('no_aplica')),
                    ])
                    ->mountUsing(fn (ComponentContainer $form, RelationManager $livewire) => $form->fill(array_merge(
                        static::fillSection($livewire, ['pmi', 'pmi_otros', 'pmi_data']),
                        ['no_aplica' => EmpresaModuleStatus::isNoAplica((int) $livewire->ownerRecord->id, EmpresaModuleStatus::MODULE_GESTION, 'proyectos')]
                    )))
                    ->action(fn (array $data, RelationManager $livewire) => static::saveFieldsWithNoAplica($livewire, 'proyectos', $data)),

                Action::make('seguridad_info')
                    ->label('Seguridad de la Información')
                    ->icon('heroicon-o-badge-check')
                    ->modalHeading('Agregar / Editar: Seguridad de la Información')
                    ->form([
                        static::noAplicaToggle('seguridad_info'),
                        Group::make([
                            Checkbox::make('iso27001')->label('Sistemas de Gestión de la Seguridad de la Información.'),
                            Toggle::make('info_otros')->label('Otros (Especifíque)')->reactive(),
                            Repeater::make('info_data')
                                ->schema([
                                    TextInput::make('info_otros_name')->label('Info Seguridad: otros')->required()
                                        ->afterStateUpdated(fn ($component, $state, $set) => $set($component, mb_strtoupper($state))),
                                ])
                                ->orderable(false)
                                ->disableLabel()
                                ->hidden(fn (callable $get) => $get('info_otros') === false),
                        ])->hidden(fn (callable $get) => (bool) $get('no_aplica')),
                    ])
                    ->mountUsing(fn (ComponentContainer $form, RelationManager $livewire) => $form->fill(array_merge(
                        static::fillSection($livewire, ['iso27001', 'info_otros', 'info_data']),
                        ['no_aplica' => EmpresaModuleStatus::isNoAplica((int) $livewire->ownerRecord->id, EmpresaModuleStatus::MODULE_GESTION, 'seguridad_info')]
                    )))
                    ->action(fn (array $data, RelationManager $livewire) => static::saveFieldsWithNoAplica($livewire, 'seguridad_info', $data)),
            ])
            ->actions([])
            ->bulkActions([]);
    }
}
