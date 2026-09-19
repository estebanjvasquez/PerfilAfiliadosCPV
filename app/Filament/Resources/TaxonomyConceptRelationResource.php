<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TaxonomyConceptRelationResource\Pages;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelation;
use App\Models\TaxonomyConceptRelationType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Phase 3 (secciones 5/6/20 del pedido): CRUD administrativo de relaciones semánticas TIPADAS entre
 * `taxonomy_canonical_concepts`. Distinto de `TermsRelationManager` (que vincula TÉRMINO->CONCEPTO,
 * identidad) - acá se vincula CONCEPTO->CONCEPTO (ej. un futuro PART_OF).
 *
 * Esta tabla queda en 0 filas al terminar esta entrega (el Builder no la puebla todavía) - el
 * recurso existe como infraestructura administrable desde ya, sin esperar a `--apply`.
 */
class TaxonomyConceptRelationResource extends Resource
{
    protected static ?string $model = TaxonomyConceptRelation::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Relaciones entre conceptos (Phase 3)';

    public static ?string $label = 'Relación entre conceptos';

    protected static ?string $pluralModelLabel = 'Relaciones entre conceptos';

    protected static ?int $navigationSort = 16;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('source_concept_id')
                ->label('Concepto origen')
                ->options(fn () => TaxonomyCanonicalConcept::query()->orderBy('canonical_name_en')->get()->pluck('display_name', 'id'))
                ->searchable()
                ->required(),
            Forms\Components\Select::make('target_concept_id')
                ->label('Concepto destino')
                ->options(fn () => TaxonomyCanonicalConcept::query()->orderBy('canonical_name_en')->get()->pluck('display_name', 'id'))
                ->searchable()
                ->required()
                ->different('source_concept_id'),
            Forms\Components\Select::make('relation_type')
                ->label('Tipo de relación')
                ->helperText('Vocabulario gobernado - ver "Tipos de relación entre conceptos". No es un mapping_relation_type de CPV (esa es otra dimensión, ver sección 11 del pedido).')
                ->options(fn () => TaxonomyConceptRelationType::activeOptions())
                ->native(false)
                ->required(),
            Forms\Components\TextInput::make('weight')->numeric()->step(0.0001)->minValue(0)->maxValue(1)->default(0.5)->required(),
            Forms\Components\TextInput::make('confidence')->numeric()->step(0.0001)->minValue(0)->maxValue(1)->default(0.5)->required(),
            Forms\Components\Select::make('status')
                ->options([
                    TaxonomyConceptRelation::STATUS_CANDIDATE => 'Candidata',
                    TaxonomyConceptRelation::STATUS_APPROVED => 'Aprobada',
                    TaxonomyConceptRelation::STATUS_REJECTED => 'Rechazada',
                ])
                ->native(false)
                ->required()
                ->default(TaxonomyConceptRelation::STATUS_CANDIDATE),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sourceConcept.display_name')->label('Origen')->searchable(),
                Tables\Columns\TextColumn::make('relation_type')->label('Relación')->badge(),
                Tables\Columns\TextColumn::make('targetConcept.display_name')->label('Destino')->searchable(),
                Tables\Columns\TextColumn::make('weight')->numeric(4)->sortable(),
                Tables\Columns\TextColumn::make('confidence')->numeric(4)->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray' => TaxonomyConceptRelation::STATUS_CANDIDATE,
                        'success' => TaxonomyConceptRelation::STATUS_APPROVED,
                        'danger' => TaxonomyConceptRelation::STATUS_REJECTED,
                    ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('relation_type')->options(fn () => TaxonomyConceptRelationType::activeOptions()),
                Tables\Filters\SelectFilter::make('status')->options([
                    TaxonomyConceptRelation::STATUS_CANDIDATE => 'Candidata',
                    TaxonomyConceptRelation::STATUS_APPROVED => 'Aprobada',
                    TaxonomyConceptRelation::STATUS_REJECTED => 'Rechazada',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyConceptRelations::route('/'),
            'create' => Pages\CreateTaxonomyConceptRelation::route('/create'),
            'edit' => Pages\EditTaxonomyConceptRelation::route('/{record}/edit'),
        ];
    }
}
