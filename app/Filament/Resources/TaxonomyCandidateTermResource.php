<?php

namespace App\Filament\Resources;

use App\Models\TaxonomyCandidateTerm;
use App\Models\TaxonomyCategory;
use App\Models\TaxonomyTerm;
use App\Models\TaxonomyTermAlias;
use App\Models\TaxonomyTermCpvRelation;
use App\Services\TaxonomyAuditLogger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

use App\Filament\Resources\TaxonomyCandidateTermResource\Pages;

/**
 * TAXV2-12 (sección 5 del documento de instrucciones): cola de revisión de los términos que el
 * crawler de fuentes (`taxonomy:crawl-source`) encontró y todavía NO son parte del diccionario real.
 *
 * Workflow: `candidate -> needs_review -> approved -> published` (o `rejected` desde cualquier
 * estado no terminal). Solo "Publicar" (permiso `taxonomy_publish`, motivo obligatorio) crea de
 * verdad una fila en `taxonomy_terms` - y sus relaciones CPV sugeridas nacen en `needs_review`
 * (nunca `approved` automático), así que vuelven a pasar por la cola ya existente de TAXV2-6
 * (`TaxonomyTermCpvRelationResource`) en vez de crear un segundo camino de aprobación.
 *
 * No hay página de creación a propósito: estas filas solo debe originarlas el crawler (mismo
 * criterio que `LegacyServiceResource`, poblado por un import, no por el admin).
 */
class TaxonomyCandidateTermResource extends Resource
{
    protected static ?string $model = TaxonomyCandidateTerm::class;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Candidatos (crawler)';

    public static ?string $label = 'Término candidato';

    protected static ?string $pluralModelLabel = 'Términos candidatos';

    protected static ?int $navigationSort = 13;

    protected static ?string $recordTitleAttribute = 'term';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['source']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Encontrado por el crawler (solo lectura)')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('term')->label('Término crudo')->disabled()->dehydrated(false),
                    Forms\Components\TextInput::make('source_id')->label('Fuente')->disabled()->dehydrated(false),
                    Forms\Components\TextInput::make('source_url')->label('URL de origen')->disabled()->dehydrated(false)->columnSpanFull(),
                    Forms\Components\Textarea::make('context_snippet')->label('Fragmento/definición')->disabled()->dehydrated(false)->rows(3)->columnSpanFull(),
                    Forms\Components\TextInput::make('change_status')->label('Estado de cambio')->disabled()->dehydrated(false),
                    Forms\Components\TextInput::make('confidence')->label('Confianza (auto-match CPV)')->disabled()->dehydrated(false),
                ]),
            Forms\Components\Section::make('Propuesta editable (antes de publicar)')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('suggested_canonical_term')
                        ->label('Término canónico sugerido')
                        ->maxLength(255)
                        ->helperText('Se usa como canonical_term si se publica. Corregir aquí antes de publicar.'),
                    Forms\Components\TagsInput::make('suggested_aliases')->label('Alias sugeridos')->columnSpanFull(),
                    Forms\Components\Textarea::make('review_notes')->label('Notas de revisión')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('term')->label('Término')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('source_id')->label('Fuente')->badge()->sortable(),
                Tables\Columns\TextColumn::make('context_snippet')->label('Fragmento')->limit(60)->toggleable(),
                Tables\Columns\BadgeColumn::make('change_status')
                    ->label('Cambio')
                    ->colors([
                        'success' => TaxonomyCandidateTerm::CHANGE_NEW,
                        'gray' => TaxonomyCandidateTerm::CHANGE_UNCHANGED,
                        'warning' => TaxonomyCandidateTerm::CHANGE_CHANGED,
                        'danger' => TaxonomyCandidateTerm::CHANGE_MISSING,
                    ]),
                Tables\Columns\TextColumn::make('confidence')->label('Confianza')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('suggested_canonical_term')->label('Canónico sugerido')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->colors([
                        'gray' => TaxonomyCandidateTerm::STATUS_CANDIDATE,
                        'warning' => TaxonomyCandidateTerm::STATUS_NEEDS_REVIEW,
                        'success' => [TaxonomyCandidateTerm::STATUS_APPROVED, TaxonomyCandidateTerm::STATUS_PUBLISHED],
                        'danger' => TaxonomyCandidateTerm::STATUS_REJECTED,
                    ]),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Última vez visto')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        TaxonomyCandidateTerm::STATUS_CANDIDATE => 'Candidato',
                        TaxonomyCandidateTerm::STATUS_NEEDS_REVIEW => 'En revisión',
                        TaxonomyCandidateTerm::STATUS_APPROVED => 'Aprobado',
                        TaxonomyCandidateTerm::STATUS_REJECTED => 'Rechazado',
                        TaxonomyCandidateTerm::STATUS_PUBLISHED => 'Publicado',
                    ])
                    ->default(TaxonomyCandidateTerm::STATUS_CANDIDATE),
                Tables\Filters\SelectFilter::make('source_id')
                    ->label('Fuente')
                    ->relationship('source', 'name'),
                Tables\Filters\SelectFilter::make('change_status')
                    ->label('Estado de cambio')
                    ->options([
                        TaxonomyCandidateTerm::CHANGE_NEW => 'Nuevo',
                        TaxonomyCandidateTerm::CHANGE_UNCHANGED => 'Sin cambios',
                        TaxonomyCandidateTerm::CHANGE_CHANGED => 'Cambió',
                        TaxonomyCandidateTerm::CHANGE_MISSING => 'Desapareció',
                    ]),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->actions([
                static::sendToReviewAction(),
                static::approveAction(),
                static::rejectAction(),
                static::publishAction(),
                Tables\Actions\EditAction::make(),
            ]);
    }

    private static function sendToReviewAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('send_to_review')
            ->label('Marcar en revisión')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn (TaxonomyCandidateTerm $record) => $record->status === TaxonomyCandidateTerm::STATUS_CANDIDATE && static::canEditTerms())
            ->action(fn (TaxonomyCandidateTerm $record) => static::transition($record, TaxonomyCandidateTerm::STATUS_NEEDS_REVIEW, null));
    }

    private static function approveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve')
            ->label('Aprobar')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (TaxonomyCandidateTerm $record) => in_array($record->status, [TaxonomyCandidateTerm::STATUS_CANDIDATE, TaxonomyCandidateTerm::STATUS_NEEDS_REVIEW], true) && static::canEditTerms())
            ->requiresConfirmation()
            ->form([Forms\Components\Textarea::make('reason')->label('Motivo')->required()])
            ->action(fn (TaxonomyCandidateTerm $record, array $data) => static::transition($record, TaxonomyCandidateTerm::STATUS_APPROVED, $data['reason']));
    }

    private static function rejectAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reject')
            ->label('Rechazar')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (TaxonomyCandidateTerm $record) => ! in_array($record->status, [TaxonomyCandidateTerm::STATUS_REJECTED, TaxonomyCandidateTerm::STATUS_PUBLISHED], true) && static::canEditTerms())
            ->requiresConfirmation()
            ->form([Forms\Components\Textarea::make('reason')->label('Motivo')->required()])
            ->action(fn (TaxonomyCandidateTerm $record, array $data) => static::transition($record, TaxonomyCandidateTerm::STATUS_REJECTED, $data['reason']));
    }

    private static function publishAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('publish')
            ->label('Publicar')
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->visible(fn (TaxonomyCandidateTerm $record) => $record->status === TaxonomyCandidateTerm::STATUS_APPROVED && static::canPublish())
            ->requiresConfirmation()
            ->form([
                Forms\Components\TextInput::make('canonical_term')
                    ->label('Término canónico')
                    ->required()
                    ->default(fn (TaxonomyCandidateTerm $record) => $record->suggested_canonical_term ?: $record->term),
                Forms\Components\Select::make('term_type')
                    ->label('Tipo')
                    ->options([
                        TaxonomyTerm::TERM_TYPE_TECHNICAL => 'Término técnico',
                        TaxonomyTerm::TERM_TYPE_COMMERCIAL_PHRASE => 'Frase comercial',
                        TaxonomyTerm::TERM_TYPE_ACRONYM => 'Acrónimo',
                        TaxonomyTerm::TERM_TYPE_OILFIELD_SLANG => 'Jerga de campo',
                        TaxonomyTerm::TERM_TYPE_REGIONAL_SLANG => 'Jerga regional',
                    ])
                    ->default(TaxonomyTerm::TERM_TYPE_TECHNICAL)
                    ->native(false)
                    ->required(),
                Forms\Components\Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->helperText('Por qué se publica este término al diccionario real - queda registrado en la auditoría.'),
            ])
            ->action(function (TaxonomyCandidateTerm $record, array $data) {
                static::publish($record, $data['canonical_term'], $data['term_type'], $data['reason']);
            });
    }

    private static function transition(TaxonomyCandidateTerm $record, string $status, ?string $reason): void
    {
        $oldStatus = $record->status;

        $record->update([
            'status' => $status,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        TaxonomyAuditLogger::record(
            entityType: TaxonomyCandidateTerm::class,
            entityId: $record->getKey(),
            field: 'status',
            oldValue: $oldStatus,
            newValue: $status,
            reason: $reason,
        );

        Notification::make()->success()->title('Estado actualizado')->send();
    }

    private static function publish(TaxonomyCandidateTerm $record, string $canonicalTerm, string $termType, string $reason): void
    {
        $term = TaxonomyTerm::query()->create([
            'external_id' => 'crawled_'.$record->id,
            'term' => $record->term,
            'language' => $record->language,
            'canonical_term' => $canonicalTerm,
            'term_type' => $termType,
            'relevance_weight' => $record->confidence ?? 0.5,
            'oil_gas_exclusivity' => 0.5,
            'context_required' => false,
            'source_id' => $record->source_id,
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);

        foreach ($record->suggested_aliases as $alias) {
            if (trim((string) $alias) === '') {
                continue;
            }
            TaxonomyTermAlias::query()->create(['term_id' => $term->id, 'alias' => $alias]);
        }

        foreach (($record->suggested_cpv_relations ?? []) as $relation) {
            $cpvCode = $relation['cpv_code'] ?? null;
            if (! $cpvCode) {
                continue;
            }

            TaxonomyTermCpvRelation::query()->create([
                'term_id' => $term->id,
                'cpv_code' => $cpvCode,
                'category_id' => TaxonomyCategory::query()->where('code', $cpvCode)->value('id'),
                'level' => TaxonomyTermCpvRelation::LEVEL_CATEGORY,
                'relation_type' => 'lexical',
                'weight' => $relation['weight'] ?? 0.5,
                'confidence' => $record->confidence ?? 0.5,
                'matched_on' => "Sugerido automáticamente al publicar el candidato #{$record->id}",
                'source' => 'crawler_'.$record->source_id,
                'status' => TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW,
            ]);
        }

        $record->update([
            'status' => TaxonomyCandidateTerm::STATUS_PUBLISHED,
            'published_term_id' => $term->id,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        TaxonomyAuditLogger::record(
            entityType: TaxonomyCandidateTerm::class,
            entityId: $record->getKey(),
            field: 'status',
            oldValue: TaxonomyCandidateTerm::STATUS_APPROVED,
            newValue: TaxonomyCandidateTerm::STATUS_PUBLISHED,
            reason: $reason,
        );

        Notification::make()->success()->title("Término publicado (#{$term->id}) - sus relaciones CPV quedaron en revisión")->send();
    }

    /** TAXV2-10: permiso `taxonomy_edit_terms` de la sección 13. */
    private static function canEditTerms(): bool
    {
        return Auth::user()?->can('taxonomy_edit_terms') ?? false;
    }

    /** TAXV2-10: permiso `taxonomy_publish` - publicar un candidato crea un término real en producción. */
    private static function canPublish(): bool
    {
        return Auth::user()?->can('taxonomy_publish') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyCandidateTerms::route('/'),
            'edit' => Pages\EditTaxonomyCandidateTerm::route('/{record}/edit'),
        ];
    }
}
