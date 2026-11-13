<?php

namespace App\Filament\Resources\CiraEvalCampaignResource\RelationManagers;

use App\Models\Cira\CiraEvalInvitation;
use App\Models\User;
use App\Services\CiraEvaluation\CiraEvaluationService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0027: evaluators of a campaign. An evaluator is an EXISTING user account invited to this campaign (no new identity
 * system, no global role or permission). Each invitation gets the next pseudonym (E-01, E-02...), which is all the
 * evaluations and the report show. Invitations are revoked, never deleted (their evaluations stay attributable).
 */
class InvitationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invitations';

    protected static ?string $title = 'Evaluadores';

    protected static bool $isLazy = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return CiraEvaluationService::isPanelUser(Auth::user());
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Solo las personas invitadas (con su cuenta de siempre) pueden evaluar en esta campaña. Los informes muestran el seudónimo, no el nombre.')
            ->columns([
                Tables\Columns\TextColumn::make('pseudonym')->label('Seudónimo'),
                Tables\Columns\TextColumn::make('user.name')->label('Usuario')->placeholder('cuenta eliminada'),
                Tables\Columns\TextColumn::make('user.email')->label('Correo')->placeholder('—'),
                Tables\Columns\TextColumn::make('expires_at')->label('Vence')->dateTime('d/m/Y H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('revoked_at')->label('Revocada')->dateTime('d/m/Y H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('evaluations_count')->label('Evaluaciones')->counts('evaluations'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('invite')
                    ->label('Invitar evaluador')
                    ->icon('heroicon-o-user-plus')
                    ->authorize(fn () => CiraEvaluationService::isPanelUser(Auth::user()))
                    ->form([
                        Forms\Components\Select::make('user_id')
                            ->label('Usuario (cuenta existente)')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => mb_strlen(trim($search)) < 3 ? [] : User::query()
                                ->where(fn ($q) => $q->where('name', 'ilike', '%'.trim($search).'%')->orWhere('email', 'ilike', '%'.trim($search).'%'))
                                ->whereNotNull('email_verified_at')
                                ->orderBy('name')->limit(20)->get()
                                ->mapWithKeys(fn (User $u) => [$u->id => $u->name.' <'.$u->email.'>'])->all())
                            ->getOptionLabelUsing(fn ($value) => ($u = User::query()->whereKey($value)->whereNotNull('email_verified_at')->first()) ? $u->name.' <'.$u->email.'>' : null)
                            ->helperText('Solo cuentas con el correo verificado. Escriba al menos 3 letras.'),
                        Forms\Components\DateTimePicker::make('expires_at')->label('Vence (opcional)')->seconds(false)->after('now'),
                    ])
                    ->action(function (array $data): void {
                        abort_unless(CiraEvaluationService::isPanelUser(Auth::user()), 403);
                        $campaign = $this->getOwnerRecord();
                        $user = User::query()->whereKey($data['user_id'])->whereNotNull('email_verified_at')->first();
                        if ($user === null) {
                            Notification::make()->danger()->title('No se puede invitar')->body('Solo se invitan cuentas existentes con el correo verificado.')->send();

                            return;
                        }
                        DB::connection('pgsql')->transaction(function () use ($campaign, $user, $data) {
                            $existing = CiraEvalInvitation::query()->where('campaign_id', $campaign->getKey())->where('user_id', $user->getKey())->lockForUpdate()->first();
                            if ($existing) {
                                // Re-inviting reactivates the same invitation (same pseudonym): one evaluator, one identity.
                                $existing->forceFill(['revoked_at' => null, 'revoked_by' => null, 'expires_at' => $data['expires_at'] ?? null])->save();

                                return;
                            }
                            (new CiraEvalInvitation())->forceFill([
                                'campaign_id' => $campaign->getKey(),
                                'user_id' => $user->getKey(),
                                'pseudonym' => CiraEvaluationService::nextPseudonym($campaign),
                                'invited_by' => Auth::id(),
                                'expires_at' => $data['expires_at'] ?? null,
                            ])->save();
                        });
                        Notification::make()->success()->title('Evaluador invitado')->body('Ya puede entrar con su cuenta y abrir «Evaluación de CIRA».')->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('Revocar')
                    ->color('danger')
                    ->icon('heroicon-o-no-symbol')
                    ->requiresConfirmation()
                    ->modalDescription('La persona deja de poder evaluar de inmediato, incluso con la página abierta. Sus evaluaciones se conservan.')
                    ->visible(fn (CiraEvalInvitation $record) => $record->revoked_at === null)
                    ->authorize(fn () => CiraEvaluationService::isPanelUser(Auth::user()))
                    ->action(function (CiraEvalInvitation $record): void {
                        abort_unless(CiraEvaluationService::isPanelUser(Auth::user()), 403);
                        $record->forceFill(['revoked_at' => now(), 'revoked_by' => Auth::id()])->save();
                    }),
            ])
            ->bulkActions([]);
    }
}
