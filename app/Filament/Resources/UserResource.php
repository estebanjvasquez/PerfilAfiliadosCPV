<?php

namespace App\Filament\Resources;

use Filament\Forms;
use App\Models\User;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Hash;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\UserResource\Pages;
use STS\FilamentImpersonate\Tables\Actions\Impersonate;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    public static function getNavigationLabel(): string
    {
        return trans('filament-user::user.resource.label');
    }

    public static function getPluralLabel(): string
    {
        return trans('filament-user::user.resource.label');
    }

    public static function getLabel(): string
    {
        return trans('filament-user::user.resource.single');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('filament-user.group');
    }

    public function getTitle(): string
    {
        return trans('filament-user::user.resource.title.resource');
    }

    public static function form(Form $form): Form
    {
        $rows = [
            TextInput::make('name')
                ->required()
                ->label(trans('filament-user::user.resource.name')),
            TextInput::make('email')
                ->email()
                ->required()
                ->label(trans('filament-user::user.resource.email')),
            TextInput::make('password')
                ->label(trans('filament-user::user.resource.password'))
                ->password()
                ->maxLength(255)
                // Requerido solo al crear - al editar se puede dejar en blanco para no
                // cambiar el password actual.
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                // Bug real del stub publicado por 3x1io/filament-user (v1.1.11): en vez
                // de esto usaba `User::find($form->getColumns())` para recuperar el
                // password actual al dejar el campo vacio - $form->getColumns()
                // devuelve el numero de columnas del grid del form (un int chico, ej. 1
                // o 2), no un ID de usuario, asi que el find() siempre devolvia null y
                // el password quedaba en null al guardar. La receta documentada de
                // Filament (`dehydrated: false` cuando el campo esta vacio) es mas
                // simple y robusta: si se deja en blanco, 'password' ni siquiera se
                // envia en el array a guardar, así que el update() no toca esa columna.
                ->dehydrated(fn ($state): bool => filled($state)),
        ];

        if (config('filament-user.shield')) {
            $rows[] = Forms\Components\Select::make('roles')
                ->multiple()
                ->relationship('roles', 'name')
                ->label(trans('filament-user::user.resource.roles'));
        }

        $form->schema($rows);

        return $form;
    }

    public static function table(Table $table): Table
    {
        !config('filament-user.impersonate') ?: $table->actions([Impersonate::make('impersonate')]);
        $table
            ->columns([
                TextColumn::make('id')
                    ->sortable()
                    ->label(trans('filament-user::user.resource.id')),
                TextColumn::make('name')
                    ->sortable()
                    ->searchable()
                    ->label(trans('filament-user::user.resource.name')),
                TextColumn::make('email')
                    ->sortable()
                    ->searchable()
                    ->label(trans('filament-user::user.resource.email')),
                IconColumn::make('email_verified_at')
                    ->boolean()
                    ->sortable()
                    ->searchable()
                    ->label(trans('filament-user::user.resource.email_verified_at')),
                TextColumn::make('created_at')
                    ->label(trans('filament-user::user.resource.created_at'))
                    ->dateTime('M j, Y')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(trans('filament-user::user.resource.updated_at'))
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('verified')
                    ->label(trans('filament-user::user.resource.verified'))
                    ->query(fn(Builder $query): Builder => $query->whereNotNull('email_verified_at')),
                Tables\Filters\Filter::make('unverified')
                    ->label(trans('filament-user::user.resource.unverified'))
                    ->query(fn(Builder $query): Builder => $query->whereNull('email_verified_at')),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make()
                ]),
            ]);
        return $table;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
