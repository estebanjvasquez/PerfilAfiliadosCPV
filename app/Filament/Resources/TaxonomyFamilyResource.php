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
use App\Filament\Resources\TaxonomyFamilyResource\Pages;

/**
 * Módulo pedido por Lorenzo el 7 sep 2026 junto a TaxonomyGroupResource (ver docblock de esa
 * clase para el contexto completo) — vista escopada de `taxonomy_categories` (level=1). Una
 * Familia (ej. "05.01 — API 6A Wellhead Valves") cuelga siempre de un Grupo (`parent_id`, acá
 * llamado "Grupo" en el formulario) y agrupa a su vez las Categorías finales.
 *
 * El selector de Grupo solo ofrece nodos de nivel 0, y TaxonomyCategoryObserver::saving()
 * bloquea guardar si el código de la familia no corresponde numéricamente al del grupo elegido
 * (ej. familia "05.01" no puede colgar de un grupo que no sea "05") — la validación vive ahí (un
 * solo lugar) porque el mismo chequeo también protege a TaxonomyCategoryResource (categoría vs.
 * familia).
 */
class TaxonomyFamilyResource extends Resource
{
    protected static ?string $model = TaxonomyCategory::class;

    protected static ?string $slug = 'taxonomy-families';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Familias';

    public static ?string $label = 'Familia';

    protected static ?string $pluralModelLabel = 'Familias';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('level', TaxonomyCategory::LEVEL_FAMILY)
            ->with([
                'parent' => fn ($q) => $q->with(['translations' => fn ($q2) => $q2->whereIn('locale', ['es', 'en'])]),
                'translations' => fn ($q) => $q->whereIn('locale', ['es', 'en']),
            ])
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
                        ->helperText('Ej. CPV-05.01 — debe empezar con el código del grupo elegido abajo.'),
                    Forms\Components\Select::make('parent_id')
                        ->label('Grupo')
                        ->required()
                        ->relationship(
                            name: 'parent',
                            titleAttribute: 'code',
                            modifyQueryUsing: fn (Builder $query) => $query->where('level', TaxonomyCategory::LEVEL_GROUP)
                        )
                        ->searchable()
                        ->preload()
                        ->getOptionLabelFromRecordUsing(fn (TaxonomyCategory $record) => "{$record->code} — {$record->nameIn('es')}"),
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
                Tables\Columns\TextColumn::make('parent.code')
                    ->label('Grupo')
                    ->formatStateUsing(fn (TaxonomyCategory $record) => $record->parent
                        ? "{$record->parent->code} — {$record->parent->nameIn('es')}"
                        : '—')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'parent',
                        fn ($q) => $q->where('code', 'like', "%{$search}%")
                    )),
                Tables\Columns\TextColumn::make('children_count')->label('Categorías hijas'),
            ])
            ->defaultSort('code')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (DeleteAction $action, TaxonomyCategory $record) {
                        if ($record->children()->count() > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Esta familia tiene categorías')
                                ->body('Reasigná o borrá primero sus categorías antes de borrar la familia.')
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
            'index' => Pages\ListTaxonomyFamilies::route('/'),
            'create' => Pages\CreateTaxonomyFamily::route('/create'),
            'edit' => Pages\EditTaxonomyFamily::route('/{record}/edit'),
        ];
    }
}
