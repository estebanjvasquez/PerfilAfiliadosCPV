<?php

namespace App\Filament\Resources\EmpresaResource\Pages;

use Clorure;
use Filament\Forms;
use App\Filament\Resources\EmpresaResource;
//use App\Models\City;
use App\Models\Country;
use Closure;
use App\Models\City;
use App\Models\Empresa;
use App\Models\Sector;
//use App\Models\State;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\BelongsToSelect;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditEmpresa extends EditRecord
{
    // CPV-SEC-01-R3 (F-SEC01-R3-01): every request after the page opened re-checks that the user may still edit this company.
    use \App\Filament\Resources\EmpresaResource\Concerns\ReauthorizesEmpresaAccess;

    use EditRecord\Concerns\HasWizard;

    protected static string $resource = EmpresaResource::class;

    /**
     * CPV-COMPLETE-COMPANY-PDF (`6270558322`): el perfil completo también desde la ficha de la empresa. Es el mismo enlace
     * protegido que la acción de la lista. La elección de secciones queda solo en la lista, para no duplicar controles.
     */
    protected function getHeaderActions(): array
    {
        return [
            // TASK-0011 (Bloque 2): guardar desde cualquier paso. Antes «Guardar cambios» solo estaba en el paso 4 y
            // corregir un dato del paso 1 obligaba a recorrer los cuatro. Es el mismo save() del formulario completo.
            \Filament\Actions\Action::make('guardar')
                ->label('Guardar cambios')
                ->icon('heroicon-o-check')
                ->keyBindings(['mod+s'])
                ->action(fn () => $this->save()),
            \Filament\Actions\Action::make('perfil_completo')
                ->label('Descargar perfil completo (PDF)')
                ->icon('heroicon-o-document-arrow-down')
                ->tooltip('Descarga el perfil completo de la empresa en PDF, con todas sus secciones')
                ->url(fn (): string => route('filament.admin.export.pdf', ['empresa' => $this->getRecord()->getKey()]))
                ->visible(fn (): bool => Auth::user()?->can('view_empresa') ?? false),
        ];
    }

    /** TASK-0011: entre el formulario y las secciones, un título que explica que ellas guardan al instante. */
    protected static string $view = 'filament.resources.empresa-resource.pages.edit-empresa';

    public function getSubheading(): ?string
    {
        return 'Los datos de los 4 pasos se guardan con «Guardar cambios», arriba o al final del último paso. '
            .'Puede abrir cualquier paso con su título. Las secciones del perfil, más abajo, guardan cada cambio al instante.';
    }

    /** Pasos navegables: la validación completa ocurre igual al guardar. */
    protected function hasSkippableSteps(): bool
    {
        return true;
    }

    /** Con pasos navegables, el error puede estar en un paso que no se ve: se dice cuál. */
    protected function onValidationError(ValidationException $exception): void
    {
        $wizard = collect($this->form->getComponents())->first(fn ($component) => $component instanceof Forms\Components\Wizard);
        $errorKeys = array_keys($exception->errors());
        $steps = [];
        foreach ($wizard?->getChildComponentContainer()->getComponents() ?? [] as $step) {
            foreach ($step->getChildComponentContainer()->getFlatFields(withHidden: true) as $field) {
                $path = $field->getStatePath();
                if (collect($errorKeys)->contains(fn (string $key) => $key === $path || str_starts_with($key, $path.'.'))) {
                    $steps[] = '«'.$step->getLabel().'»';
                    break;
                }
            }
        }

        Notification::make()
            ->danger()
            ->title('No se guardó: hay datos por corregir')
            ->body(($steps !== [] ? 'Revise el paso '.implode(', ', $steps).'. ' : '').'Los campos con error aparecen en rojo.')
            ->send();
    }

    /** Aviso para después de guardar (empresa con servicios viejos fuera de sus sectores), armado en beforeSave(). */
    protected ?string $sectorNotice = null;

    /** TASK-0011: «www.x.com» → «https://www.x.com»; vacío → null. No corrige letras: solo agrega el esquema. */
    public static function normalizeWebsite(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) ? $value : 'https://'.$value;
    }

    /** http(s), con un dominio que tenga al menos un punto (descarta `http://wwwgeohidracom`). */
    public static function isValidWebsite(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && is_string($host) && preg_match('/^[^.]+(\.[^.]+)+$/', $host) === 1;
    }

    /** El sector principal lo define la Cámara: solo quien administra (super_admin) lo elige; para el resto no se envía. */
    public static function definesSectors(): bool
    {
        return Auth::user()?->hasRole(config('filament-shield.super_admin.name')) ?? false;
    }

    /**
     * Regla de 2 sectores (cbc1e37) con TASK-0011 (R3). Recibe los sectores de los servicios viejos de la empresa y los
     * sectores permitidos antes y después del guardado. `blocked`: sectores cuyos servicios este cambio dejaría fuera y
     * hoy están dentro (el guardado empeora la empresa: se detiene). `outside`: los que quedan fuera después (incluye una
     * inconsistencia previa, que no impide guardar otros datos; se avisa).
     *
     * @param  int[]  $serviceSectors
     * @param  int[]  $current
     * @param  int[]  $next
     * @return array{blocked: int[], outside: int[]}
     */
    public static function sectorCoherence(array $serviceSectors, array $current, array $next): array
    {
        $outsideNow = array_diff($serviceSectors, $current);
        $outside = array_values(array_diff($serviceSectors, $next));

        return ['blocked' => array_values(array_diff($outside, $outsideNow)), 'outside' => $outside];
    }

    /**
     * Las empresas solo pueden operar en 2 sectores (principal y secundario). Antes (D2/D3 de TASK-0011) esto bloqueaba
     * cualquier guardado con servicios viejos fuera de los sectores, sin forma de desvincularlos, y además leía el sector
     * principal del formulario, donde no viene cuando el campo está deshabilitado (Filament no deshidrata un campo
     * disabled). Ahora el principal que no se envía es el guardado, y solo se detiene un cambio de sectores que empeora.
     */
    protected function beforeSave(): void
    {
        $data = $this->form->getState();
        $record = $this->record;

        $principal = array_key_exists('sector_principal_id', $data) ? $data['sector_principal_id'] : $record->sector_principal_id;
        $next = array_map('intval', array_values(array_filter([
            $principal,
            array_key_exists('sector_secundario_id', $data) ? $data['sector_secundario_id'] : $record->sector_secundario_id,
        ])));

        $check = static::sectorCoherence($record->distinctSectorIds(), $record->allowedSectorIds(), $next);

        if ($check['blocked'] !== []) {
            $names = Sector::whereIn('id', $check['blocked'])->pluck('name')->implode(', ');

            Notification::make()
                ->danger()
                ->title('Este cambio de sectores deja servicios fuera')
                ->body("Su empresa tiene servicios de: {$names}. Solo se permiten un sector principal y uno secundario. "
                    .'Primero desvincule esos servicios en la sección «Sectores y servicios (histórico)», más abajo, y luego cambie el sector; '
                    .'o conserve el sector actual. No se guardó ningún cambio.')
                ->persistent()
                ->send();

            $this->halt();
        }

        $this->sectorNotice = null;
        if ($check['outside'] !== []) {
            $names = Sector::whereIn('id', $check['outside'])->pluck('name')->implode(', ');
            $this->sectorNotice = blank($principal)
                ? "La Cámara aún no definió el sector principal de su empresa, y tiene servicios anteriores de: {$names}. "
                    .'Puede seguir actualizando su perfil. Para definir el sector, contacte a la CPV; los servicios que no correspondan se quitan con «Desvincular» en «Sectores y servicios (histórico)».'
                : "Su empresa tiene servicios anteriores fuera de sus sectores: {$names}. "
                    .'Quítelos con «Desvincular» en la sección «Sectores y servicios (histórico)», o pida a la CPV revisar sus sectores.';
        }
    }

    protected function afterSave(): void
    {
        if ($this->sectorNotice !== null) {
            Notification::make()
                ->warning()
                ->title('Cambios guardados, con un pendiente')
                ->body($this->sectorNotice)
                ->persistent()
                ->send();
        }
    }

    /**
     * Pedido del cliente (9 sep 2026): el RIF solo lo puede modificar un Super Admin - el resto
     * de los usuarios lo ve en greyout, sin poder tocarlo. `disabled()` ya deja el campo
     * visualmente greyed-out y evita que se guarde su valor (dehydrated(false) automático, ver
     * Filament\Forms\Components\Concerns\CanBeDisabled) — pero eso NO alcanza solo, porque
     * Filament sigue corriendo la validación (required/regex/unique) de un campo disabled
     * mientras siga VISIBLE (`isHiddenAndNotDehydrated()` en
     * vendor/filament/forms/src/Concerns/CanBeValidated.php solo salta componentes ocultos, no
     * disabled). Sin este chequeo extra, cualquier usuario no-Super-Admin quedaría bloqueado
     * para guardar CUALQUIER cambio de la empresa si su RIF actual no matcheara el regex nuevo
     * (ej. datos históricos con formato distinto) — por eso unique()/maxLength()/regex() solo se
     * agregan cuando sí es Super Admin, en vez de dejarlos siempre puestos.
     */
    protected function rifField(): Forms\Components\TextInput
    {
        $isSuperAdmin = (bool) auth()->user()?->hasRole('super_admin');

        $field = Forms\Components\TextInput::make('rif')
            ->required($isSuperAdmin)
            ->disabled(! $isSuperAdmin)
            ->placeholder('X123456789')
            ->helperText(
                'Formato: letra (V/E/J/P/G) seguida de 9 dígitos, sin guiones ni espacios.'
                .($isSuperAdmin ? '' : ' Solo un Super Admin puede modificar el RIF.')
            )
            ->afterStateUpdated(function ($component, $state, $set) {
                return $set($component, mb_strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $state)));
            });

        if ($isSuperAdmin) {
            $field->unique(ignoreRecord: true)->maxLength(10)->regex('/^[VEJPG]\d{9}$/i');
        }

        return $field;
    }

    public function getSteps(): array
    {
        $ciudades = City::get();
        foreach ($ciudades as $key => $value) {
            //
        }

        return [

            Forms\Components\Wizard\Step::make('1- Datos generales')
                ->schema([
                    Forms\Components\Placeholder::make('completitud')
                        ->label('Completado del perfil')
                        ->content(function ($record) {
                            if (!$record) {
                                return '';
                            }

                            $data = $record->completionData();
                            $pendientes = collect($data)->filter(fn ($completo) => !$completo)->keys()->implode(', ');

                            return $record->completionPercentage() . '% completado'
                                . ($pendientes !== '' ? ' — Pendiente: ' . $pendientes : '');
                        })
                        // Bug real (confirmado leyendo vendor/filament/support/.../grid/column.blade.php):
                        // columnSpan(3) sin array fija 'default' => 3 (ver CanSpanColumns::columnSpan()),
                        // o sea que este Placeholder pide 3 columnas de ancho INCLUSO en mobile, donde el
                        // grid del Step solo tiene 1 columna explícita (columns(['default' => 1, 'md' => 3])
                        // de mas abajo). CSS Grid resuelve ese desborde creando columnas implícitas extra
                        // para acomodar el span - y esas columnas de más las heredan también los siguientes
                        // hermanos del grid (Rif/Nombre/Año), que terminan apretados 3-en-fila en vez de
                        // apilados (visto en vivo en la captura de mobile: fila angosta con Rif/Nombre/Año).
                        // Fix: el span de 3 solo tiene sentido desde md en adelante (donde el grid sí abre a
                        // 3 columnas); en mobile debe pedir 1 sola, igual que el resto de los campos.
                        ->columnSpan(['default' => 1, 'md' => 3]),
                    $this->rifField(),
                    Forms\Components\TextInput::make('name')->label(__('Nombre de la empresa'))->required()
                        ->afterStateUpdated(function ($component, $state, $set) {
                            return $set($component, mb_strtoupper($state));
                        }),
                    Forms\Components\TextInput::make('ano_fund')->numeric()
                        ->minValue(1901)->maxValue(2150)
                        ->label('Año de fundación')
                        ->placeholder('aaaa'),
                    //Forms\Components\TextInput::make('ano_fund')->placeholder('AAAA'),

                    Fieldset::make('Dirección')->schema([
                        Forms\Components\TextInput::make('phone')->tel()->label('Teléfono')
                            ->mask('+58-999-9999999')
                            ->placeholder('+58-XXX-XXXXXXX'),
                        Forms\Components\TextInput::make('street')->maxLength(100)->label('Dirección')
                            ->afterStateUpdated(function ($component, $state, $set) {
                                return $set($component, mb_strtoupper($state));
                            }),


                        Select::make('city_id')
                            ->label('Ciudad')
                            ->options($value->getFullCity()->pluck('full_location', 'id'))
                            ->searchable()



                    ])->columns(['default' => 1, 'md' => 3]),
                ])->columns(['default' => 1, 'md' => 3])->id('uno'),
            Forms\Components\Wizard\Step::make('2 - Datos de contacto')
                ->schema([
                    Fieldset::make('Redes sociales')->schema([
                        // TASK-0011: sin la máscara `http://***…` (forzaba http:// y cortaba en 32 caracteres; en datos
                        // quedaron valores como `http://wwwgeohidracom`). Se normaliza al guardar y se valida solo si
                        // el valor cambia: una web vieja mal escrita no impide guardar otros datos.
                        Forms\Components\TextInput::make('website')
                            ->label('Página web')
                            ->placeholder('https://www.suempresa.com')
                            ->maxLength(255)
                            ->helperText('Escriba la dirección completa, por ejemplo www.suempresa.com; el sistema agrega https:// si falta.')
                            ->rule(fn (?Empresa $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                $normalized = static::normalizeWebsite($value);
                                if ($normalized === null || $normalized === static::normalizeWebsite($record?->website)) {
                                    return;
                                }
                                if (! static::isValidWebsite($normalized)) {
                                    $fail('Escriba una dirección web válida, por ejemplo https://www.suempresa.com.');
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state, ?Empresa $record): ?string => static::normalizeWebsite($state) === static::normalizeWebsite($record?->website)
                                ? $record?->website
                                : static::normalizeWebsite($state)),
                        Forms\Components\TextInput::make('linkedin_profile')->mask('@********************')
                            ->label('Perfil de LinkedIn'),
                        Forms\Components\TextInput::make('twitter_profile')->mask('@********************')
                            ->label('Perfil de Twitter'),
                        Forms\Components\TextInput::make('instagram_profile')->mask('@********************')
                            ->label('Perfil de Instagram'),
                        Forms\Components\TextInput::make('facebook_profile')->mask('@********************')
                            ->label('Perfil de Facebook'),
                        Forms\Components\TextInput::make('youtube_profile')->mask('@********************')
                            ->label('Youtube'),

                        Forms\Components\TextInput::make('otros_profile')->label('Otros'),
                    ])->columns(['default' => 1, 'md' => 3])->id('dos'),
                    //Fieldset::make('Persona Contacto')->schema([]),
                ]),
            Forms\Components\Wizard\Step::make('3 - Operaciones')
                ->schema([
                    Fieldset::make('Sectores de actividad económica (máximo 2)')->schema([
                        Select::make('sector_principal_id')
                            ->label('Sector principal')
                            ->options(Sector::orderBy('name')->pluck('name', 'id'))
                            ->placeholder(fn (): string => static::definesSectors() ? 'Por favor seleccione una opción' : 'Sin definir por la Cámara')
                            ->reactive()
                            // TASK-0011 (D4): obligatorio solo para quien lo puede elegir. Un campo deshabilitado
                            // y obligatorio bloqueaba «Siguiente» en las empresas sin sector (p. ej. id 72).
                            ->required(fn (): bool => static::definesSectors())
                            ->disabled(fn (): bool => ! static::definesSectors())
                            ->helperText(fn (): ?string => static::definesSectors()
                                ? null
                                : 'Este dato lo define la Cámara Petrolera al registrar tu empresa. Si no estás de acuerdo, contacta a la CPV para solicitar el cambio.'),
                        Select::make('sector_secundario_id')
                            ->label('Sector secundario (opcional)')
                            ->options(fn (callable $get) => Sector::orderBy('name')
                                ->where('id', '<>', $get('sector_principal_id'))
                                ->pluck('name', 'id'))
                            ->placeholder('Por favor seleccione una opción')
                            ->different('sector_principal_id')
                            ->helperText('Solo podrá asociar servicios de los sectores aquí seleccionados.'),
                    ])->columns(['default' => 1, 'md' => 2]),
                    Fieldset::make('Operaciones en Venezuela')->schema([
                        Forms\Components\Select::make('billing_id')
                            ->options([
                                '1' => '< 100.000 USD',
                                '2' => '100.001 - 1.000.000 USD',
                                '3' => '1.000.001 - 10.000.000 USD',
                                '4' => '> 10.000.001 USD'
                            ])->label('Facturación anual promedio (últimos 5 años)')
                            ->placeholder('Por favor seleccione una opción'),
                        Forms\Components\Select::make('employees_id')
                            ->options([
                                '1' => '< 50',
                                '2' => '51 - 100',
                                '3' => '101 - 500',
                                '4' => '> 500'
                            ])->label('Empleados')->placeholder('Por favor seleccione una opción'),

                        Forms\Components\Select::make('status_id')
                            ->options([
                                '1' => 'Activa',
                                '0' => 'Inactiva',
                            ])->label('Estatus actual')
                            ->default('1')
                            ->required(),
                    ])->columns(['default' => 1, 'md' => 3]),
                    Fieldset::make('Capital de la empresa')->schema([
                        Forms\Components\Select::make('property_id')
                            ->options([
                                '1' => 'Privado',
                                '0' => 'Público',
                            ])->label('Propiedad')->placeholder('Por favor seleccione una opción'),

                        Forms\Components\Select::make('origin_id')
                            ->options([
                                '1' => 'Nacional',
                                '0' => 'Internacional',
                            ])->label('Origen')->placeholder('Por favor seleccione una opción'),
                    ]),
                ]),
            Forms\Components\Wizard\Step::make('4 - Clientes')
                ->schema([

                    Repeater::make('customers_country')
                        ->schema([
                            Forms\Components\TextInput::make('customer_name')->label('Nombre del cliente')
                                ->afterStateUpdated(function ($component, $state, $set) {
                                    return $set($component, mb_strtoupper($state));
                                }),
                            Select::make('country_id')->relationship('country', 'country_name')->label('País'),
                            //Select::make('city_id')->relationship('city', 'city_name')->required(),
                        ])->label('Principales clientes en los últimos 15 años:')
                        ->createItemButtonLabel('Añadir cliente')
                        ->columns(['default' => 1, 'md' => 2])
                        ->orderable(false)
                    //->defaultItems(3)
                ])->columns(1),
        ];
    }
}
