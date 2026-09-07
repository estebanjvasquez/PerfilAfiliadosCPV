<?php

namespace App\Filament\Resources;

use App\Models\TaxonomyCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use App\Filament\Resources\TaxonomyCategoryResource\RelationManagers;
use App\Filament\Resources\TaxonomyGroupResource\Pages;

/**
 * Módulo pedido por Lorenzo el 7 sep 2026, junto al acuerdo de los 5 puntos: el Excel de origen
 * SÍ trae "Group Name"/"Family Name" (ya se cargan como traducción `en` en `taxonomy:import`), pero
 * no había forma cómoda de administrarlos/corregirlos aparte — con 3.483 filas en un solo listado
 * (Grupos+Familias+Categorías mezclados) encontrar los 48 Grupos para revisar o corregir su nombre
 * era poco práctico. Este Resource es una vista ESCOPADA de la misma tabla `taxonomy_categories`
 * (level=0) — comparte modelo, traducciones y sinónimos con TaxonomyCategoryResource, no es una
 * tabla nueva. Un Grupo (ej. "05 — Valves") es el nodo raíz del que cuelgan sus Familias
 * (TaxonomyFamilyResource) y, eventualmente, Categorías directas.
 */
class TaxonomyGroupResource extends Resource
{
    protected static ?string $model = TaxonomyCategory::class;

    protected static ?string $slug = 'taxonomy-groups';

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Grupos';

    public static ?string $label = 'Grupo';

    protected static ?string $pluralModelLabel = 'Grupos';

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('level', TaxonomyCategory::LEVEL_GROUP)
            ->with(['translations' => fn ($q) => $q->whereIn('locale', ['es', 'en'])])
            ->withCount('children');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Código (CPV)')
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->helperText('Ej. CPV-05 — sin puntos, sin sufijo G/S (eso es solo de las categorías hoja).'),
                    Forms\Components\TextInput::make('source_version')
                        ->label('Versión de origen')
                        ->disabled()
                        ->dehydrated(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('name_es')
                    ->label('Nombre (ES)')
                    ->getStateUsing(fn (TaxonomyCategory $record) => $record->translations->firstWhere('locale', 'es')?->name)
                    ->placeholder('— sin traducir —')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'translations',
                        fn ($q) => $q->where('locale', 'es')->where('name', 'like', "%{$search}%")
                    )),
                Tables\Columns\TextColumn::make('name_en')
                    ->label('Nombre (EN)')
                    ->getStateUsing(fn (TaxonomyCategory $record) => $record->translations->firstWhere('locale', 'en')?->name)
                    ->placeholder('— sin traducir —'),
                Tables\Columns\TextColumn::make('children_count')->label('Familias/Categorías hijas'),
            ])
            ->defaultSort('code')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (DeleteAction $action, TaxonomyCategory $record) {
                        if ($record->children()->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Este grupo tiene familias o categorías')
                                ->body('Reasigná o borrá primero sus hijos antes de borrar el grupo.')
                                ->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TranslationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyGroups::route('/'),
            'create' => Pages\CreateTaxonomyGroup::route('/create'),
            'edit' => Pages\EditTaxonomyGroup::route('/{record}/edit'),
        ];
    }
}
