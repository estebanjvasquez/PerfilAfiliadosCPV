<?php

namespace App\Filament\Resources;

use App\Models\TaxonomyAuditLog;
use App\Services\TaxonomyAuditLogger;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

use App\Filament\Resources\TaxonomyAuditLogResource\Pages;

/**
 * TAXV2-11 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 10 y el plan
 * de esta fase): versionado simple vía el propio `taxonomy_audit_log` de TAXV2-9, sin snapshots.
 * "Deshacer" reescribe `old_value` sobre la entidad real, usando `entity_type` (un FQCN de modelo)
 * + `entity_id` (la clave primaria real - ver nota en `EditTaxonomySource` sobre por qué
 * `taxonomy_sources` loggea `id`, no `source_id`) para ubicarla vía `Model::find()`, sin necesitar
 * saber de antemano qué tabla es.
 *
 * Solo se puede deshacer la fila MÁS RECIENTE de cada combinación (entity_type, entity_id, field) -
 * deshacer una fila vieja cuando ya hubo cambios posteriores sobre ese mismo campo pisaría ese
 * cambio más nuevo sin que quede claro cuál "gana"; en ese caso hay que deshacer primero el cambio
 * más reciente.
 *
 * Resource de solo lectura salvo la acción "Deshacer" - nadie crea/edita filas de auditoría a mano.
 */
class TaxonomyAuditLogResource extends Resource
{
    protected static ?string $model = TaxonomyAuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Auditoría';

    public static ?string $label = 'Registro de auditoría';

    protected static ?string $pluralModelLabel = 'Auditoría';

    protected static ?int $navigationSort = 12;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('taxonomy_view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Fecha')->dateTime()->sortable(),
                Tables\Columns\BadgeColumn::make('actor_type')
                    ->label('Actor')
                    ->colors(['gray' => 'user', 'info' => 'system'])
                    ->formatStateUsing(fn (string $state) => $state === 'system' ? 'Sistema' : 'Humano'),
                Tables\Columns\TextColumn::make('user.name')->label('Usuario')->default('sistema')->toggleable(),
                Tables\Columns\TextColumn::make('algorithm_version')->label('Algoritmo')->toggleable(isToggledHiddenByDefault: true)->placeholder('—'),
                Tables\Columns\TextColumn::make('entity_type')
                    ->label('Entidad')
                    ->formatStateUsing(fn (string $state) => class_basename($state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('entity_id')->label('ID')->searchable(),
                Tables\Columns\TextColumn::make('field')->label('Campo')->badge(),
                Tables\Columns\TextColumn::make('old_value')->label('Antes')->limit(30)->toggleable(),
                Tables\Columns\TextColumn::make('new_value')->label('Después')->limit(30),
                Tables\Columns\TextColumn::make('reason')->label('Motivo')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\QueryBuilder::make()
                    ->constraints([
                        TextConstraint::make('entity_type')->label('Entidad (nombre completo)'),
                        TextConstraint::make('entity_id')->label('ID de entidad'),
                        SelectConstraint::make('field')
                            ->label('Campo')
                            ->options(fn () => TaxonomyAuditLog::query()->distinct()->pluck('field', 'field')->all()),
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('undo')
                    ->label('Deshacer')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (TaxonomyAuditLog $record) => static::isLatestForKey($record) && (Auth::user()?->can('taxonomy_rollback') ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(fn (TaxonomyAuditLog $record) => "Vuelve \"{$record->field}\" de \"{$record->new_value}\" a \"{$record->old_value}\" en ".class_basename($record->entity_type)." #{$record->entity_id}.")
                    ->form([
                        Textarea::make('reason')
                            ->label('Motivo del revert')
                            ->required(),
                    ])
                    ->action(fn (TaxonomyAuditLog $record, array $data) => static::undo($record, $data['reason'])),
            ]);
    }

    /** Ninguna fila posterior existe para la misma (entity_type, entity_id, field) - ver docblock de la clase. */
    private static function isLatestForKey(TaxonomyAuditLog $record): bool
    {
        return ! TaxonomyAuditLog::query()
            ->where('entity_type', $record->entity_type)
            ->where('entity_id', $record->entity_id)
            ->where('field', $record->field)
            ->where('id', '>', $record->id)
            ->exists();
    }

    private static function undo(TaxonomyAuditLog $record, string $reason): void
    {
        $modelClass = $record->entity_type;

        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            Notification::make()->danger()->title('No se pudo deshacer')->body('Tipo de entidad desconocido.')->send();

            return;
        }

        $target = $modelClass::query()->find($record->entity_id);

        if (! $target) {
            Notification::make()->danger()->title('No se pudo deshacer')->body('La entidad original ya no existe.')->send();

            return;
        }

        $currentValue = $target->{$record->field};
        $target->update([$record->field => $record->old_value]);

        TaxonomyAuditLogger::record(
            entityType: $modelClass,
            entityId: $record->entity_id,
            field: $record->field,
            oldValue: $currentValue,
            newValue: $record->old_value,
            reason: "Deshecho (revierte cambio #{$record->id} del {$record->created_at->format('d/m/Y H:i')}): {$reason}",
        );

        Notification::make()->success()->title('Cambio revertido')->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyAuditLogs::route('/'),
        ];
    }
}
