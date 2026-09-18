<?php

namespace App\Filament\Resources\CompanyCrawlRunResource\RelationManagers;

use App\Models\CompanyTermMatch;
use App\Services\TaxonomyAuditLogger;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * TAXV2-13: evidencia encontrada en la web de la empresa para esta corrida. Confirmar/descartar es
 * la única acción humana disponible acá - no crea ni modifica `empresa_taxonomy_category` (ver
 * docblock de `CompanyCrawlRunResource`).
 */
class TermMatchesRelationManager extends RelationManager
{
    protected static string $relationship = 'termMatches';

    protected static ?string $title = 'Evidencia encontrada';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('canonical_term')
            ->columns([
                Tables\Columns\TextColumn::make('canonical_term')->label('Término')->searchable(),
                Tables\Columns\TextColumn::make('matched_text')->label('Coincidió con'),
                Tables\Columns\TextColumn::make('context')->label('Contexto')->limit(60)->toggleable(),
                Tables\Columns\TextColumn::make('cpv_code')->label('Código CPV')->toggleable(),
                Tables\Columns\TextColumn::make('evidence_score')->label('Evidencia')->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->colors([
                        'warning' => CompanyTermMatch::STATUS_PENDING_REVIEW,
                        'success' => CompanyTermMatch::STATUS_CONFIRMED,
                        'gray' => CompanyTermMatch::STATUS_DISMISSED,
                    ]),
                Tables\Columns\TextColumn::make('page.url')->label('Página')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('crawled_at')->label('Encontrado')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        CompanyTermMatch::STATUS_PENDING_REVIEW => 'Pendiente',
                        CompanyTermMatch::STATUS_CONFIRMED => 'Confirmada',
                        CompanyTermMatch::STATUS_DISMISSED => 'Descartada',
                    ]),
            ])
            ->defaultSort('evidence_score', 'desc')
            ->actions([
                Tables\Actions\Action::make('confirm')
                    ->label('Confirmar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (CompanyTermMatch $record) => $record->status !== CompanyTermMatch::STATUS_CONFIRMED && static::canReviewMatches())
                    ->action(fn (CompanyTermMatch $record) => static::setStatus($record, CompanyTermMatch::STATUS_CONFIRMED)),
                Tables\Actions\Action::make('dismiss')
                    ->label('Descartar')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (CompanyTermMatch $record) => $record->status !== CompanyTermMatch::STATUS_DISMISSED && static::canReviewMatches())
                    ->action(fn (CompanyTermMatch $record) => static::setStatus($record, CompanyTermMatch::STATUS_DISMISSED)),
            ])
            ->headerActions([]);
    }

    /**
     * Nombre distinto de `canEdit()` a propósito: `RelationManager` (clase base de Filament) ya
     * declara un `canEdit()` de instancia para su propio control de permisos - un método `static`
     * con el mismo nombre en esta clase es una redeclaración incompatible (fatal), no una
     * sobreescritura válida.
     */
    private static function canReviewMatches(): bool
    {
        return Auth::user()?->can('taxonomy_edit_terms') ?? false;
    }

    private static function setStatus(CompanyTermMatch $record, string $status): void
    {
        $old = $record->status;
        $record->update(['status' => $status]);

        TaxonomyAuditLogger::record(
            entityType: CompanyTermMatch::class,
            entityId: $record->getKey(),
            field: 'status',
            oldValue: $old,
            newValue: $status,
        );

        Notification::make()->success()->title('Evidencia actualizada')->send();
    }
}
