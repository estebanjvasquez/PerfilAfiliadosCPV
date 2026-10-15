<?php

namespace App\Filament\Resources;

use App\Exceptions\CategoryRequestNoticeException;
use App\Filament\Pages\CategoryRequestSettingsPage;
use App\Filament\Resources\TaxonomyCategoryRequestResource\Pages;
use App\Models\TaxonomyCategoryRequest;
use App\Models\TaxonomyCategoryRequestDeliveryAttempt;
use App\Services\TaxonomyCategoryRequestService;
use Filament\Actions\Action as PageAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * TASK-0010C (Issue #2 comentario `6187768951`): "Solicitudes de categoría".
 *
 * Hasta ahora una solicitud de revisión de categoría quedaba en la base y en un correo, y en ningún
 * otro lado: el propietario envió la #1 en staging y como Super Admin no tuvo dónde verla. Esto la
 * hace visible, con su contenido completo, su estado de aviso y su historial de intentos.
 *
 * SOLO LECTURA, salvo "Reintentar aviso". No hay crear, editar ni borrar (ver la policy), y nada aquí
 * publica, crea ni vincula categorías: revisar la solicitud sigue siendo un trabajo manual de la
 * Cámara.
 *
 * Acceso: Super Admin, decidido por TaxonomyCategoryRequestPolicy. Ocultar la navegación no
 * alcanza: la policy protege también las URL directas de la lista y del detalle, y el servicio
 * vuelve a comprobar el rol antes de reintentar.
 */
class TaxonomyCategoryRequestResource extends Resource
{
    protected static ?string $model = TaxonomyCategoryRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Solicitudes de categoría';

    protected static ?string $modelLabel = 'solicitud de categoría';

    protected static ?string $pluralModelLabel = 'Solicitudes de categoría';

    protected static ?string $slug = 'solicitudes-de-categoria';

    protected static ?int $navigationSort = 4;

    public const RETRY_LABEL = 'Reintentar aviso';

    public static function canCreate(): bool
    {
        return false;
    }

    /** Cuántas esperan que alguien mire su aviso (fallido o pendiente). */
    public static function getNavigationBadge(): ?string
    {
        $count = TaxonomyCategoryRequest::query()->whereIn('delivery_status', TaxonomyCategoryRequest::RETRYABLE_DELIVERY)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function deliveryColor(?string $state): string
    {
        return match ($state) {
            TaxonomyCategoryRequest::DELIVERY_SENT => 'success',
            TaxonomyCategoryRequest::DELIVERY_SIMULATED => 'info',
            TaxonomyCategoryRequest::DELIVERY_FAILED => 'danger',
            default => 'warning',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('N.º')->prefix('#')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('submitted_at')->label('Enviada')->dateTime('d/m/Y H:i')->sortable(),
                // Nombre del SNAPSHOT (la empresa tal como estaba al enviar), no de la relación
                // `empresa`: esa relación usa EmpresaPgsql, cuya clase no compila hoy
                // (EmpresaPgsql::taxonomyCategories() es incompatible con Empresa::taxonomyCategories():
                // HasMany) y cargarla es un error fatal. Defecto previo, reportado aparte.
                Tables\Columns\TextColumn::make('context_snapshot.empresa.name')
                    ->label('Empresa')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereRaw(
                        "context_snapshot->'empresa'->>'name' ILIKE ?",
                        ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%']
                    ))
                    ->limit(40)
                    ->wrap(),
                Tables\Columns\TextColumn::make('necesidad')->label('Necesidad')->searchable()->limit(60)->wrap(),
                Tables\Columns\TextColumn::make('justificacion')
                    ->label('Motivo')
                    ->formatStateUsing(fn (?string $state) => TaxonomyCategoryRequest::JUSTIFICACIONES[$state] ?? $state)
                    ->limit(45)
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('request_status')
                    ->label('Revisión')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => TaxonomyCategoryRequest::REQUEST_STATUS_LABELS[$state] ?? $state),
                Tables\Columns\TextColumn::make('delivery_status')
                    ->label('Aviso por correo')
                    ->badge()
                    ->color(fn (?string $state) => self::deliveryColor($state))
                    ->formatStateUsing(fn (?string $state) => TaxonomyCategoryRequest::DELIVERY_LABELS[$state] ?? $state)
                    ->wrap(),
                Tables\Columns\TextColumn::make('delivery_error')
                    ->label('Motivo del fallo')
                    ->formatStateUsing(fn (?string $state) => TaxonomyCategoryRequest::ERROR_LABELS[$state] ?? $state)
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('delivery_attempts_count')
                    ->label('Intentos')
                    ->counts('deliveryAttempts')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('delivery_status')
                    ->label('Aviso por correo')
                    ->options(TaxonomyCategoryRequest::DELIVERY_LABELS),
                Tables\Filters\SelectFilter::make('request_status')
                    ->label('Revisión')
                    ->options(TaxonomyCategoryRequest::REQUEST_STATUS_LABELS),
                Tables\Filters\SelectFilter::make('justificacion')
                    ->label('Motivo')
                    ->options(TaxonomyCategoryRequest::JUSTIFICACIONES),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                self::configureRetry(Tables\Actions\Action::make('reintentarAviso')),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Solicitud')
                ->columns(3)
                ->schema([
                    TextEntry::make('id')->label('N.º')->prefix('#'),
                    TextEntry::make('submitted_at')->label('Enviada')->dateTime('d/m/Y H:i'),
                    TextEntry::make('request_status')->label('Revisión')->badge()
                        ->formatStateUsing(fn (?string $state) => TaxonomyCategoryRequest::REQUEST_STATUS_LABELS[$state] ?? $state),
                    TextEntry::make('justificacion')->label('Motivo')->columnSpanFull()
                        ->formatStateUsing(fn (?string $state) => TaxonomyCategoryRequest::JUSTIFICACIONES[$state] ?? $state),
                    TextEntry::make('necesidad')->label('Qué necesita representar')->columnSpanFull(),
                    TextEntry::make('detalle')->label('Detalle')->columnSpanFull()
                        ->extraAttributes(['style' => 'white-space: pre-line']),
                    TextEntry::make('terminos_probados')->label('Palabras que probó al buscar')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Empresa y solicitante, tal como estaban al enviar')
                ->columns(2)
                ->schema([
                    TextEntry::make('context_snapshot.empresa.name')->label('Empresa')
                        ->url(fn (TaxonomyCategoryRequest $record) => EmpresaResource::getUrl('edit', ['record' => $record->empresa_id])),
                    TextEntry::make('context_snapshot.empresa.rif')->label('RIF')->placeholder('—'),
                    TextEntry::make('context_snapshot.empresa.phone')->label('Teléfono de la empresa')->placeholder('—'),
                    TextEntry::make('context_snapshot.empresa.website')->label('Sitio web')->placeholder('—'),
                    TextEntry::make('context_snapshot.usuario.name')->label('Solicitante')->placeholder('—'),
                    TextEntry::make('context_snapshot.usuario.email')->label('Correo del solicitante')->placeholder('—'),
                    TextEntry::make('context_snapshot.usuario.phone')->label('Teléfono del solicitante')->placeholder('—'),
                ]),

            Section::make('Categorías que la empresa tenía al enviar')
                ->schema([
                    RepeatableEntry::make('context_snapshot.categorias_vinculadas')
                        ->label('')
                        ->columns(3)
                        ->schema([
                            TextEntry::make('breadcrumb')->label('Categoría')->columnSpan(2),
                            TextEntry::make('es_principal')->label('Tipo')
                                ->formatStateUsing(fn ($state) => $state ? 'Principal' : 'Secundaria'),
                        ])
                        ->placeholder('La empresa no tenía categorías vinculadas.'),
                ]),

            Section::make('Aviso a la Cámara')
                ->columns(2)
                ->schema([
                    TextEntry::make('delivery_status')->label('Estado del aviso')->badge()
                        ->color(fn (?string $state) => self::deliveryColor($state))
                        ->formatStateUsing(fn (?string $state) => TaxonomyCategoryRequest::DELIVERY_LABELS[$state] ?? $state),
                    TextEntry::make('delivery_error')->label('Motivo del fallo')->placeholder('—')
                        ->formatStateUsing(fn (?string $state) => TaxonomyCategoryRequest::ERROR_LABELS[$state] ?? $state),
                    TextEntry::make('que_hacer')->label('Qué hacer')->columnSpanFull()
                        ->state(fn (TaxonomyCategoryRequest $record) => self::guidance($record)),
                    TextEntry::make('recipient_email')->label('Destinatario del último intento')->placeholder('—'),
                    TextEntry::make('delivery_transport')->label('Modo de envío del último intento')->placeholder('—'),
                    TextEntry::make('mail_sent_at')->label('Aceptado por el servidor de correo')->dateTime('d/m/Y H:i')->placeholder('—'),
                ]),

            Section::make('Historial de avisos')
                ->schema([
                    RepeatableEntry::make('deliveryAttempts')
                        ->label('')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('attempt_number')->label('Intento')->prefix('#'),
                            TextEntry::make('kind')->label('Tipo')
                                ->formatStateUsing(fn (?string $state) => TaxonomyCategoryRequestDeliveryAttempt::KIND_LABELS[$state] ?? $state),
                            TextEntry::make('started_at')->label('Fecha')->dateTime('d/m/Y H:i'),
                            TextEntry::make('actor.name')->label('Pedido por')->placeholder('—'),
                            TextEntry::make('result')->label('Resultado')->badge()
                                ->color(fn (?string $state) => self::deliveryColor($state))
                                ->formatStateUsing(fn (?string $state) => TaxonomyCategoryRequest::DELIVERY_LABELS[$state] ?? $state),
                            TextEntry::make('error_code')->label('Motivo')->placeholder('—')
                                ->formatStateUsing(fn (?string $state) => TaxonomyCategoryRequest::ERROR_LABELS[$state] ?? $state),
                            TextEntry::make('recipient_email')->label('Destinatario')->placeholder('—'),
                            TextEntry::make('transport')->label('Modo de envío')->placeholder('no registrado'),
                        ])
                        ->placeholder('Sin intentos registrados.'),
                ]),
        ]);
    }

    /** Explicación accionable del estado del aviso, con enlace a la configuración cuando hace falta. */
    public static function guidance(TaxonomyCategoryRequest $record): HtmlString
    {
        $config = '<a class="underline" href="'.e(CategoryRequestSettingsPage::getUrl()).'">Configuración de solicitudes</a>';
        $hayDestinatario = TaxonomyCategoryRequestService::configuredRecipient() !== null;

        $texto = match ($record->delivery_status) {
            TaxonomyCategoryRequest::DELIVERY_SENT => 'El servidor de correo aceptó el aviso. Eso no confirma que haya llegado al buzón. La solicitud se revisa desde esta pantalla.',
            TaxonomyCategoryRequest::DELIVERY_SIMULATED => 'El servidor está en modo simulado: el aviso quedó registrado en el servidor y NO se envió a ningún buzón. La solicitud se revisa desde esta pantalla igualmente.',
            default => $record->delivery_error === TaxonomyCategoryRequest::ERROR_SIN_DESTINATARIO && ! $hayDestinatario
                ? "No hay correo responsable configurado. Configúrelo en {$config} y luego use «".self::RETRY_LABEL."». Configurarlo no reenvía esta solicitud sola."
                : ($hayDestinatario
                    ? 'Puede usar «'.self::RETRY_LABEL.'» para volver a intentar el aviso con el destinatario configurado ahora.'
                    : "No hay correo responsable configurado. Configúrelo en {$config} antes de reintentar."),
        };

        return new HtmlString($texto);
    }

    /**
     * La acción "Reintentar aviso", igual en la tabla y en el detalle. Muestra a quién iría y qué
     * significa el modo de envío ANTES de confirmar, y deja todo el trabajo al servicio, que vuelve
     * a comprobar rol, estado, destinatario y la reserva contra doble envío.
     *
     * @template T of Tables\Actions\Action|PageAction
     *
     * @param  T  $action
     * @return T
     */
    public static function configureRetry(Tables\Actions\Action|PageAction $action): Tables\Actions\Action|PageAction
    {
        return $action
            ->label(self::RETRY_LABEL)
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (TaxonomyCategoryRequest $record) => $record->isRetryable()
                && (Auth::user()?->can('retryNotice', $record) ?? false))
            ->disabled(fn () => TaxonomyCategoryRequestService::configuredRecipient() === null)
            ->tooltip(fn () => TaxonomyCategoryRequestService::configuredRecipient() === null
                ? 'Configure primero el correo responsable en «Configuración de solicitudes».'
                : null)
            ->requiresConfirmation()
            ->modalHeading(fn (TaxonomyCategoryRequest $record) => "Reintentar el aviso de la solicitud #{$record->id}")
            ->modalDescription(fn (TaxonomyCategoryRequest $record) => 'Se enviará el aviso de esta solicitud a: '
                .(TaxonomyCategoryRequestService::configuredRecipient() ?? '(sin destinatario)').'. '
                .TaxonomyCategoryRequestService::transportDescription()
                .' No se crea otra solicitud ni se cambia su contenido.')
            ->modalSubmitActionLabel('Reintentar ahora')
            ->action(function (TaxonomyCategoryRequest $record) {
                try {
                    $attempt = app(TaxonomyCategoryRequestService::class)->retryNotice($record, Auth::user());
                } catch (AuthorizationException) {
                    Notification::make()->danger()->title('No autorizado')->send();

                    return;
                } catch (CategoryRequestNoticeException $e) {
                    Notification::make()->warning()->title('No se reintentó el aviso')->body($e->getMessage())->send();

                    return;
                }

                self::retryNotification($attempt)->send();
            });
    }

    public static function retryNotification(TaxonomyCategoryRequestDeliveryAttempt $attempt): Notification
    {
        return match ($attempt->result) {
            TaxonomyCategoryRequest::DELIVERY_SENT => Notification::make()->success()
                ->title('Aviso aceptado por el servidor de correo')
                ->body('Eso no confirma que haya llegado al buzón del destinatario.'),
            TaxonomyCategoryRequest::DELIVERY_SIMULATED => Notification::make()->info()
                ->title('Aviso simulado: no se envió ningún correo')
                ->body('El servidor está en modo simulado. El intento quedó registrado en el historial.'),
            default => Notification::make()->danger()
                ->title('El aviso volvió a fallar')
                ->body((TaxonomyCategoryRequest::ERROR_LABELS[$attempt->error_code] ?? 'Motivo no registrado.').' El intento quedó registrado en el historial.'),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomyCategoryRequests::route('/'),
            'view' => Pages\ViewTaxonomyCategoryRequest::route('/{record}'),
        ];
    }
}
