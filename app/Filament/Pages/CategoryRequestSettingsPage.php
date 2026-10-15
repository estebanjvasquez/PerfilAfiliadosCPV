<?php

namespace App\Filament\Pages;

use App\Filament\Resources\TaxonomyCategoryRequestResource;
use App\Models\TaxonomySelectionSettings;
use App\Services\TaxonomyCategoryRequestService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * TASK-0010C: "Configuración de solicitudes", el ÚNICO lugar donde se edita el correo responsable
 * de las solicitudes de revisión de categoría.
 *
 * El campo vivía en "Límites de selección" (TaxonomySelectionSettingsPage), donde nadie lo buscaba:
 * el propietario no lo encontró al probar. Se movió aquí; la columna sigue siendo la misma
 * (`taxonomy_selection_settings.category_request_recipient_email`) y "Límites de selección" solo
 * enlaza a esta página, para no tener dos campos que se contradigan.
 *
 * Guardar el destinatario NO reenvía las solicitudes ya registradas: eso es un reintento manual,
 * solicitud por solicitud, desde "Solicitudes de categoría".
 */
class CategoryRequestSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Configuración de solicitudes';

    protected static ?string $title = 'Configuración de solicitudes de categoría';

    protected static ?string $slug = 'configuracion-de-solicitudes';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.taxonomy-selection-settings';

    public const RECIPIENT_LABEL = 'Correo responsable de solicitudes de categorías';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(config('filament-shield.super_admin.name')) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(TaxonomySelectionSettings::current()->only(['category_request_recipient_email']));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('category_request_recipient_email')
                    ->label(self::RECIPIENT_LABEL)
                    ->email()
                    ->maxLength(255)
                    ->nullable()
                    ->helperText('Recibe el aviso de cada solicitud de revisión de categoría que envían las empresas. Vacío = las solicitudes se registran igual, pero no se envía ningún aviso. Guardar este campo no reenvía las solicitudes anteriores.'),
                Placeholder::make('modo_envio')
                    ->label('Modo de envío del servidor')
                    ->content(fn () => TaxonomyCategoryRequestService::transportDescription()),
                Placeholder::make('ver_solicitudes')
                    ->label('Solicitudes registradas')
                    ->content(fn () => new \Illuminate\Support\HtmlString(
                        'Las solicitudes y su estado de aviso están en <a class="underline" href="'
                        .e(TaxonomyCategoryRequestResource::getUrl('index'))
                        .'">Solicitudes de categoría</a>.'
                    )),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        TaxonomySelectionSettings::current()->update([
            'category_request_recipient_email' => filled($state['category_request_recipient_email'] ?? null)
                ? $state['category_request_recipient_email']
                : null,
        ]);

        Notification::make()->success()
            ->title('Configuración guardada')
            ->body('Las solicitudes ya registradas no se reenvían solas: use «Reintentar aviso» en cada una si corresponde.')
            ->send();
    }
}
